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
define('NET_TIMEOUT_US', 30000000);   // ffmpeg: max 30 s utan data från CDN innan anropet avbryts
define('SOURCE_REFERER', 'https://www.100.se/');

$jobId = $argv[1] ?? '';
$url   = $argv[2] ?? '';

if (!preg_match('/^[a-f0-9]+$/', $jobId) || $url === '') {
    fwrite(STDERR, "usage: worker.php <job_id> <url>\n");
    exit(1);
}

$mp4File  = DOWNLOADS_DIR . '/' . $jobId . '.mp4';
$m4aFile  = DOWNLOADS_DIR . '/' . $jobId . '.m4a';
// ffmpeg skriver till en dold tempfil som flyttas till $m4aFile först när allt lyckats,
// så index.php/rss.php aldrig listar en halvskriven fil.
$tmpM4a   = DOWNLOADS_DIR . '/.' . $jobId . '.tmp.m4a';
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
    @unlink("$downloadsDir/.$jobId.tmp.m4a");
}

// Loggrad med tidsstämpel och sekunder sedan workern startade
function log_line($log, string $msg): void {
    global $startTime;
    fwrite($log, sprintf("[%s +%ds] %s\n", date('H:i:s'), time() - $startTime, $msg));
    fflush($log);
}

// Throttlad progress-skrivning (max var WRITE_THROTTLE_SECONDS). Download-fasen skrivs
// bara när procenten ändrats; convert-fasen skrivs som heartbeat så att `check` inte
// tolkar en lång omkodning som ett hängt jobb (updated_at > 10 min).
function report_progress(string $progFile, string $phase, int $pct): void {
    global $lastPercent, $lastWrite;
    $now = time();
    if ($phase === 'download' && $pct === $lastPercent) return;
    if (($now - $lastWrite) < WRITE_THROTTLE_SECONDS) return;
    write_progress($progFile, ['phase' => $phase, 'percent' => $pct, 'updated_at' => $now]);
    $lastWrite = $now;
    if ($phase === 'download') $lastPercent = $pct;
}

// Källor som kräver Referer från 100.se (BunnyCDN-hotlink-skydd). Skickas inte till andra sajter.
function needs_source_referer(string $url): bool {
    return (bool) preg_match('~^https?://([a-z0-9-]+\.)*(100\.se|b-cdn\.net|mediadelivery\.net)(/|$)~i', $url);
}

// Hämta en liten textfil (master-playlist). Returnerar null vid fel.
function fetch_text(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Referer: ' . SOURCE_REFERER],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
}

// Läs ljudrenditionens URL ur master-playlistens #EXT-X-MEDIA:TYPE=AUDIO,URI="...".
// Relativa URI:er löses mot master-URL:en; absoluta godtas bara från samma värd.
function find_audio_playlist(string $masterUrl, string $master): ?string {
    if (!preg_match_all('/^#EXT-X-MEDIA:(.+)$/m', $master, $all)) return null;
    foreach ($all[1] as $attrs) {
        if (!preg_match('/TYPE=AUDIO/', $attrs) || !preg_match('/URI="([^"]+)"/', $attrs, $m)) continue;
        $uri = $m[1];
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $uri)) {
            return parse_url($uri, PHP_URL_HOST) === parse_url($masterUrl, PHP_URL_HOST) ? $uri : null;
        }
        return preg_replace('~[^/]*$~', '', strtok($masterUrl, '?')) . ltrim($uri, '/');
    }
    return null;
}

// Kör ffmpeg (ljudspår -> m4a) och loggar via log_line. $onProgress(sekunder, total) anropas
// per out_time_us-rad från -progress. Returnerar [lyckades, sista felraden].
// $knownDuration: förväntad längd i sekunder. Sätts från ffmpegs Duration-rad och kan lånas av
// ett senare försök mot samma innehåll där Duration saknas (N/A).
function run_ffmpeg(array $inputArgs, string $input, array $outArgs, string $outFile, $log, ?callable $onProgress = null, float &$knownDuration = 0.0): array {
    $cmd = implode(' ', array_merge(
        [escapeshellarg(FFMPEG_PATH), '-nostdin', '-hide_banner', '-nostats', '-y'],
        $inputArgs,
        ['-i', escapeshellarg($input)],
        $outArgs,
        ['-progress', 'pipe:1', escapeshellarg($outFile), '2>&1']
    ));
    log_line($log, 'ffmpeg: ' . $cmd);
    $fp = popen($cmd, 'r');
    if (!$fp) return [false, 'Kunde inte starta ffmpeg.'];

    $duration = $knownDuration;
    $lastOut  = 0.0;
    $last     = '';
    $headerSeen = false;
    while (($line = fgets($fp)) !== false) {
        $line = rtrim($line);
        // Duration: 00:18:45.06 (skrivs en gång i ffmpegs inmatningsinfo)
        if (!$headerSeen && preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $line, $m)) {
            $headerSeen = true;
            $duration = $knownDuration = $m[1] * 3600 + $m[2] * 60 + (float) $m[3];
        }
        // out_time_us=<mikrosekunder>
        if (preg_match('/^out_time_us=(\d+)$/', $line, $m)) {
            $lastOut = $m[1] / 1e6;
            if ($onProgress) $onProgress($m[1] / 1e6, $duration);
            continue;
        }
        // Progress-nyckel/värde-rader och HLS-brus (en rad per segment) loggas inte
        if (preg_match('/^(frame|fps|stream_\d+_\d+_q|bitrate|total_size|out_time\w*|dup_frames|drop_frames|speed|progress)=/', $line)
            || preg_match('/^\[(hls|https?) @ [^\]]+\] (Opening|Skip|No longer)/', $line)) {
            continue;
        }
        log_line($log, $line);
        if ($line !== '') $last = $line;
    }
    $status = pclose($fp);
    $ok = $status === 0 && is_file($outFile) && filesize($outFile) > 0;
    // ffmpeg tolkar ett avbrutet HLS-segment (timeout, 404) som slutet på strömmen och
    // avslutar med exit 0. Kontrollera därför att hela längden faktiskt skrevs.
    if ($ok && $duration > 0 && $lastOut < $duration - max(3.0, $duration * 0.01)) {
        $last = sprintf('Ofullständig ljudfil: %.0f s av %.0f s skrevs (avbruten ström).', $lastOut, $duration);
        log_line($log, $last);
        $ok = false;
    }
    return [$ok, $last];
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
    // Ljudrenditionen läses ur master-playlisten (slipper videovarianterna, ~5 s snabbare).
    // Hittas den inte, eller går den inte att öppna, provas master-playlisten.
    $candidates = [];
    $master = fetch_text($url);
    $audioUrl = $master !== null ? find_audio_playlist($url, $master) : null;
    if ($audioUrl !== null) {
        log_line($log, "ljudrendition: $audioUrl");
        $candidates[] = $audioUrl;
    } else {
        log_line($log, 'ingen ljudrendition hittad i master-playlisten, använder master direkt');
    }
    $candidates[] = $url;

    $netArgs = ['-headers', escapeshellarg("Referer: " . SOURCE_REFERER . "\r\n"), '-rw_timeout', (string) NET_TIMEOUT_US];
    $onProgress = function (float $sec, float $dur) use ($progFile): void {
        if ($dur > 0) report_progress($progFile, 'download', min(99, (int) floor($sec / $dur * 100)));
    };
    $expectedDuration = 0.0;
    foreach ($candidates as $srcUrl) {
        [$ok] = run_ffmpeg($netArgs, $srcUrl, ['-vn', '-c:a', 'copy', '-bsf:a', 'aac_adtstoasc'], $tmpM4a, $log, $onProgress, $expectedDuration);
        if ($ok && @rename($tmpM4a, $m4aFile)) {
            @unlink($progFile);
            touch($doneFile);
            log_line($log, 'klart (ffmpeg direkt), totalt ' . (time() - $startTime) . 's');
            fclose($log);
            exit(0);
        }
        log_line($log, "ffmpeg direkt misslyckades mot $srcUrl");
        @unlink($tmpM4a);
        $lastPercent = 0;
        write_progress($progFile, ['phase' => 'download', 'percent' => 0, 'updated_at' => time()]);
    }
    log_line($log, 'faller tillbaka på yt-dlp');
}

// --newline tvingar yt-dlp att avsluta progress-rader med \n istället för \r,
// så fgets() kan läsa dem direkt utan buffring.
// Bara ljudspåret hämtas (AAC föredras så att copy-steget räcker); Referer skickas bara till
// 100.se/Bunny, inte till andra sajter.
$dlCmd = implode(' ', array_merge([
    escapeshellarg(YTDLP_PATH),
    '--downloader', 'native',
    '-N', '4',
    '--newline',
    '--no-playlist',
    '-f', escapeshellarg('ba[acodec^=mp4a]/ba/b'),
], needs_source_referer($url) ? ['--referer', escapeshellarg(SOURCE_REFERER)] : [], [
    '--restrict-filenames',
    '--ffmpeg-location', escapeshellarg(FFMPEG_PATH),
    '-o', escapeshellarg($mp4File),
    escapeshellarg($url),
    '2>&1',
]));

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
        report_progress($progFile, 'download', (int) round((float) $m[1]));
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

// ── Steg 2: ffmpeg extraherar ljudspåret ─────
// Först copy utan omkodning; misslyckas det (t.ex. om källan inte är AAC) omkodas till AAC.
// Frontend visar indeterminate bar under konverteringen, men updated_at uppdateras löpande
// så att en lång omkodning inte tolkas som ett hängt jobb.
write_progress($progFile, ['phase' => 'convert', 'percent' => $lastPercent, 'updated_at' => time()]);
$lastWrite = time();

$heartbeat = function () use ($progFile): void {
    global $lastPercent;
    report_progress($progFile, 'convert', $lastPercent);
};

log_line($log, 'ffmpeg-steg start');
[$ok, $ffErr] = run_ffmpeg([], $mp4File, ['-vn', '-map', '0:a:0', '-c:a', 'copy', '-bsf:a', 'aac_adtstoasc'], $tmpM4a, $log, $heartbeat);
if (!$ok) {
    log_line($log, 'copy misslyckades, försöker omkoda till AAC');
    @unlink($tmpM4a);
    [$ok, $ffErr] = run_ffmpeg([], $mp4File, ['-vn', '-map', '0:a:0', '-c:a', 'aac', '-b:a', '128k'], $tmpM4a, $log, $heartbeat);
}

log_line($log, 'ffmpeg-steg klart, ok=' . ($ok ? 'ja' : 'nej'));
if (!$ok || !@rename($tmpM4a, $m4aFile)) {
    cleanup_partials(DOWNLOADS_DIR, $jobId);
    error_exit($errFile, $progFile, 'Konvertering misslyckades: ' . $ffErr, $log);
}

// Klart — städa upp och signalera done
@unlink($mp4File);
@unlink($progFile);
touch($doneFile);
log_line($log, 'klart, totalt ' . (time() - $startTime) . 's');
fclose($log);
