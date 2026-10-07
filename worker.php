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

$startTime   = time();
$lastPercent = 0;
$lastLogged  = 0;
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

// Loggrad med tidsstämpel och sekunder sedan workern startade
function log_line($log, string $msg): void {
    global $startTime;
    fwrite($log, sprintf("[%s +%ds] %s\n", date('H:i:s'), time() - $startTime, $msg));
    fflush($log);
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
log_line($log, "worker start, url=$url");
write_progress($progFile, ['phase' => 'download', 'percent' => 0, 'updated_at' => time()]);

// ── Snabbväg: Bunny-HLS (100.se) direkt med ffmpeg ──────────
// Sparar yt-dlps uppstart (~15 s på NAS:en) och den mellanliggande mp4:n.
// Misslyckas det faller vi tillbaka på yt-dlp-flödet nedan.
if (preg_match('~^https://vz-[a-f0-9-]+\.b-cdn\.net/[a-f0-9-]{36}/playlist\.m3u8$~', $url)) {
    // Ljud-playlisten först (slipper videovarianterna, ~5 s snabbare), sedan master-playlisten.
    $candidates = [preg_replace('~/playlist\.m3u8$~', '/audio/audio.m3u8', $url), $url];
    foreach ($candidates as $srcUrl) {
        $dirCmd = implode(' ', [
            escapeshellarg(FFMPEG_PATH),
            '-nostdin', '-hide_banner', '-nostats', '-y',
            '-headers', escapeshellarg("Referer: https://www.100.se/\r\n"),
            '-i', escapeshellarg($srcUrl),
            '-vn', '-c:a', 'copy', '-bsf:a', 'aac_adtstoasc',
            '-progress', 'pipe:1',
            escapeshellarg($m4aFile),
            '2>&1',
        ]);
        log_line($log, 'ffmpeg direkt: ' . $dirCmd);
        $fp = popen($dirCmd, 'r');
        $duration = 0.0;
        if ($fp) {
            while (($line = fgets($fp)) !== false) {
                $line = rtrim($line);
                // Duration: 00:18:45.06 (skrivs en gång i ffmpegs inmatningsinfo)
                if ($duration === 0.0 && preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $line, $m)) {
                    $duration = $m[1] * 3600 + $m[2] * 60 + (float) $m[3];
                }
                // out_time_us=<mikrosekunder>
                if ($duration > 0 && preg_match('/^out_time_us=(\d+)$/', $line, $m)) {
                    $pct = min(99, (int) floor(($m[1] / 1e6) / $duration * 100));
                    $now = time();
                    if ($pct !== $lastPercent && ($now - $lastWrite) >= WRITE_THROTTLE_SECONDS) {
                        write_progress($progFile, ['phase' => 'download', 'percent' => $pct, 'updated_at' => $now]);
                        $lastWrite   = $now;
                        $lastPercent = $pct;
                    }
                }
                // Loggen: utan progress-nyckel/värde-rader och HLS-brus (en rad per segment)
                if (!preg_match('/^(out_time|out_time_ms|total_size|bitrate|speed|progress|fps|stream_|dup_frames|drop_frames)[=_]/', $line)
                    && !preg_match('/^\[(hls|https?) @ [^\]]+\] (Opening|Skip|No longer)/', $line)) {
                    log_line($log, $line);
                }
            }
            $status = pclose($fp);
        } else {
            $status = 1;
        }
        if ($status === 0 && file_exists($m4aFile) && filesize($m4aFile) > 0) {
            @unlink($progFile);
            touch($doneFile);
            log_line($log, 'klart (ffmpeg direkt), totalt ' . (time() - $startTime) . 's');
            fclose($log);
            exit(0);
        }
        log_line($log, "ffmpeg direkt misslyckades (exit=$status) mot $srcUrl");
        @unlink($m4aFile);
        $lastPercent = 0;
        write_progress($progFile, ['phase' => 'download', 'percent' => 0, 'updated_at' => time()]);
    }
    log_line($log, 'faller tillbaka på yt-dlp');
}

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

log_line($log, 'yt-dlp: ' . $dlCmd);
$fp = popen($dlCmd, 'r');
if (!$fp) {
    error_exit($errFile, $progFile, 'Kunde inte starta yt-dlp.', $log);
}

$lastError = '';
while (($line = fgets($fp)) !== false) {
    $line = rtrim($line);

    // Progress-rader loggas max var 5:e sekund, övriga rader alltid
    $isProgress = str_starts_with($line, '[download]') && str_contains($line, '%');
    if (!$isProgress || (time() - $lastLogged) >= 5 || preg_match('/\s100(\.0)?%/', $line)) {
        log_line($log, $line);
        if ($isProgress) $lastLogged = time();
    }

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
log_line($log, "yt-dlp klar, exit=$status");
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

log_line($log, 'ffmpeg-steg start');
// Första försöket: kopiera ljudspåret utan omkodning. Misslyckas det (t.ex. om
// källan inte är AAC) faller vi tillbaka på omkodning till AAC.
[$ok, $ffErr] = run_ffmpeg($mp4File, $m4aFile, ['-c:a', 'copy', '-bsf:a', 'aac_adtstoasc'], $log);
if (!$ok) {
    fwrite($log, "Copy misslyckades, försöker omkoda till AAC\n");
    @unlink($m4aFile);
    [$ok, $ffErr] = run_ffmpeg($mp4File, $m4aFile, ['-c:a', 'aac', '-b:a', '128k'], $log);
}

log_line($log, 'ffmpeg-steg klart, ok=' . ($ok ? 'ja' : 'nej'));
if (!$ok) {
    cleanup_partials(DOWNLOADS_DIR, $jobId);
    error_exit($errFile, $progFile, 'Konvertering misslyckades: ' . $ffErr, $log);
}

// Klart — städa upp och signalera done
@unlink($mp4File);
@unlink($progFile);
touch($doneFile);
log_line($log, 'klart, totalt ' . (time() - $startTime) . 's');
fclose($log);
