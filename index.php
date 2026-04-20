<?php
// ──────────────────────────────────────────────
//  Konfiguration – anpassa efter din Synology
// ──────────────────────────────────────────────
define('YTDLP_PATH',   '/volume1/@yt-dlp/yt-dlp');   // wrapper-skript på volymen
define('FFMPEG_PATH',  '/var/packages/ffmpeg7/target/bin/ffmpeg');    // SynoCommunity ffmpeg7
define('DOWNLOADS_DIR', __DIR__ . '/downloads');
define('DOWNLOADS_URL', 'downloads');               // relativ URL till mappen

// Skapa downloads-mappen om den saknas
if (!is_dir(DOWNLOADS_DIR)) {
    mkdir(DOWNLOADS_DIR, 0755, true);
}

// Städa bort gamla jobb-filer (dolda filer äldre än 24h)
foreach (glob(DOWNLOADS_DIR . '/.*') as $hidden) {
    if (is_file($hidden) && (time() - filemtime($hidden)) > 86400) {
        @unlink($hidden);
    }
}
// Städa bort temp-videofiler som yt-dlp kan ha lämnat kvar vid krasch (äldre än 1h)
foreach (glob(DOWNLOADS_DIR . '/*') as $f) {
    if (!is_file($f)) continue;
    if (preg_match('/\.(mp3|m4a|ogg|opus|wav)$/i', $f)) continue;  // behåll färdiga ljudfiler
    if ((time() - filemtime($f)) > 3600) {
        @unlink($f);
    }
}

// Hämta lista med nedladdade filer
$files = [];
if (is_dir(DOWNLOADS_DIR)) {
    foreach (scandir(DOWNLOADS_DIR) as $item) {
        // Visa bara färdiga ljudfiler — dölj dolda filer, temporärfiler och skräp
        if ($item[0] === '.') continue;                          // dolda filer (.log, .title, .htaccess osv)
        if (!preg_match('/\.(mp3|m4a|ogg|opus|wav)$/i', $item)) continue;  // bara ljudformat
        if (str_ends_with($item, '.part')) continue;             // ofärdiga nedladdningar
        $path = DOWNLOADS_DIR . '/' . $item;
        if (is_file($path)) {
            $files[] = [
                'name'     => $item,
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

        .input-row {
            display: flex;
            gap: 0.75rem;
        }

        input[type="url"] {
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

        input[type="url"]:focus {
            border-color: #6366f1;
        }

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

        button:hover { background: #4f46e5; }
        button:disabled { background: #475569; cursor: not-allowed; }

        #status {
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            display: none;
        }

        #status.loading {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: #1e3a5f;
            border: 1px solid #2563eb;
            color: #93c5fd;
        }

        #status.success {
            display: block;
            background: #14532d;
            border: 1px solid #16a34a;
            color: #86efac;
        }

        #status.error {
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
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #1e293b;
        }

        .file-item:last-child { border-bottom: none; }

        .file-icon {
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .file-info {
            flex: 1;
            min-width: 0;
        }

        .file-name {
            font-size: 0.9rem;
            font-weight: 500;
            color: #e2e8f0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-meta {
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 0.15rem;
        }

        .btn-download {
            background: #0f4c2a;
            border: 1px solid #16a34a;
            border-radius: 6px;
            color: #4ade80;
            font-size: 0.8rem;
            font-weight: 500;
            padding: 0.35rem 0.75rem;
            text-decoration: none;
            flex-shrink: 0;
            transition: background 0.2s;
        }

        .btn-download:hover { background: #166534; }

        .btn-delete {
            background: none;
            border: 1px solid #475569;
            border-radius: 6px;
            color: #94a3b8;
            font-size: 0.8rem;
            padding: 0.35rem 0.6rem;
            cursor: pointer;
            flex-shrink: 0;
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
            <button id="downloadBtn" onclick="startDownload()">Ladda ner</button>
        </div>
        <div id="status"></div>
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
                    <div class="file-icon">🎵</div>
                    <div class="file-info">
                        <div class="file-name" title="<?= htmlspecialchars($f['name']) ?>">
                            <?= htmlspecialchars($f['name']) ?>
                        </div>
                        <div class="file-meta">
                            <?= formatBytes($f['size']) ?> &middot;
                            <?= date('Y-m-d H:i', $f['modified']) ?>
                        </div>
                    </div>
                    <a class="btn-download" href="<?= htmlspecialchars($f['url']) ?>" download>
                        &#8595; Ladda ner
                    </a>
                    <button class="btn-delete" onclick="deleteFile('<?= htmlspecialchars(addslashes($f['name'])) ?>')">
                        &#128465;
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<script>
let pollTimer = null;

async function startDownload() {
    const urlInput = document.getElementById('urlInput');
    const btn      = document.getElementById('downloadBtn');
    const url      = urlInput.value.trim();

    if (!url) { showStatus('error', 'Ange en URL.'); return; }

    btn.disabled = true;
    showStatus('loading', 'Startar nedladdning...');

    try {
        const resp = await fetch('download.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'url=' + encodeURIComponent(url)
        });
        const data = await resp.json();

        if (data.pending) {
            // Jobbet körs i bakgrunden — starta polling
            urlInput.value = '';
            pollJob(data.job_id);
        } else if (data.success) {
            showStatus('success', '&#10003; Klar! "' + escHtml(data.filename) + '"');
            urlInput.value = '';
            btn.disabled = false;
            setTimeout(() => location.reload(), 1500);
        } else {
            showStatus('error', '&#10007; Fel: ' + escHtml(data.error));
            btn.disabled = false;
        }
    } catch (e) {
        showStatus('error', '&#10007; Nätverksfel: ' + e.message);
        btn.disabled = false;
    }
}

function pollJob(jobId) {
    clearInterval(pollTimer);

    pollTimer = setInterval(async () => {
        try {
            const resp = await fetch('download.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=check&job_id=' + encodeURIComponent(jobId)
            });
            const data = await resp.json();

            if (data.done) {
                clearInterval(pollTimer);
                document.getElementById('downloadBtn').disabled = false;
                showStatus('success', '&#10003; Klar! "' + escHtml(data.filename) + '"');
                setTimeout(() => location.reload(), 1500);
            } else if (data.error) {
                clearInterval(pollTimer);
                document.getElementById('downloadBtn').disabled = false;
                showStatus('error', '&#10007; Fel: ' + escHtml(data.error));
            } else if (data.phase === 'download') {
                showProgress('Laddar ner... ' + (data.percent ?? 0) + '%', data.percent ?? 0, false);
            } else if (data.phase === 'convert') {
                showProgress('Konverterar till ljud...', 100, true);
            } else {
                showProgress('Startar...', 0, true);
            }
        } catch (e) {
            // Tillfälligt nätverksfel — fortsätt polla
        }
    }, 2000);
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

function showStatus(type, msg) {
    const el = document.getElementById('status');
    el.className = type;
    el.style.display = '';
    if (type === 'loading') {
        el.innerHTML = '<div class="spinner"></div><span>' + msg + '</span>';
    } else {
        el.innerHTML = msg;
    }
}

function showProgress(label, percent, indeterminate) {
    const el = document.getElementById('status');
    el.className = 'loading';
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

document.getElementById('urlInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') startDownload();
});
</script>
</body>
</html>
