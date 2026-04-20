<?php
header('Content-Type: application/json');

$url = trim($_POST['url'] ?? '');

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['error' => 'Ogiltig URL.']);
    exit;
}

define('AUDIO_DIR', __DIR__ . '/../audio/');
define('JOBS_DIR',  __DIR__ . '/../jobs/');

foreach ([AUDIO_DIR, JOBS_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

$job_id   = bin2hex(random_bytes(8));
$job_file = JOBS_DIR . $job_id . '.json';

file_put_contents($job_file, json_encode([
    'status'   => 'pending',
    'url'      => $url,
    'message'  => 'Väntar på start...',
    'phase'    => 'pending',
    'progress' => 0,
    'created'  => time(),
]));

$cmd = sprintf(
    'php %s/worker.php %s > /dev/null 2>&1 &',
    escapeshellarg(__DIR__),
    escapeshellarg($job_id)
);

exec($cmd);

echo json_encode(['job_id' => $job_id]);
