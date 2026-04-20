<?php
/**
 * worker.php – runs as a background process, called by start.php
 * Usage: php worker.php <job_id>
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$job_id = $argv[1] ?? null;
if (!$job_id || !preg_match('/^[a-f0-9]{16}$/', $job_id)) {
    exit(1);
}

define('AUDIO_DIR',  __DIR__ . '/../audio/');
define('JOBS_DIR',   __DIR__ . '/../jobs/');
define('WEB_AUDIO',  'audio/');
define('YT_DLP',     '/usr/local/bin/yt-dlp');
define('FFMPEG_PATH', '/usr/local/bin/ffmpeg');
define('WRITE_THROTTLE_SECONDS', 2);

$job_file = JOBS_DIR . $job_id . '.json';

if (!file_exists($job_file)) {
    exit(1);
}

$job = json_decode(file_get_contents($job_file), true);
$url = $job['url'];

// Atomic write: temp-file then rename(). rename() is atomic on POSIX,
// so concurrent readers in status.php never see a half-written file.
function update_job(string $job_file, array $data): void {
    $tmp = $job_file . '.tmp';
    file_put_contents($tmp, json_encode($data));
    rename($tmp, $job_file);
}

$phase      = 'download';
$progress   = 0;
$last_write = 0;
$last_lines = [];

function error_exit(string $job_file, array $job, string $message, string $phase, int $progress): void {
    update_job($job_file, array_merge($job, [
        'status'   => 'error',
        'message'  => $message,
        'phase'    => 'error',
        'progress' => $progress,
    ]));
    exit(1);
}

update_job($job_file, array_merge($job, [
    'status'   => 'running',
    'message'  => 'Startar nedladdning...',
    'phase'    => $phase,
    'progress' => $progress,
]));

$temp_prefix = AUDIO_DIR . $job_id . '_';
$output_template = $temp_prefix . '%(title)s.%(ext)s';

// --newline forces progress lines to be terminated with \n instead of \r,
// so we can read them incrementally via fgets()
$cmd = sprintf(
    '%s -x --audio-format mp3 --audio-quality 0 --no-playlist --newline --ffmpeg-location %s -o %s %s 2>&1',
    escapeshellarg(YT_DLP),
    escapeshellarg(FFMPEG_PATH),
    escapeshellarg($output_template),
    escapeshellarg($url)
);

$handle = popen($cmd, 'r');
if (!$handle) {
    error_exit($job_file, $job, 'Kunde inte starta yt-dlp.', $phase, $progress);
}

while (!feof($handle)) {
    $line = fgets($handle);
    if ($line === false) break;
    $line = rtrim($line);
    if ($line === '') continue;

    $last_lines[] = $line;
    if (count($last_lines) > 5) array_shift($last_lines);

    if (preg_match('/^\[download\]\s+([\d.]+)%/', $line, $m)) {
        $progress = (int)round((float)$m[1]);
    } elseif (preg_match('/^\[(ExtractAudio|ffmpeg|Fixup)/', $line)) {
        $phase = 'convert';
    }

    $now = time();
    if ($now - $last_write >= WRITE_THROTTLE_SECONDS) {
        $last_write = $now;
        $msg = $phase === 'convert'
            ? 'Konverterar till MP3...'
            : sprintf('Hämtar: %d %%', $progress);
        update_job($job_file, array_merge($job, [
            'status'   => 'running',
            'message'  => $msg,
            'phase'    => $phase,
            'progress' => $progress,
        ]));
    }
}

$exit_code = pclose($handle);

if ($exit_code !== 0) {
    $error_msg = implode(' | ', array_slice($last_lines, -3));
    error_exit($job_file, $job, $error_msg ?: 'yt-dlp misslyckades.', $phase, $progress);
}

$files = glob($temp_prefix . '*.mp3');
if (empty($files)) {
    error_exit($job_file, $job, 'MP3-filen hittades inte efter konvertering.', $phase, $progress);
}

$filepath = $files[0];
$filename = basename($filepath);
$title    = preg_replace('/^[a-f0-9]{16}_/', '', pathinfo($filename, PATHINFO_FILENAME));

update_job($job_file, array_merge($job, [
    'status'   => 'done',
    'message'  => 'Klar!',
    'phase'    => 'done',
    'progress' => 100,
    'title'    => $title,
    'filename' => $filename,
    'file_url' => WEB_AUDIO . rawurlencode($filename),
    'finished' => time(),
]));
