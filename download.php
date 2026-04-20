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
    $descFile  = DOWNLOADS_DIR . '/.' . $jobId . '.desc';
    $imgFile   = DOWNLOADS_DIR . '/.' . $jobId . '.jpg';
    $logFile   = DOWNLOADS_DIR . '/.' . $jobId . '.log';
    $errFile   = DOWNLOADS_DIR . '/.' . $jobId . '.err';
    $doneFile  = DOWNLOADS_DIR . '/.' . $jobId . '.done';   // sätts EFTER ffmpeg är klar
    $progFile  = DOWNLOADS_DIR . '/.' . $jobId . '.progress';

    // Kolla om ett fel uppstod
    if (file_exists($errFile)) {
        $errMsg = trim(file_get_contents($errFile));
        @unlink($errFile); @unlink($logFile); @unlink($progFile);
        @unlink($titleFile); @unlink($descFile); @unlink($imgFile);
        echo json_encode(['done' => false, 'error' => $errMsg ?: 'Nedladdning misslyckades.']);
        exit;
    }

    // Kolla om hela jobbet är klart (.done sätts av worker.php efter yt-dlp+ffmpeg)
    if (file_exists($doneFile)) {
        @unlink($doneFile);
        $finalBase = $jobId;

        // Byt namn till titel om vi har en. Sidecar innehåller rå titel;
        // filsystem-safe variant härleds här så vi aldrig har ?/:/* i filnamn.
        if (file_exists($titleFile)) {
            $rawTitle  = trim(file_get_contents($titleFile));
            $safeTitle = preg_replace('/[^a-zA-Z0-9åäöÅÄÖ._-]/', '_', $rawTitle);
            $safeTitle = preg_replace('/_+/', '_', trim($safeTitle, '_.'));
            if ($safeTitle !== '') {
                $newPath = DOWNLOADS_DIR . '/' . $safeTitle . '.m4a';
                if (!file_exists($newPath) && @rename($m4aFile, $newPath)) {
                    $finalBase = $safeTitle;
                }
            }
            // Behåll sidecar bredvid ljudfilen — rss.php läser den som RSS-titel.
            // !file_exists-check: undvik att POSIX-rename skriver över en orphan-sidecar.
            $destTitle = DOWNLOADS_DIR . '/' . $finalBase . '.title';
            if (!file_exists($destTitle)) {
                @rename($titleFile, $destTitle);
            }
        }

        // Flytta ingress + episodbild till synliga sidecars med samma basnamn som ljudfilen.
        $destDesc = DOWNLOADS_DIR . '/' . $finalBase . '.desc';
        if (file_exists($descFile) && !file_exists($destDesc)) {
            @rename($descFile, $destDesc);
        }
        $destImg = DOWNLOADS_DIR . '/' . $finalBase . '.jpg';
        if (file_exists($imgFile) && !file_exists($destImg)) {
            @rename($imgFile, $destImg);
        }

        @unlink($logFile);
        echo json_encode(['done' => true, 'filename' => $finalBase . '.m4a']);
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
            // Rensa sidecars (.title, .desc, .jpg) med samma basnamn — annars ligger de kvar som skräp.
            $base = pathinfo($filename, PATHINFO_FILENAME);
            @unlink(DOWNLOADS_DIR . '/' . $base . '.title');
            @unlink(DOWNLOADS_DIR . '/' . $base . '.desc');
            @unlink(DOWNLOADS_DIR . '/' . $base . '.jpg');
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

    if (preg_match('~<meta[^>]+property="og:image"[^>]+content="([^"]+)"~', $html, $m)
     || preg_match('~<meta[^>]+content="([^"]+)"[^>]+property="og:image"~', $html, $m)) {
        $result['image_url'] = $m[1];
        // Peka direkt på 360p-subströmmen (inte master-playlisten).
        // Undviker att yt-dlp väljer fel kvalitet eller laddar ner allt.
        if (preg_match('~^https://(vz-[a-f0-9-]+\.b-cdn\.net)/([a-f0-9-]{36})/~', $m[1], $mm)) {
            $result['url'] = "https://{$mm[1]}/{$mm[2]}/360p/video.m3u8";
        }
    }

    if (preg_match('~<meta[^>]+property="og:title"[^>]+content="([^"]+)"~', $html, $m)) {
        $result['title'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } elseif (preg_match('~<meta[^>]+content="([^"]+)"[^>]+property="og:title"~', $html, $m)) {
        $result['title'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // 100.se lägger ingressen i og:description — används som RSS <description>.
    if (preg_match('~<meta[^>]+property="og:description"[^>]+content="([^"]+)"~', $html, $m)) {
        $result['description'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } elseif (preg_match('~<meta[^>]+content="([^"]+)"[^>]+property="og:description"~', $html, $m)) {
        $result['description'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } else {
        // Fallback: vissa 100.se-sidor saknar og:description men har ingressen i en
        // div med klasserna "text-xl md:text-2xl" (verkar vara unik för ingressen).
        $fallback = extract_text_xl_description($html);
        if ($fallback !== '') {
            $result['description'] = $fallback;
        }
    }

    return $result;
}

// Plocka ut text ur <div class="... text-xl ... md:text-2xl ..."> när og:description saknas.
// Använder DOMXPath eftersom klassordning kan variera och divar kan vara nästlade.
function extract_text_xl_description(string $html): string {
    if (!class_exists('DOMDocument')) return '';
    $doc  = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    @$doc->loadHTML('<?xml encoding="UTF-8"?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query(
        "//div[contains(concat(' ', normalize-space(@class), ' '), ' text-xl ')"
        . " and contains(concat(' ', normalize-space(@class), ' '), ' md:text-2xl ')]"
    );
    if ($nodes && $nodes->length > 0) {
        return trim(preg_replace('/\s+/', ' ', $nodes->item(0)->textContent));
    }
    return '';
}

$downloadTitle = null;
$downloadDesc  = null;
$downloadImage = null;
if (str_contains($url, '100.se')) {
    $extracted = extract_100se($url);
    if (!empty($extracted['url']))         $url           = $extracted['url'];
    if (!empty($extracted['title']))       $downloadTitle = $extracted['title'];
    if (!empty($extracted['description'])) $downloadDesc  = $extracted['description'];
    if (!empty($extracted['image_url']))   $downloadImage = $extracted['image_url'];
}

// Unikt jobb-ID — används som temporärt filnamn
$jobId = bin2hex(random_bytes(8));

// Spara rå titel — används både för filsystem-rename (saneras on-the-fly) och
// som RSS-titel (original-text med frågetecken, punkter osv bevaras).
if ($downloadTitle !== null) {
    file_put_contents(DOWNLOADS_DIR . '/.' . $jobId . '.title', $downloadTitle);
}

// Spara ingress som sidecar — flyttas till <titel>.desc när jobbet är klart.
if ($downloadDesc !== null) {
    file_put_contents(DOWNLOADS_DIR . '/.' . $jobId . '.desc', $downloadDesc);
}

// Hämta episodbild lokalt — bilden följer då med även om källan försvinner.
// Misslyckas tyst: bilden är trevlig-att-ha, inte kritisk för jobbet.
// Låser host till BunnyCDN-mönstret som 100.se faktiskt använder — förhindrar SSRF
// eftersom og:image kommer från en sida som en angripare potentiellt kontrollerar
// (str_contains($url,'100.se') är en svag filter).
if ($downloadImage !== null && function_exists('curl_init')
    && preg_match('~^https://vz-[a-f0-9-]+\.b-cdn\.net/~', $downloadImage)) {
    $imgPath = DOWNLOADS_DIR . '/.' . $jobId . '.jpg';
    $fp      = fopen($imgPath, 'wb');
    if ($fp) {
        $ch = curl_init($downloadImage);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $ok   = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code >= 400 || filesize($imgPath) < 100) {
            @unlink($imgPath);
        }
    }
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
