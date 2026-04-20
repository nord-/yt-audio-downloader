<?php
header('Content-Type: application/json');

$url = trim($_POST['url'] ?? '');

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['error' => 'Ogiltig URL.']);
    exit;
}

// Paths – adjust AUDIO_DIR to your Synology web root
define('AUDIO_DIR',  __DIR__ . '/../audio/');
define('JOBS_DIR',   __DIR__ . '/../jobs/');
define('WEB_AUDIO',  '../audio/');   // Relative URL served by Apache
define('YT_DLP',     '/usr/local/bin/yt-dlp');
define('FFMPEG_PATH', '/usr/local/bin/ffmpeg');

foreach ([AUDIO_DIR, JOBS_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

$job_id   = bin2hex(random_bytes(8));
$job_file = JOBS_DIR . $job_id . '.json';

// Write initial job state
file_put_contents($job_file, json_encode([
    'status'  => 'pending',
    'url'     => $url,
    'message' => 'Väntar på start...',
    'created' => time(),
]));

// Build the yt-dlp command
// --no-playlist: don't download entire playlists
// --ffmpeg-location: explicit path in case it's not in PATH
$output_template = AUDIO_DIR . '%(title)s.%(ext)s';

$cmd = sprintf(
    '%s -x --audio-format mp3 --audio-quality 0 --no-playlist --ffmpeg-location %s -o %s %s > /dev/null 2>&1 ; php %s/worker.php %s &',
    escapeshellarg(YT_DLP),
    escapeshellarg(FFMPEG_PATH),
    escapeshellarg($output_template),
    escapeshellarg($url),
    escapeshellarg(__DIR__),
    escapeshellarg($job_id)
);

// Launch worker asynchronously
exec($cmd);

echo json_encode(['job_id' => $job_id]);
