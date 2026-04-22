# 100procent – ljud-nedladdare med RSS-flöde

En webbapp för Synology NAS (Apache + PHP 8) som extraherar ljud från videolänkar via yt-dlp och exponerar resultatet både som webblista och som podcast-RSS.

## Arkitektur

```
index.php        # UI + PHP-sida som listar nedladdade filer och städar skräp
download.php     # POST-endpoint: start / check / delete
worker.php       # CLI-only bakgrundsjobb – yt-dlp + ffmpeg, streamar progress
rss.php          # RSS 2.0-flöde (iTunes-namespace) över samma filer
downloads/       # Ljudfiler + dolda jobb-statusfiler (skapas automatiskt)
```

Ingen databas, ingen jobs-mapp – all jobbstatus lever som **dolda filer** (dot-prefix) i `downloads/` och rensas automatiskt.

## Flödet

1. `download.php` POST utan action: validerar URL, (för 100.se: skrapar og:image för BunnyCDN-HLS + og:title), genererar `$jobId = bin2hex(random_bytes(8))`, startar `worker.php` via `nohup php worker.php <jobId> <url> &` och returnerar omedelbart `{job_id, pending: true}`.
2. `worker.php` (CLI) kör:
   - `popen()` på yt-dlp med `--newline` → läser progress-rader ("[download] 12.3% of …") löpande.
   - Varje ny procent-siffra skrivs throttlat (max var 2:a sek) till `.<jobId>.progress` som JSON via tmp+rename (atomiskt).
   - När yt-dlp är klar: sätter `phase=convert` i progress-filen, kör `ffmpeg -acodec copy` för ljudspåret.
   - Vid lyckat slut: städar upp och `touch .<jobId>.done`.
   - Vid fel: skriver meddelande till `.<jobId>.err`, tar bort progress-filen.
3. Frontend pollar `download.php?action=check` var 2:a sek. `check` läser `.err` → `.done` → `.progress` i tur och ordning och returnerar `{error}`, `{done, filename}` eller `{done: false, phase, percent}`.
4. När `.done` hittas: om `.<jobId>.title` finns byter `check` namn på m4a:n till säker titel, annars lämnas `<jobId>.m4a`.

## Progress-UI

Frontend växlar mellan **determinate bar** (procent under `phase=download`) och **indeterminate bar** (animation under `phase=convert`). Sentinel-filer i `downloads/` per jobb:

- `.<jobId>.progress` – JSON `{phase, percent, updated_at}`, atomiskt skriven av worker
- `.<jobId>.log` – stdout+stderr från yt-dlp och ffmpeg (för debug)
- `.<jobId>.err` – felmeddelande (bara om fel uppstår)
- `.<jobId>.done` – tom sentinel, sätts sist av worker
- `.<jobId>.title` – skrivs av download.php (om 100.se-scrape lyckades), används vid rename
- `.<jobId>.desc` – ingress från og:description, flyttas till `<titel>.desc` vid klart jobb
- `.<jobId>.imageurl` – URL till original-episodbilden (på BunnyCDN), flyttas till `<titel>.imageurl`. Klienten (webbläsare, poddspelare) hämtar bilden direkt — inga bildfiler lagras på servern.

## Viktiga beslut

- **Två-stegsnedladdning (mp4 → m4a via `-acodec copy`)** i stället för yt-dlp-inbyggd `-x --audio-format mp3` – undviker omkodning, bevarar källkvalitet, snabbare på NAS:ens svaga CPU.
- **CLI-worker via popen() i stället för shell-pipeline** – tidigare kedjades yt-dlp och ffmpeg med `&& ... ||` i en lång `nohup sh -c`. Det gjorde att vi inte kunde parsa yt-dlps progress. Nu: `worker.php` läser stdout rad för rad och skriver strukturerad status.
- **Throttling (2 sek) på progress-writes** – undviker att skriva 20+ gånger/sek vid snabba nedladdningar. Matchar frontendens pollintervall.
- **Atomisk JSON-write (tmp + rename)** – frontend kan aldrig läsa en halvskriven progress-fil.
- **Dolda jobbfiler i `downloads/`** (`.log`, `.err`, `.done`, `.progress`, `.title`) i stället för en `jobs/`-mapp – en enda rensningsrutin, inga extra .htaccess-blockeringar behövs (dolda filer filtreras bort i listningen).
- **Polling via `check` med `.done`-sentinel** – `.done` skapas *efter* ffmpeg, så klienten ser aldrig en halv fil.
- **Hängnings-detektion**: om progress-filens `updated_at` är äldre än 10 min rapporteras jobbet som misslyckat (worker har dött men progress-filen ligger kvar). Saknas progress-filen helt används `.log`-mtime som fallback-livstecken.
- **100.se-scraping** pekar direkt på `360p/video.m3u8`-subströmmen, inte master-playlisten – undviker att yt-dlp laddar ner högsta kvaliteten i onödan.
- **Filnamnsstrategi**: jobId används som temporärt filnamn under körning. Titeln saneras (`[^a-zA-Z0-9åäöÅÄÖ_-]` → `_`) och skrivs till `.<jobId>.title`; byte sker i `check` efter `.done`.
- **Städning vid varje index-laddning**: dolda filer > 24h, icke-ljudfiler > 1h (fångar yt-dlp-krascher som lämnat kvar mp4). Ljudfiler rensas aldrig automatiskt.
- **RSS kräver inget admingränssnitt** – `rss.php` bygger bas-URL från `$_SERVER` och listar samma filer som `index.php`.

## Sökvägar (Synology)

```php
define('YTDLP_PATH',   '/volume1/@yt-dlp/yt-dlp');                  // wrapper-skript
define('FFMPEG_PATH',  '/var/packages/ffmpeg7/target/bin/ffmpeg');  // SynoCommunity ffmpeg7
define('DOWNLOADS_DIR', __DIR__ . '/downloads');
define('DOWNLOADS_URL', 'downloads');
```

Verifiera med `which yt-dlp` och `/var/packages/ffmpeg7/target/bin/ffmpeg -version` på NAS:en. Sökvägarna är **duplicerade** mellan `index.php` och `download.php` – om de ändras måste båda filer uppdateras.

## Filformat

- **Accepterade i listning och RSS**: `mp3`, `m4a`, `ogg`, `opus`, `wav`
- **Producerade av appen**: endast `m4a` (ljudspår kopierat ur mp4)
- **MIME-mappning för RSS-enclosure** sker i `rss.php` via `match`.
- `itunes:duration` är en **grov uppskattning** från filstorlek (`bytes / 16000`) – inte exakt.

## Kommandon

```bash
# Testa yt-dlp manuellt
/volume1/@yt-dlp/yt-dlp --downloader native --no-playlist \
  --ffmpeg-location /var/packages/ffmpeg7/target/bin/ffmpeg \
  -o /tmp/test.mp4 "https://example.com/video"

# Kontrollera att http-användaren kan skriva
ls -la downloads/

# Titta på ett pågående jobb
tail -f downloads/.<jobId>.log
```

## Begränsningar

- `exec` / `shell_exec` måste vara tillåtet i PHP (kolla `disable_functions`).
- Apache-användaren `http` behöver skrivrättigheter till projektroten (downloads/ skapas runtime).
- **Ingen autentisering** – exponera inte publikt utan lösenordsskydd/VPN.
- `--no-playlist` satt i yt-dlp – playlists stöds inte.
- 100.se-scrapern är bräcklig: om deras HTML-struktur (og:image/og:title meta-tags med BunnyCDN-URL) ändras slutar detekteringen fungera och man måste mata in en direkt-URL.
- Två parallella jobb för samma URL blir två separata m4a-filer (olika jobId) – ingen dedup.
