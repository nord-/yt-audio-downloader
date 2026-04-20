# yt-audio-downloader

En webbapp för Synology NAS (Apache + PHP 8) som extraherar ljud (MP3) från videolänkar via yt-dlp.

## Arkitektur

```
index.html          # Frontend – formulär + polling-loop (vanilla JS)
api/start.php       # Tar emot URL, skapar jobb-JSON, startar worker.php i bakgrunden
api/worker.php      # CLI-only – kör yt-dlp, uppdaterar jobb-JSON med status/resultat
api/status.php      # Pollas av frontend var 2:a sekund, returnerar jobb-JSON
audio/              # MP3-filer (skapas automatiskt av worker.php)
jobs/               # Jobb-JSON-filer (skapas automatiskt, rensas efter 1h)
jobs/.htaccess      # Blockerar direktåtkomst
```

## Viktiga beslut

- **Asynkron** – worker.php körs via `exec(...&)` och PHP-requestet returnerar omedelbart med ett `job_id`
- **Jobbfiler** – status spåras i `jobs/<job_id>.json`, inte i databas eller session
- **Filnamnsstrategi** – output-template prefixas med `job_id` så worker kan hitta den producerade MP3:n med `glob()`
- **Rensning** – `status.php` rensar jobbfiler äldre än 1h vid varje anrop; MP3-filer rensas inte automatiskt

## Sökvägar (Synology-specifika)

```php
define('YT_DLP',      '/usr/local/bin/yt-dlp');
define('FFMPEG_PATH', '/usr/local/bin/ffmpeg');
define('AUDIO_DIR',   __DIR__ . '/../audio/');
define('JOBS_DIR',    __DIR__ . '/../jobs/');
define('WEB_AUDIO',   'audio/');   // Relativ URL från webbrot
```

Verifiera sökvägar med `which yt-dlp` och `which ffmpeg` på NAS:en.

## Kommandon

```bash
# Testa att yt-dlp fungerar manuellt på NAS:en
yt-dlp -x --audio-format mp3 --audio-quality 0 --no-playlist "https://example.com/video"

# Kontrollera att Apache-användaren (http) kan skriva till audio/ och jobs/
ls -la audio/ jobs/
```

## Begränsningar

- `shell_exec` / `exec` måste vara aktiverat i PHP (`disable_functions` i php.ini)
- Apache-användaren `http` måste ha skrivrättigheter till `audio/` och `jobs/`
- Ingen autentisering – exponera inte publikt utan lösenordsskydd
- Inga playlists (`--no-playlist` flagga satt)
