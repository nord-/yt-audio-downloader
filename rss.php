<?php
// ── RSS-flöde för nedladdade MP3-filer ────────
// Prenumerera i valfri poddapp med URL:en till den här filen.
// ──────────────────────────────────────────────
define('DOWNLOADS_DIR', __DIR__ . '/downloads');

// Bygg bas-URL automatiskt utifrån serverns headers
$scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$base        = $scheme . '://' . $host . $scriptDir;   // t.ex. https://synstation.nord.cc/100
$feedUrl     = $base . '/rss.php';
$downloadsBase = $base . '/downloads';

// Hämta MP3-filer sorterade nyast först
$files = [];
if (is_dir(DOWNLOADS_DIR)) {
    foreach (scandir(DOWNLOADS_DIR) as $item) {
        if ($item[0] === '.') continue;
        if (!preg_match('/\.(mp3|m4a|ogg|opus|wav)$/i', $item)) continue;
        $path = DOWNLOADS_DIR . '/' . $item;
        if (!is_file($path)) continue;
        $ext       = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        $fileBase  = pathinfo($item, PATHINFO_FILENAME);
        $titlePath = DOWNLOADS_DIR . '/' . $fileBase . '.title';
        $descPath  = DOWNLOADS_DIR . '/' . $fileBase . '.desc';
        $imgPath   = DOWNLOADS_DIR . '/' . $fileBase . '.imageurl';
        // Rå titel från sidecar bevarar frågetecken, punkter och tecken som inte tål filsystem.
        // Fallback: härled titel från filnamnet (underscore → space) för filer utan sidecar.
        $title       = is_file($titlePath) ? trim(file_get_contents($titlePath)) : str_replace('_', ' ', $fileBase);
        $description = is_file($descPath)  ? trim(file_get_contents($descPath))  : '';
        $imageUrl    = is_file($imgPath)   ? trim(file_get_contents($imgPath))   : '';
        $files[] = [
            'name'     => $item,
            'title'    => $title,
            'size'     => filesize($path),
            'modified' => filemtime($path),
            'url'      => $downloadsBase . '/' . rawurlencode($item),
            'desc'     => $description,
            'image'    => $imageUrl,
            'mime'     => match($ext) {
                'mp3'  => 'audio/mpeg',
                'm4a'  => 'audio/mp4',
                'ogg'  => 'audio/ogg',
                'opus' => 'audio/ogg; codecs=opus',
                'wav'  => 'audio/wav',
                default => 'audio/mpeg',
            },
        ];
    }
    usort($files, fn($a, $b) => $b['modified'] - $a['modified']);
}

$lastBuild = $files ? $files[0]['modified'] : time();

function x(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function rfc(int $ts): string {
    return date('D, d M Y H:i:s O', $ts);
}

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: max-age=300');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"
     xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
     xmlns:atom="http://www.w3.org/2005/Atom">
<?php $coverUrl = 'https://raw.githubusercontent.com/nord-/yt-audio-downloader/master/cover.png'; ?>
  <channel>
    <title>Mina nedladdningar</title>
    <link><?= x($base) ?></link>
    <description>Nedladdade ljudfiler</description>
    <language>sv</language>
    <lastBuildDate><?= rfc($lastBuild) ?></lastBuildDate>
    <atom:link href="<?= x($feedUrl) ?>" rel="self" type="application/rss+xml"/>
    <itunes:image href="<?= x($coverUrl) ?>"/>
    <image>
      <url><?= x($coverUrl) ?></url>
      <title>Mina nedladdningar</title>
      <link><?= x($base) ?></link>
    </image>
    <itunes:explicit>no</itunes:explicit>

<?php foreach ($files as $f): ?>
    <item>
      <title><?= x($f['title']) ?></title>
      <guid isPermaLink="false"><?= x($f['url']) ?></guid>
      <pubDate><?= rfc($f['modified']) ?></pubDate>
<?php if ($f['desc'] !== ''): ?>
      <description><?= x($f['desc']) ?></description>
      <itunes:summary><?= x($f['desc']) ?></itunes:summary>
<?php endif; ?>
<?php if ($f['image'] !== ''): ?>
      <itunes:image href="<?= x($f['image']) ?>"/>
      <image>
        <url><?= x($f['image']) ?></url>
        <title><?= x($f['title']) ?></title>
        <link><?= x($f['url']) ?></link>
      </image>
<?php endif; ?>
      <enclosure url="<?= x($f['url']) ?>"
                 length="<?= $f['size'] ?>"
                 type="<?= x($f['mime']) ?>"/>
      <itunes:duration><?= gmdate('H:i:s', (int)($f['size'] / 16000)) ?></itunes:duration>
    </item>
<?php endforeach; ?>

  </channel>
</rss>
