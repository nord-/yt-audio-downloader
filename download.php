<?php
// ──────────────────────────────────────────────
//  Konfiguration – samma som i index.php
// ──────────────────────────────────────────────
define('YTDLP_PATH',  '/volume1/@yt-dlp/yt-dlp');
define('FFMPEG_PATH', '/var/packages/ffmpeg7/target/bin/ffmpeg');
define('DOWNLOADS_DIR', __DIR__ . '/downloads');

set_time_limit(30);  // Kort timeout – vi returnerar snabbt och jobbet körs i bakgrunden

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

// ── Kontrollera jobb-status ───────────────────
if ($action === 'check') {
    $jobId = $_POST['job_id'] ?? '';
    if (!preg_match('/^[a-f0-9]+$/', $jobId)) {
        echo json_encode(['done' => false, 'error' => 'Ogiltigt jobb-ID']);
        exit;
    }

    $m4aFile   = DOWNLOADS_DIR . '/' . $jobId . '.m4a';
    $titleFile = DOWNLOADS_DIR . '/.' . $jobId . '.title';
    $logFile   = DOWNLOADS_DIR . '/.' . $jobId . '.log';
    $errFile   = DOWNLOADS_DIR . '/.' . $jobId . '.err';
    $doneFile  = DOWNLOADS_DIR . '/.' . $jobId . '.done';   // sätts EFTER ffmpeg är klar
    $progFile  = DOWNLOADS_DIR . '/.' . $jobId . '.progress';

    // Kolla om ett fel uppstod
    if (file_exists($errFile)) {
        $errMsg = trim(file_get_contents($errFile));
        @unlink($errFile); @unlink($logFile); @unlink($progFile);
        echo json_encode(['done' => false, 'error' => $errMsg ?: 'Nedladdning misslyckades.']);
        exit;
    }

    // Kolla om hela jobbet är klart (.done sätts av worker.php efter yt-dlp+ffmpeg)
    if (file_exists($doneFile)) {
        @unlink($doneFile);
        $finalName = $jobId . '.m4a';

        // Byt namn till titel om vi har en
        if (file_exists($titleFile)) {
            $safeTitle = trim(file_get_contents($titleFile));
            $newPath   = DOWNLOADS_DIR . '/' . $safeTitle . '.m4a';
            if (!file_exists($newPath) && @rename($m4aFile, $newPath)) {
                $finalName = $safeTitle . '.m4a';
            }
            @unlink($titleFile);
        }

        @unlink($logFile);
        echo json_encode(['done' => true, 'filename' => $finalName]);
        exit;
    }

    // Jobb pågår — läs progress-fil och returnera fas + procent.
    // Om worker.php kraschat ligger progress-filen kvar med gammal updated_at;
    // då behandlar vi det som timeout istället för att returnera "stuck" progress.
    if (file_exists($progFile)) {
        $prog = @json_decode(@file_get_contents($progFile), true);
        if (is_array($prog) && isset($prog['phase'])) {
            $age = time() - (int) ($prog['updated_at'] ?? 0);
            if ($age > 600) {
                @unlink($logFile); @unlink($progFile);
                echo json_encode(['done' => false, 'error' => 'Nedladdningen tog för lång tid.']);
                exit;
            }
            echo json_encode([
                'done'    => false,
                'phase'   => $prog['phase'],
                'percent' => $prog['percent'] ?? 0,
            ]);
            exit;
        }
    }

    // Progress-fil saknas eller är korrupt — fallback till log-mtime som livstecken
    if (file_exists($logFile) && (time() - filemtime($logFile)) > 600) {
        @unlink($logFile); @unlink($progFile);
        echo json_encode(['done' => false, 'error' => 'Nedladdningen tog för lång tid.']);
        exit;
    }

    echo json_encode(['done' => false]);
    exit;
}

// ── Radera fil ────────────────────────────────
if ($action === 'delete') {
    $filename = basename($_POST['filename'] ?? '');
    if ($filename === '') {
        echo json_encode(['success' => false, 'error' => 'Inget filnamn angett.']);
        exit;
    }
    $path = DOWNLOADS_DIR . '/' . $filename;
    if (realpath($path) !== false && strpos(realpath($path), realpath(DOWNLOADS_DIR)) === 0) {
        if (unlink($path)) {
            echo json_encode(['success' => true, 'id' => md5($filename)]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Kunde inte ta bort filen.']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Ogiltig filsökväg.']);
    }
    exit;
}

// ── Starta nedladdning ────────────────────────
$url = trim($_POST['url'] ?? '');

if ($url === '') {
    echo json_encode(['success' => false, 'error' => 'Ingen URL angiven.']);
    exit;
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'Ogiltig URL.']);
    exit;
}

if (!is_dir(DOWNLOADS_DIR)) {
    mkdir(DOWNLOADS_DIR, 0755, true);
}

// ── 100.se: extrahera HLS-ström från BunnyCDN ─
function extract_100se(string $pageUrl): array {
    $html = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($pageUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
    }

    if (!$html) {
        $ctx  = stream_context_create([
            'http' => ['header' => "User-Agent: Mozilla/5.0\r\n", 'timeout' => 15],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $html = @file_get_contents($pageUrl, false, $ctx);
    }

    if (!$html) return [];

    $result = [];

    if (preg_match(
        '~<meta[^>]+property="og:image"[^>]+content="https://(vz-[a-f0-9-]+\.b-cdn\.net)/([a-f0-9-]{36})/~',
        $html, $m
    ) || preg_match(
        '~<meta[^>]+content="https://(vz-[a-f0-9-]+\.b-cdn\.net)/([a-f0-9-]{36})/[^"]*"[^>]+property="og:image"~',
        $html, $m
    )) {
        // Peka direkt på 360p-subströmmen (inte master-playlisten).
        // Undviker att yt-dlp väljer fel kvalitet eller laddar ner allt.
        $result['url'] = "https://{$m[1]}/{$m[2]}/360p/video.m3u8";
    }

    if (preg_match('~<meta[^>]+property="og:title"[^>]+content="([^"]+)"~', $html, $m)) {
        $result['title'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } elseif (preg_match('~<meta[^>]+content="([^"]+)"[^>]+property="og:title"~', $html, $m)) {
        $result['title'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return $result;
}

$downloadTitle = null;
if (str_contains($url, '100.se')) {
    $extracted = extract_100se($url);
    if (!empty($extracted['url']))   $url           = $extracted['url'];
    if (!empty($extracted['title'])) $downloadTitle = $extracted['title'];
}

// Unikt jobb-ID — används som temporärt filnamn
$jobId = bin2hex(random_bytes(8));

// Spara titel för namnbyte när jobbet är klart
if ($downloadTitle !== null) {
    $safeTitle = preg_replace('/[^a-zA-Z0-9åäöÅÄÖ_-]/', '_', $downloadTitle);
    $safeTitle = preg_replace('/_+/', '_', trim($safeTitle, '_'));
    file_put_contents(DOWNLOADS_DIR . '/.' . $jobId . '.title', $safeTitle);
}

// Starta worker.php i bakgrunden. Den sköter yt-dlp + ffmpeg och skriver
// progress-fil som frontend pollar via ?action=check.
$bgCmd = 'nohup php ' . escapeshellarg(__DIR__ . '/worker.php')
    . ' ' . escapeshellarg($jobId)
    . ' ' . escapeshellarg($url)
    . ' > /dev/null 2>&1 &';

$bgOutput   = [];
$bgExitCode = 0;
exec($bgCmd, $bgOutput, $bgExitCode);

if ($bgExitCode !== 0) {
    // Om själva bakgrundsstarten failade (t.ex. php/nohup saknas) har vi ingen
    // worker som kommer skriva .err — rapportera direkt och städa titel-filen.
    @unlink(DOWNLOADS_DIR . '/.' . $jobId . '.title');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Kunde inte starta bakgrundsjobb: ' . trim(implode("\n", $bgOutput)),
    ]);
    exit;
}

echo json_encode(['success' => true, 'job_id' => $jobId, 'pending' => true]);
