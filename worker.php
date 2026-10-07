<?php
// CLI-only: startar yt-dlp via popen() och streamar progress till .<jobId>.progress.
// Används via "nohup php worker.php <job_id> <url>" från download.php.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('YTDLP_PATH',    '/volume1/@yt-dlp/yt-dlp');
define('FFMPEG_PATH',   '/var/packages/ffmpeg7/target/bin/ffmpeg');
define('DOWNLOADS_DIR', __DIR__ . '/downloads');
define('WRITE_THROTTLE_SECONDS', 2);

$jobId = $argv[1] ?? '';
$url   = $argv[2] ?? '';

if (!preg_match('/^[a-f0-9]+$/', $jobId) || $url === '') {
    fwrite(STDERR, "usage: worker.php <job_id> <url>\n");
    exit(1);
}

$mp4File  = DOWNLOADS_DIR . '/' . $jobId . '.mp4';
$m4aFile  = DOWNLOADS_DIR . '/' . $jobId . '.m4a';
$logFile  = DOWNLOADS_DIR . '/.' . $jobId . '.log';
$errFile  = DOWNLOADS_DIR . '/.' . $jobId . '.err';
$doneFile = DOWNLOADS_DIR . '/.' . $jobId . '.done';
$progFile = DOWNLOADS_DIR . '/.' . $jobId . '.progress';

$lastPercent = 0;
$lastWrite   = 0;

// Atomisk skrivning — frontend ska aldrig se en halvskriven JSON
function write_progress(string $file, array $state): void {
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($state));
    @rename($tmp, $file);
}

// Rensa alla temporära filer för ett jobb-ID (partial mp4, .part, osv).
// Kallas vid fel så vi inte lämnar GB av skräp till nästa cleanup-runda.
function cleanup_partials(string $downloadsDir, string $jobId): void {
    foreach (glob("$downloadsDir/$jobId.*") as $f) {
        if (is_file($f)) @unlink($f);
    }
}

function error_exit(string $errFile, string $progFile, string $msg, $log = null): never {
    file_put_contents($errFile, $msg);
    @unlink($progFile);
    if ($log) fclose($log);
    exit(1);
}

$log = fopen($logFile, 'a');
if ($log === false) {
    // Oftast permissions på downloads/ — misslyckas tydligt istället för att fwrite
    // tyst triggar warnings och loggen försvinner.
    error_exit($errFile, $progFile, "Kunde inte öppna loggfil: $logFile");
}

// ── Steg 1: yt-dlp laddar ner video ──────────
write_progress($progFile, ['phase' => 'download', 'percent' => 0, 'updated_at' => time()]);

// --newline tvingar yt-dlp att avsluta progress-rader med \n istället för \r,
// så fgets() kan läsa dem direkt utan buffring.
$dlCmd = implode(' ', [
    escapeshellarg(YTDLP_PATH),
    '--downloader', 'native',
    '-N', '4',
    '--newline',
    '--no-playlist',
    '-f', 'ba/b',
    '--referer', 'https://www.100.se/',
    '--restrict-filenames',
    '--ffmpeg-location', escapeshellarg(FFMPEG_PATH),
    '-o', escapeshellarg($mp4File),
    escapeshellarg($url),
    '2>&1',
]);

$fp = popen($dlCmd, 'r');
if (!$fp) {
    error_exit($errFile, $progFile, 'Kunde inte starta yt-dlp.', $log);
}

$lastError = '';
while (($line = fgets($fp)) !== false) {
    $line = rtrim($line);
    fwrite($log, $line . "\n");
    fflush($log);

    // [download]  12.3% of ~123.45MiB at 1.23MiB/s ETA 00:45
    if (preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%/', $line, $m)) {
        $pct = (int) round((float) $m[1]);
        $now = time();
        if ($pct !== $lastPercent && ($now - $lastWrite) >= WRITE_THROTTLE_SECONDS) {
            write_progress($progFile, [
                'phase'      => 'download',
                'percent'    => $pct,
                'updated_at' => $now,
            ]);
            $lastWrite   = $now;
            $lastPercent = $pct;
        }
    }

    // Fånga senaste ERROR-rad för felmeddelande till frontend
    if (preg_match('/^ERROR:\s*(.+)$/', $line, $m)) {
        $lastError = trim($m[1]);
    }
}

$status = pclose($fp);
if ($status !== 0) {
    cleanup_partials(DOWNLOADS_DIR, $jobId);
    error_exit($errFile, $progFile, $lastError ?: 'Nedladdning misslyckades.', $log);
}
if (!file_exists($mp4File)) {
    cleanup_partials(DOWNLOADS_DIR, $jobId);
    error_exit($errFile, $progFile, 'Ingen videofil producerades.', $log);
}

// ── Steg 2: ffmpeg kopierar ljudspåret ───────
// Ingen procent-parsning här — ffmpeg -acodec copy är nästan momentant på NAS
// eftersom det inte omkodas. Frontend visar indeterminate bar under konverteringen.
write_progress($progFile, [
    'phase'      => 'convert',
    'percent'    => $lastPercent,
    'updated_at' => time(),
]);

// Kör ffmpeg med givna ljudargument. Returnerar [lyckades, sista felraden].
function run_ffmpeg(string $mp4File, string $m4aFile, array $audioArgs, $log): array {
    $cmd = implode(' ', array_merge([
        escapeshellarg(FFMPEG_PATH),
        '-y',
        '-i', escapeshellarg($mp4File),
        '-vn',
        '-map', '0:a:0',
    ], $audioArgs, [
        escapeshellarg($m4aFile),
        '2>&1',
    ]));
    fwrite($log, "\n$ " . $cmd . "\n");
    $fp = popen($cmd, 'r');
    if (!$fp) return [false, 'Kunde inte starta ffmpeg.'];
    $last = '';
    while (($line = fgets($fp)) !== false) {
        $line = rtrim($line);
        fwrite($log, $line . "\n");
        fflush($log);
        if ($line !== '') $last = $line;
    }
    $status = pclose($fp);
    $ok = $status === 0 && file_exists($m4aFile) && filesize($m4aFile) > 0;
    return [$ok, $last];
}

// Första försöket: kopiera ljudspåret utan omkodning. Misslyckas det (t.ex. om
// källan inte är AAC) faller vi tillbaka på omkodning till AAC.
[$ok, $ffErr] = run_ffmpeg($mp4File, $m4aFile, ['-c:a', 'copy', '-bsf:a', 'aac_adtstoasc'], $log);
if (!$ok) {
    fwrite($log, "Copy misslyckades, försöker omkoda till AAC\n");
    @unlink($m4aFile);
    [$ok, $ffErr] = run_ffmpeg($mp4File, $m4aFile, ['-c:a', 'aac', '-b:a', '128k'], $log);
}

if (!$ok) {
    cleanup_partials(DOWNLOADS_DIR, $jobId);
    error_exit($errFile, $progFile, 'Konvertering misslyckades: ' . $ffErr, $log);
}

// Klart — städa upp och signalera done
@unlink($mp4File);
@unlink($progFile);
touch($doneFile);
fclose($log);
