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

$job_file = JOBS_DIR . $job_id . '.json';

if (!file_exists($job_file)) {
    exit(1);
}

$job = json_decode(file_get_contents($job_file), true);
$url = $job['url'];

function update_job(string $job_file, array $data): void {
    file_put_contents($job_file, json_encode($data));
}

update_job($job_file, array_merge($job, [
    'status'  => 'running',
    'message' => 'Laddar ner och konverterar...',
]));

// Get a unique temp filename prefix based on job_id to find the output file later
$temp_prefix = AUDIO_DIR . $job_id . '_';

// Use job_id as part of the output template so we can locate the file reliably
$output_template = $temp_prefix . '%(title)s.%(ext)s';

$cmd = sprintf(
    '%s -x --audio-format mp3 --audio-quality 0 --no-playlist --ffmpeg-location %s -o %s %s 2>&1',
    escapeshellarg(YT_DLP),
    escapeshellarg(FFMPEG_PATH),
    escapeshellarg($output_template),
    escapeshellarg($url)
);

$output   = [];
$exit_code = 0;
exec($cmd, $output, $exit_code);

if ($exit_code !== 0) {
    $error_msg = implode(' ', array_slice($output, -3)); // last 3 lines
    update_job($job_file, array_merge($job, [
        'status'  => 'error',
        'message' => $error_msg ?: 'yt-dlp misslyckades.',
    ]));
    exit(1);
}

// Find the produced mp3 file (prefix is unique to this job)
$files = glob($temp_prefix . '*.mp3');
if (empty($files)) {
    update_job($job_file, array_merge($job, [
        'status'  => 'error',
        'message' => 'MP3-filen hittades inte efter konvertering.',
    ]));
    exit(1);
}

$filepath = $files[0];
$filename  = basename($filepath);

// Extract the title (strip our job_id prefix)
$title = preg_replace('/^[a-f0-9]{16}_/', '', pathinfo($filename, PATHINFO_FILENAME));

update_job($job_file, array_merge($job, [
    'status'   => 'done',
    'message'  => 'Klar!',
    'title'    => $title,
    'filename' => $filename,
    'file_url' => WEB_AUDIO . rawurlencode($filename),
    'finished' => time(),
]));
