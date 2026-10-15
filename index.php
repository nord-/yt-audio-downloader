<?php
// ──────────────────────────────────────────────
//  Configuration – adjust to your Synology
// ──────────────────────────────────────────────
define('YTDLP_PATH',   '/volume1/@yt-dlp/yt-dlp');   // wrapper script on the volume
define('FFMPEG_PATH',  '/var/packages/ffmpeg7/target/bin/ffmpeg');    // SynoCommunity ffmpeg7
define('DOWNLOADS_DIR', __DIR__ . '/downloads');
define('DOWNLOADS_URL', 'downloads');               // relative URL to the folder

// Create the downloads folder if it is missing
if (!is_dir(DOWNLOADS_DIR)) {
    mkdir(DOWNLOADS_DIR, 0755, true);
}

// Complete the rename for jobs that finished while the client was away.
// Normally the rename happens in download.php?action=check, but if the user closed
// the tab between the worker writing .done and the next poll, the sentinel files
// are still there and the m4a is still named <jobId>.m4a.
foreach (glob(DOWNLOADS_DIR . '/.*.done') as $doneFile) {
    if (!preg_match('~/\.([a-f0-9]+)\.done$~', $doneFile, $m)) continue;
    $jid       = $m[1];
    $m4aFile   = DOWNLOADS_DIR . '/' . $jid . '.m4a';
    $titleFile = DOWNLOADS_DIR . '/.' . $jid . '.title';
    $descFile  = DOWNLOADS_DIR . '/.' . $jid . '.desc';
    $imgFile   = DOWNLOADS_DIR . '/.' . $jid . '.imageurl';
    $finalBase = $jid;

    if (is_file($m4aFile) && is_file($titleFile)) {
        $rawTitle  = trim(file_get_contents($titleFile));
        $safeTitle = preg_replace('/[^a-zA-Z0-9åäöÅÄÖ._-]/', '_', $rawTitle);
        $safeTitle = preg_replace('/_+/', '_', trim($safeTitle, '_.'));
        if ($safeTitle !== '') {
            $newPath = DOWNLOADS_DIR . '/' . $safeTitle . '.m4a';
            if (!file_exists($newPath) && @rename($m4aFile, $newPath)) {
                $finalBase = $safeTitle;
            }
        }
    }
    // !file_exists check: keep POSIX rename from overwriting an orphan sidecar
    // with the same base name (extremely rare, but a surgical fix on reviewer request).
    $destTitle = DOWNLOADS_DIR . '/' . $finalBase . '.title';
    if (is_file($titleFile) && !file_exists($destTitle)) {
        @rename($titleFile, $destTitle);
    }
    $destDesc = DOWNLOADS_DIR . '/' . $finalBase . '.desc';
    if (is_file($descFile) && !file_exists($destDesc)) {
        @rename($descFile, $destDesc);
    }
    $destImg = DOWNLOADS_DIR . '/' . $finalBase . '.imageurl';
    if (is_file($imgFile) && !file_exists($destImg)) {
        @rename($imgFile, $destImg);
    }
    @unlink($doneFile);
    @unlink(DOWNLOADS_DIR . '/.' . $jid . '.progress');
}

// Clean up old job files (hidden files older than 24h)
foreach (glob(DOWNLOADS_DIR . '/.*') as $hidden) {
    if (is_file($hidden) && (time() - filemtime($hidden)) > 86400) {
        @unlink($hidden);
    }
}
// Clean up temporary video files yt-dlp may have left behind after a crash (older than 1h)
foreach (glob(DOWNLOADS_DIR . '/*') as $f) {
    if (!is_file($f)) continue;
    if (preg_match('/\.(mp3|m4a|ogg|opus|wav|title|desc|imageurl|jpg)$/i', $f)) continue;  // keep finished audio files + sidecars (.jpg = old thumbnails, used as fallback)
    if ((time() - filemtime($f)) > 3600) {
        @unlink($f);
    }
}

// Collect the list of downloaded files
$files = [];
if (is_dir(DOWNLOADS_DIR)) {
    foreach (scandir(DOWNLOADS_DIR) as $item) {
        // Show only finished audio files — hide hidden files, temp files and junk
        if ($item[0] === '.') continue;                          // hidden files (.log, .title, .htaccess etc.)
        if (!preg_match('/\.(mp3|m4a|ogg|opus|wav)$/i', $item)) continue;  // audio formats only
        if (str_ends_with($item, '.part')) continue;             // unfinished downloads
        $path = DOWNLOADS_DIR . '/' . $item;
        if (is_file($path)) {
            $fileBase  = pathinfo($item, PATHINFO_FILENAME);
            $titlePath = DOWNLOADS_DIR . '/' . $fileBase . '.title';
            $descPath  = DOWNLOADS_DIR . '/' . $fileBase . '.desc';
            $imgUrlPath = DOWNLOADS_DIR . '/' . $fileBase . '.imageurl';
            $jpgPath    = DOWNLOADS_DIR . '/' . $fileBase . '.jpg';
            $title     = is_file($titlePath) ? trim(file_get_contents($titlePath)) : str_replace('_', ' ', $fileBase);
            $desc      = is_file($descPath)  ? trim(file_get_contents($descPath))  : '';
            // .imageurl (new model) first, .jpg (old, local thumbnail) as fallback.
            $imageUrl  = is_file($imgUrlPath) ? trim(file_get_contents($imgUrlPath))
                       : (is_file($jpgPath)  ? DOWNLOADS_URL . '/' . rawurlencode($fileBase . '.jpg') : '');
            $files[] = [
                'name'     => $item,
                'title'    => $title,
                'desc'     => $desc,
                'image'    => $imageUrl,
                'size'     => filesize($path),
                'modified' => filemtime($path),
                'url'      => DOWNLOADS_URL . '/' . rawurlencode($item),
            ];
        }
    }
    usort($files, fn($a, $b) => $b['modified'] - $a['modified']);
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1_048_576) return round($bytes / 1_048_576, 1) . ' MB';
    if ($bytes >= 1_024)     return round($bytes / 1_024, 1) . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ljud-nedladdare</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎵</text></svg>">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            padding: 2rem 1rem;
        }

        .container {
            max-width: 720px;
            margin: 0 auto;
        }

        h1 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 0.25rem;
        }

        .subtitle {
            color: #94a3b8;
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }

        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .card h2 {
            font-size: 1rem;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }

        /* Rows may wrap so the button drops below the input on narrow screens */
        .input-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        input[type="url"],
        input[type="text"] {
            flex: 1;
            background: #0f172a;
            border: 1px solid #475569;
            border-radius: 8px;
            color: #f1f5f9;
            font-size: 0.95rem;
            padding: 0.65rem 0.9rem;
            outline: none;
            transition: border-color 0.2s;
        }

        input[type="url"]:focus,
        input[type="text"]:focus {
            border-color: #6366f1;
        }

        .upload-row input[type="file"] {
            flex: 1 1 100%;
            color: #cbd5e1;
            font-size: 0.9rem;
        }

        .input-row input[type="url"],
        .upload-row input[type="text"] { flex: 1 1 60%; }

        button {
            background: #6366f1;
            border: none;
            border-radius: 8px;
            color: #fff;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0.65rem 1.25rem;
            transition: background 0.2s;
            white-space: nowrap;
        }

        .btn-fetch {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
        }

        .btn-fetch svg { width: 1.1em; height: 1.1em; flex-shrink: 0; }

        button:hover { background: #4f46e5; }
        button:disabled { background: #475569; cursor: not-allowed; }

        .statusbox {
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            display: none;
        }

        .statusbox.loading {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: #1e3a5f;
            border: 1px solid #2563eb;
            color: #93c5fd;
        }

        .statusbox.success {
            display: block;
            background: #14532d;
            border: 1px solid #16a34a;
            color: #86efac;
        }

        .statusbox.error {
            display: block;
            background: #450a0a;
            border: 1px solid #dc2626;
            color: #fca5a5;
        }

        .spinner {
            width: 18px; height: 18px;
            border: 2px solid #2563eb;
            border-top-color: #93c5fd;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            flex-shrink: 0;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* Progress bar */
        .progress-wrap {
            flex: 1;
            min-width: 0;
        }

        .progress-label {
            display: block;
            font-size: 0.85rem;
            color: #93c5fd;
            margin-bottom: 0.4rem;
        }

        .progress-track {
            width: 100%;
            height: 6px;
            background: #0f172a;
            border-radius: 3px;
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background: #6366f1;
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .progress-track.indeterminate .progress-fill {
            width: 40%;
            position: absolute;
            left: 0;
            animation: indet 1.4s ease-in-out infinite;
        }

        @keyframes indet {
            0%   { left: -40%; }
            100% { left: 100%; }
        }

        /* File list */
        .file-list {
            list-style: none;
        }

        .file-item {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto auto;
            grid-template-areas:
                "thumb title dl del"
                "thumb desc  desc desc"
                "thumb meta  meta meta";
            column-gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #1e293b;
        }

        .file-item:last-child { border-bottom: none; }

        .file-icon {
            grid-area: thumb;
            align-self: start;
            font-size: 1.5rem;
            width: 56px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .file-thumb {
            grid-area: thumb;
            align-self: start;
            width: 56px;
            height: 56px;
            border-radius: 6px;
            object-fit: cover;
            background: #0f172a;
        }

        .file-title {
            grid-area: title;
            align-self: center;
            font-size: 0.95rem;
            font-weight: 600;
            color: #f1f5f9;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .file-desc {
            grid-area: desc;
            font-size: 0.82rem;
            color: #94a3b8;
            margin-top: 0.2rem;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .file-meta {
            grid-area: meta;
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.3rem;
        }

        .btn-download {
            grid-area: dl;
            align-self: center;
            background: #0f4c2a;
            border: 1px solid #16a34a;
            border-radius: 6px;
            color: #4ade80;
            font-size: 0.8rem;
            font-weight: 500;
            padding: 0.35rem 0.75rem;
            text-decoration: none;
            transition: background 0.2s;
        }

        .btn-download:hover { background: #166534; }

        .btn-delete {
            grid-area: del;
            align-self: center;
            background: none;
            border: 1px solid #475569;
            border-radius: 6px;
            color: #94a3b8;
            font-size: 0.8rem;
            padding: 0.35rem 0.6rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-delete:hover {
            background: #450a0a;
            border-color: #dc2626;
            color: #fca5a5;
        }

        .empty-state {
            text-align: center;
            color: #475569;
            padding: 2rem 0;
            font-size: 0.9rem;
        }

        /* On phones: collapse the download button to just its icon, let desc/meta span the full width */
        @media (max-width: 560px) {
            body { padding: 1.25rem 0.75rem; }

            .card { padding: 1rem; }

            .file-item {
                grid-template-areas:
                    "thumb title dl del"
                    "desc  desc  desc desc"
                    "meta  meta  meta meta";
                column-gap: 0.5rem;
            }

            .btn-download .btn-label,
            .btn-fetch .btn-label { display: none; }

            .btn-fetch { padding: 0.65rem 0.9rem; }

            .btn-download,
            .btn-delete {
                width: 2.25rem;
                height: 2.25rem;
                padding: 0;
                font-size: 1rem;
                line-height: 1;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
        }
</style>
</head>
<body>
<div class="container">
    <h1>Ljud-nedladdare</h1>
    <p class="subtitle">Ladda ner ljud från videor &mdash; lyssna offline</p>

    <!-- Download form -->
    <div class="card">
        <h2>Ladda ner nytt ljud</h2>
        <div class="input-row">
            <input type="url" id="urlInput" placeholder="https://www.100.se/program/..." autocomplete="off">
            <button id="downloadBtn" class="btn-fetch" onclick="startDownload()" aria-label="Ladda ner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 3v12M7 10l5 5 5-5M4 17v3h16v-3"/>
                </svg><span class="btn-label">Ladda ner</span>
            </button>
        </div>
        <div id="jobs"></div>
    </div>

    <!-- Upload form -->
    <div class="card">
        <h2>Ladda upp egen fil</h2>
        <div class="input-row upload-row">
            <input type="file" id="fileInput" accept="audio/*">
            <input type="text" id="uploadTitle" placeholder="Titel (valfritt)" autocomplete="off">
            <button id="uploadBtn" onclick="startUpload()">Ladda upp</button>
        </div>
        <div id="uploadStatus" class="statusbox"></div>
    </div>

    <!-- File list -->
    <div class="card">
        <h2>Nedladdade filer</h2>
        <?php if (empty($files)): ?>
            <p class="empty-state">Inga filer ännu &mdash; ladda ner något ovan!</p>
        <?php else: ?>
            <ul class="file-list" id="fileList">
                <?php foreach ($files as $f): ?>
                <li class="file-item" id="file-<?= md5($f['name']) ?>">
                    <?php if ($f['image'] !== ''): ?>
                        <img class="file-thumb" src="<?= htmlspecialchars($f['image']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="file-icon">🎵</div>
                    <?php endif; ?>
                    <div class="file-title" title="<?= htmlspecialchars($f['title']) ?>">
                        <?= htmlspecialchars($f['title']) ?>
                    </div>
                    <a class="btn-download" href="<?= htmlspecialchars($f['url']) ?>" download aria-label="Ladda ner">
                        <span class="btn-icon">&#8595;</span><span class="btn-label"> Ladda ner</span>
                    </a>
                    <button class="btn-delete" onclick="deleteFile('<?= htmlspecialchars(addslashes($f['name'])) ?>')" aria-label="Ta bort">
                        &#128465;
                    </button>
                    <?php if ($f['desc'] !== ''): ?>
                        <div class="file-desc" title="<?= htmlspecialchars($f['desc']) ?>">
                            <?= htmlspecialchars($f['desc']) ?>
                        </div>
                    <?php endif; ?>
                    <div class="file-meta">
                        <?= formatBytes($f['size']) ?> &middot;
                        <?= date('Y-m-d H:i', $f['modified']) ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<script>
// Each download gets its own status row in #jobs and its own poll timer, so
// several jobs can run side by side. The page reloads (to show the new files in
// the list) only once nothing a reload would interrupt is left: `busy` counts
// start requests and uploads in flight as well as jobs being polled.
let busy = 0;
let anySucceeded = false;
let anyFailed = false;
let reloadHint = null;
let validationRow = null;

async function startDownload() {
    const urlInput = document.getElementById('urlInput');
    const btn      = document.getElementById('downloadBtn');
    const url      = urlInput.value.trim();

    // Enter in the URL field bypasses the disabled button, so check it here too.
    if (btn.disabled) return;

    if (!url) {
        validationRow ??= addJobRow();
        showStatus('error', 'Ange en URL.', validationRow);
        return;
    }
    validationRow?.remove();
    validationRow = null;

    const row = addJobRow();

    // Disabled only while the job is being started (100.se scraping takes a few
    // seconds), so a double click cannot start the same download twice.
    btn.disabled = true;
    busy++;
    showStatus('loading', 'Startar nedladdning...', row);

    try {
        const resp = await fetch('download.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'url=' + encodeURIComponent(url)
        });
        const data = await resp.json();

        if (data.pending) {
            // The job runs in the background - start polling; it stays counted in `busy`
            urlInput.value = '';
            pollJob(data.job_id, row);
        } else {
            showStatus('error', '&#10007; Fel: ' + escHtml(data.error || 'Okänt fel.'), row);
            finishJob(false);
        }
    } catch (e) {
        showStatus('error', '&#10007; Nätverksfel: ' + e.message, row);
        finishJob(false);
    }
    btn.disabled = false;
}

function addJobRow() {
    const row = document.createElement('div');
    row.className = 'statusbox';
    document.getElementById('jobs').appendChild(row);
    return row;
}

function pollJob(jobId, row) {
    const timer = setInterval(async () => {
        try {
            const resp = await fetch('download.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=check&job_id=' + encodeURIComponent(jobId)
            });
            const data = await resp.json();

            if (data.done) {
                clearInterval(timer);
                showStatus('success', '&#10003; Klar! "' + escHtml(data.filename) + '"', row);
                finishJob(true);
            } else if (data.error) {
                clearInterval(timer);
                showStatus('error', '&#10007; Fel: ' + escHtml(data.error), row);
                finishJob(false);
            } else if (data.phase === 'download') {
                showProgress('Laddar ner... ' + (data.percent ?? 0) + '%', data.percent ?? 0, false, row);
            } else if (data.phase === 'convert') {
                showProgress('Konverterar till ljud...', 100, true, row);
            } else {
                showProgress('Startar...', 0, true, row);
            }
        } catch (e) {
            // Temporary network error - keep polling
        }
    }, 2000);
}

// Called once for every `busy++`: when a polled job, a failed start or an upload settles.
function finishJob(succeeded) {
    busy--;
    if (succeeded) anySucceeded = true; else anyFailed = true;
    reloadWhenIdle();
}

// Reload so new files show up in the list, but never while a request or job is
// still in flight, and never over a visible error message - then show a hint instead.
function reloadWhenIdle() {
    if (busy > 0 || !anySucceeded) return;
    if (anyFailed) {
        if (!reloadHint) {
            reloadHint = addJobRow();
            showStatus('success', '<a href="" style="color:inherit">Ladda om sidan</a> för att se de nya filerna i listan.', reloadHint);
        }
        return;
    }
    // A job started or failed during the delay re-runs the check instead.
    setTimeout(() => {
        if (busy === 0 && !anyFailed) location.reload(); else reloadWhenIdle();
    }, 1500);
}

async function deleteFile(filename) {
    if (!confirm('Ta bort "' + filename + '"?')) return;
    const resp = await fetch('download.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete&filename=' + encodeURIComponent(filename)
    });
    const data = await resp.json();
    if (data.success) {
        const el = document.getElementById('file-' + data.id);
        if (el) el.remove();
    } else {
        alert('Kunde inte ta bort: ' + data.error);
    }
}

function showStatus(type, msg, el) {
    el.className = 'statusbox ' + type;
    el.style.display = '';
    if (type === 'loading') {
        el.innerHTML = '<div class="spinner"></div><span>' + msg + '</span>';
    } else {
        el.innerHTML = msg;
    }
}

function showProgress(label, percent, indeterminate, el) {
    el.className = 'statusbox loading';
    el.style.display = 'flex';
    const trackClass = indeterminate ? 'progress-track indeterminate' : 'progress-track';
    el.innerHTML =
        '<div class="progress-wrap">' +
            '<span class="progress-label">' + escHtml(label) + '</span>' +
            '<div class="' + trackClass + '">' +
                '<div class="progress-fill" style="width:' + percent + '%"></div>' +
            '</div>' +
        '</div>';
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function startUpload() {
    const fileInput  = document.getElementById('fileInput');
    const titleInput = document.getElementById('uploadTitle');
    const btn        = document.getElementById('uploadBtn');
    const status     = document.getElementById('uploadStatus');

    if (!fileInput.files || fileInput.files.length === 0) {
        showStatus('error', 'Välj en fil att ladda upp.', status);
        return;
    }

    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('audio', fileInput.files[0]);
    fd.append('title', titleInput.value.trim());

    btn.disabled = true;
    busy++;
    showStatus('loading', 'Laddar upp...', status);

    let succeeded = false;
    try {
        // No Content-Type header - let the browser set the multipart boundary.
        const resp = await fetch('download.php', { method: 'POST', body: fd });
        const data = await resp.json();

        if (data.success) {
            showStatus('success', '&#10003; Uppladdad! "' + escHtml(data.filename) + '"', status);
            fileInput.value = '';
            titleInput.value = '';
            succeeded = true;
        } else {
            showStatus('error', '&#10007; Fel: ' + escHtml(data.error || 'Okänt fel.'), status);
        }
    } catch (e) {
        showStatus('error', '&#10007; Nätverksfel: ' + e.message, status);
    }
    btn.disabled = false;
    finishJob(succeeded);
}

document.getElementById('urlInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') startDownload();
});
</script>
</body>
</html>
