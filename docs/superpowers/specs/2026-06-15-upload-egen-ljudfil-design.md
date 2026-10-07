# Ladda upp egen ljudfil → RSS-flödet

**Datum:** 2026-06-15
**Status:** Godkänd, klar för implementation

## Mål

Lägga till möjligheten att ladda upp en egen ljudfil direkt i webbappen, så att
den hamnar i både fil-listan (`index.php`) och podcast-RSS-flödet (`rss.php`) —
utan video-länk, yt-dlp eller bakgrundsjobb.

## Nyckelinsikt

Både `index.php` och `rss.php` listar enbart de ljudfiler (`mp3, m4a, ogg, opus,
wav`) som ligger i `downloads/`, plus eventuella sidecars (`.title`, `.desc`,
`.imageurl`). En uppladdad fil som landar i `downloads/` dyker därför upp
automatiskt i båda — **ingen ändring krävs i `index.php` eller `rss.php`**.
Uppladdningen är synkron och utgör hela jobbet: ingen `worker.php`, ingen
polling, inga progress-/done-sentinels.

## Arkitektur / endpoint

Ny gren `action=upload` i `download.php`. Matchar det befintliga mönstret
(`start / check / delete`) och undviker en ny fil med duplicerade `define`-
sökvägar.

## Serverlogik (`action=upload`)

1. Läs `$_FILES['audio']`. Verifiera `error === UPLOAD_ERR_OK`.
   - `UPLOAD_ERR_INI_SIZE` / `UPLOAD_ERR_FORM_SIZE` → felmeddelande om att filen
     är för stor (se begränsning nedan).
   - Tom/saknad fil → "Ingen fil vald."
2. **Tillåtna format**: `mp3, m4a, ogg, opus, wav` (samma whitelist som
   listningen/RSS). Validera *både*:
   - filändelse mot whitelist, och
   - MIME via `finfo_file()` (lita inte blint på klientens filnamn).
3. **Filnamn**: utgå från originalfilens basnamn. Sanera med samma regex som
   titlar: `preg_replace('/[^a-zA-Z0-9åäöÅÄÖ._-]/', '_', ...)` följt av
   kollaps av upprepade `_` och trim av `_.`. Behåll den validerade
   filändelsen. Vid kollision i `downloads/` lägg på `-1`, `-2`, … tills namnet
   är ledigt, så inget skrivs över.
4. **Titel (valfri)**: om titelfältet är ifyllt → skriv `<basnamn>.title`-sidecar
   (rå text, precis som scrape-flödet). Annars ingen sidecar → titel härleds från
   filnamnet (underscore → mellanslag) av `index.php`/`rss.php`.
5. Flytta filen med `move_uploaded_file()` till `downloads/`.
6. Returnera JSON `{success: true, filename: "<namn>.<ext>"}` eller
   `{success: false, error: "..."}`.

## UI (eget kort)

Nytt kort **"Ladda upp egen fil"** under nedladdnings-kortet i `index.php`:

- `<input type="file" id="fileInput" accept="audio/*">`
- valfritt titel-`<input type="text" id="uploadTitle">`
- "Ladda upp"-knapp

JS bygger `FormData` (`action=upload`, `audio`, `title`) och skickar via `fetch`.
Visar spinner via befintliga `#status`-elementet under uppladdning. Vid
`success` → kort statusmeddelande + `location.reload()` så filen syns i listan.
Vid fel → felmeddelande i samma statusruta. Återanvänder befintliga
`.card` / `button` / `#status`-stilar — ingen ny CSS-arkitektur.

## Viktig begränsning: PHP:s uppladdningstak

Synology/PHP har ofta `upload_max_filesize` och `post_max_size` satt lågt
(t.ex. 2M/8M), medan ett poddavsnitt lätt är 30–100 MB. Funktionen fungerar inte
för stora filer förrän dessa höjs (via `.htaccess`, `.user.ini` eller
php-konfig på NAS:en). UI:t ska ge ett begripligt fel i stället för en tyst
krasch när taket överskrids. Själva NAS-konfigurationen ligger utanför den här
ändringen.

## Avgränsningar (YAGNI)

- Ingen beskrivning eller bild-URL vid uppladdning (kan läggas till senare med
  samma sidecar-mönster).
- Ingen uppladdnings-progressbar (synkron POST + reload räcker).
- Ingen dedup mot redan nedladdat innehåll — kollisionssuffix räcker.
