<?php
header('Content-Type: application/json');

$job_id = trim($_GET['job_id'] ?? '');

if (!$job_id || !preg_match('/^[a-f0-9]{16}$/', $job_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Ogiltigt jobb-ID.']);
    exit;
}

define('JOBS_DIR', __DIR__ . '/../jobs/');

$job_file = JOBS_DIR . $job_id . '.json';

if (!file_exists($job_file)) {
    echo json_encode(['status' => 'error', 'message' => 'Jobbet hittades inte.']);
    exit;
}

$job = json_decode(file_get_contents($job_file), true);

// Clean up old job files (older than 1 hour) to avoid cluttering the jobs dir
$cutoff = time() - 3600;
foreach (glob(JOBS_DIR . '*.json') as $f) {
    $data = json_decode(file_get_contents($f), true);
    if (($data['created'] ?? 0) < $cutoff) {
        @unlink($f);
    }
}

echo json_encode($job);
