# yt-audio-downloader

En enkel webbsida som extraherar ljud (MP3) från videolänkar via yt-dlp.  
Byggd för Synology NAS med Apache + PHP 8.

## Struktur

```
yt-audio-downloader/
├── index.html        # Frontend
├── audio/            # Skapas automatiskt – här sparas MP3-filerna
├── jobs/             # Skapas automatiskt – jobbstatusens JSON-filer
│   └── .htaccess     # Blockerar direktåtkomst till jobs/
└── api/
    ├── start.php     # Startar ett nedladdningsjobb
    ├── status.php    # Pollas av frontend för statusuppdateringar
    └── worker.php    # Bakgrundsprocess som kör yt-dlp
```

## Installation på Synology

### 1. Installera beroenden

**yt-dlp** (via pip):
```bash
pip3 install yt-dlp
```
Eller ladda ner binären direkt:
```bash
curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
chmod +x /usr/local/bin/yt-dlp
```

**ffmpeg:**  
Installera via Synology Package Center (sök på "ffmpeg") eller via SynoCommunity-repot.  
Kontrollera sökvägen efteråt: `which ffmpeg`

### 2. Justera sökvägar i start.php / worker.php

Om yt-dlp eller ffmpeg ligger på annan sökväg än `/usr/local/bin/`, uppdatera konstanterna:

```php
define('YT_DLP',      '/usr/local/bin/yt-dlp');
define('FFMPEG_PATH', '/usr/local/bin/ffmpeg');
```

### 3. Placera filerna

Kopiera projektet till din web-rot, t.ex.:
```
/volume1/web/yt-audio-downloader/
```

### 4. Rättigheter

Apache körs som användaren `http`. Se till att den användaren har skrivrättigheter till `audio/` och `jobs/`:
```bash
chmod 775 audio jobs
chown http:http audio jobs
```

### 5. PHP – tillåt exec/shell_exec

Kontrollera att `exec` och `shell_exec` inte är med i `disable_functions` i din `php.ini`.  
På Synology finns php.ini typiskt under:
```
/etc/php/php.ini
```

### 6. Öppna i webbläsaren

```
http://<nas-ip>/yt-audio-downloader/
```

## Rensa gamla filer

MP3-filer rensas inte automatiskt. Sätt upp ett cron-jobb om du vill ta bort gamla filer:
```bash
# Ta bort MP3-filer äldre än 7 dagar
0 3 * * * find /volume1/web/yt-audio-downloader/audio/ -name "*.mp3" -mtime +7 -delete
```
