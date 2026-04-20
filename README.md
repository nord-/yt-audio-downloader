# 100procent

Liten webbapp för Synology NAS som laddar ner ljud från videolänkar (bl.a. 100.se) och publicerar dem som en privat poddradio-RSS.

- Klistra in en video-URL → MP4 laddas ner, ljudspåret extraheras till M4A (utan omkodning).
- Filerna listas i ett enkelt webbgränssnitt.
- `rss.php` exponerar samma filer som ett podcast-flöde du kan prenumerera på i valfri poddapp.

## Krav

- Apache (eller annan PHP-server) med **PHP 8+**.
- `exec` och `shell_exec` aktiverade (ej i `disable_functions`).
- `yt-dlp` installerat på NAS:en.
- `ffmpeg` installerat (här: SynoCommunity-paketet **ffmpeg7**).
- Skrivrättigheter för webbserver-användaren i projektmappen.

## Installation på Synology

1. Lägg filerna i en webbmapp under t.ex. `/volume1/web/100/`.
2. Installera yt-dlp (wrapper-skript som auto-uppdaterar rekommenderas), default-sökväg:
   ```
   /volume1/@yt-dlp/yt-dlp
   ```
3. Installera **ffmpeg7** från SynoCommunity:
   ```
   /var/packages/ffmpeg7/target/bin/ffmpeg
   ```
4. Justera sökvägarna överst i `index.php` och `download.php` om dina avviker:
   ```php
   define('YTDLP_PATH',  '/volume1/@yt-dlp/yt-dlp');
   define('FFMPEG_PATH', '/var/packages/ffmpeg7/target/bin/ffmpeg');
   ```
5. Säkerställ att `http`-användaren kan skriva:
   ```
   chown -R http:http /volume1/web/100
   ```
6. Besök `https://<ditt-nas>/100/` i webbläsaren.

## Användning

- **Ladda ner**: klistra in URL, klicka *Ladda ner*. Status uppdateras automatiskt; filen dyker upp i listan när konverteringen är klar.
- **Ta bort**: soptunne-knappen bredvid varje fil.
- **Prenumerera**: lägg till `https://<ditt-nas>/100/rss.php` i din poddapp.

## Hur det fungerar

Kort: `download.php` returnerar direkt med ett jobb-ID och startar yt-dlp + ffmpeg i bakgrunden via `nohup`. Frontend pollar `download.php?action=check` tills en `.done`-sentinel dyker upp. För 100.se-länkar skrapas sidans `og:image` för att hitta direkt-URL:en till BunnyCDN-HLS-strömmen. Allt jobbstate ligger som dolda filer i `downloads/`.

Se [`CLAUDE.md`](CLAUDE.md) för den detaljerade arkitekturen.

## Säkerhet

Appen har **ingen inbyggd autentisering**. Exponera den inte publikt utan något av följande:

- Apache Basic Auth / Synology reverse proxy med inloggning
- VPN / endast inom lokalnätet
- IP-whitelist

## Begränsningar

- Playlists stöds inte (`--no-playlist`).
- 100.se-skrapningen kan gå sönder om deras HTML ändras – då får du mata in direktlänken till strömmen manuellt.
- Ljudfiler rensas aldrig automatiskt (dolda jobb-filer städas dock).
