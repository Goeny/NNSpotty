# Installatie (ontwikkelomgeving)

## Vereisten
- PHP 8.2 of hoger
- Composer 2.x
- MySQL/MariaDB en PHP-extensies `pdo_mysql`, `SimpleXML`, `dom`, `iconv`, `zlib`, `curl`, `openssl`

## Stappen
1. Installeer dependencies:
   ```bash
   composer install
   ```
   Als `composer.lock` nog ontbreekt (eerste run), gebruik:
   ```bash
   composer update --no-dev --optimize-autoloader
   ```
   en daarna:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
2. Maak een lokale configuratie op basis van:
   - [`.env.example`](../.env.example)
   - sla op als `.env` in de projectroot.
   - `.env` bevat basis app- en DB instellingen; Usenet/retrieve instellingen beheer je in admin.
3. Voer database migraties uit:
   ```bash
   php bin/console db migrate
   ```
4. Start de applicatie:
   ```bash
   php -S localhost:8080 -t public
   ```
5. Open:
   - `http://localhost:8080` (verwijst naar het overzicht, via login indien nodig)
   - `http://localhost:8080/health`
   - `http://localhost:8080/login`
   - `http://localhost:8080/admin` (admin login vereist)
   - `http://localhost:8080/preferences` (login vereist)
6. Stel via `/admin` je Usenet/retrieve instellingen in (server, login, retentie, optionele begindatum, Spotnet-groepen, max artikelen per incrementele run).
   De meest recente grens van begindatum en retentie geldt. Retentie `0` betekent onbeperkt en schakelt ook het automatisch verwijderen van oude spots uit.
   Gebruik standaard `spots:free.pt,comments:free.usenet`; leeg gebruikt dezelfde defaults.
7. Haal echte spots op met `php bin/console retrieve --days=30 --verbose` en open `/spots`.
   Dit begint bij de hoogste artikelnummers en stopt per groep na drie opeenvolgende niet-lege batches met uitsluitend geldige datums voor de datumgrens. Een recente/ongeldige regel of lege batch onderbreekt de reeks. `--full`/`--all` gebruikt dezelfde stopregel en retentiegrens. Artikelnummer en datum zijn niet gegarandeerd gesorteerd: recente berichten die dieper dan deze stop liggen kunnen worden gemist. Zonder datumgrens blijft de volledige scan behouden. Iedere geslaagde retrieve ruimt alle verlopen spots op in transactiebatches van maximaal 1000, zonder maximum per run.

### Optionele muziekmetadata
Na `db migrate` staan de MusicBrainz-functies standaard uit. Schakel globaal in via `/admin#music-metadata` en persoonlijk via `/preferences#music-metadata`. Geen API-key nodig; APP_URL moet je publieke contact/project-URL zijn en wordt meegestuurd in de User-Agent. Detail haalt gericht op, preview gebruikt alleen cache. Aanvraaglimiet, privacy, licenties en grenzen staan in de [README](../README.md#muziekmetadata-musicbrainz-en-cover-art-archive). Geen aparte databasedump, worker of retrieve-backfill nodig.

## Git en dependencies
- Een verse checkout bevat geen `vendor/` of `.env`. Installeer dependencies vanuit de gevolgde `composer.lock` met Composer; maak je eigen `.env` op basis van `.env.example`.
- Houd `composer.json` en `composer.lock` in Git. Commit geen authenticatiegegevens (`auth.json`), lokale environmentconfiguratie, logs, databasedumps of runtimebestanden.
- Gedeelde `.vscode`-taken, tests, documentatie en screenshots horen wel bij de repository.
- Upgrade je een bestaande installatie die `.env` en `vendor/` nog volgde, volg dan eerst de [eenmalige backup-/restoreprocedure](./linux-server-testing.md#git-opruimen-inhoudsinstellingen-en-afbeeldingsvergroting). Een gewone pull kan de eerder gevolgde bestanden verwijderen; bewaar je serverconfiguratie buiten de repository.
- Negeren maakt oude Git-commits niet schoon. Roteer echte credentials die ooit zijn vastgelegd; geschiedenis herschrijven is geen onderdeel van deze upgrade.

## Op te halen inhoud en afbeeldingvergroting
- Admin bevat **Op te halen inhoud**: Usenet-reacties indexeren, reacties tonen/lokaal plaatsen, afbeeldingen ophalen/tonen. Standaard staat alles aan. Retentie en de artikellimiet blijven onder Usenet instellingen.
- Reacties indexeren uit slaat de groep/cursor over; tonen uit verbergt ook al opgeslagen reacties. Herinschakelen verwijdert of reset geen data; gebruik zo nodig `retrieve --days=30` voor beschikbare historie.
- Afbeeldingen uit voorkomt requests voor thumbnails, covers en hero, en blokkeert de image-route ook voor cachehits. Afbeeldingen worden nooit tijdens de gewone retrieve massaal gedownload.
- Herlaad de assets `/assets/spotty.css`, `/assets/spots.js`, `/assets/presentation.js`, `/assets/downloader.js` en `/assets/metadata.js` zonder oude cache. De cover in preview/detail opent een lightbox met toetsenbordbediening en foutfeedback.
- SABnzbd vereist PHP-extensie `curl` en migratie `20261018_0012_sabnzbd`. Configureer als admin de centrale verbinding en categorietoewijzing onder `/admin#downloads`; gebruikers stellen een eigen publieke host in onder `/preferences#downloads`. Alleen admins mogen interne hosts gebruiken en de centrale wachtrij zien. Zie [SABnzbd serverchecks](./linux-server-testing.md#sabnzbd-categorietoewijzing-en-downloadpaneel).
- Spotnet-omschrijvingen met `[br]` en gangbare bracketopmaak worden direct juist weergegeven. Een nieuwe retrieve of datamigratie is hiervoor niet nodig.

## TheTVDB installeren of inschakelen
- Maak een beveiligde databasebackup, pauzeer webverkeer/retrieval en voer `php bin/console db migrate` uit. Migratie `20261018_0013_tvdb` voegt vier metadata-tabellen toe zonder spots of cursors te wijzigen. Geen nieuwe retrieval nodig.
- Controleer PHP-extensie `curl`, werkende TLS/CA-validatie en uitgaand HTTPS naar `api4.thetvdb.com`. De browser moet afbeeldingen van `artworks.thetvdb.com`/TheTVDB kunnen laden. Redirects en onveilige TLS worden niet toegestaan.
- Admin `/admin#metadata`: centrale API V4-key, optionele abonnee-PIN, Nederlands-met-Engelse-fallback of Engels, globale schakelaar en test van opgeslagen toegang. Controleer je TVDB-licentie; keys zijn niet automatisch voor elk gebruik toegestaan. Lege key/PIN behouden eerdere waarden; PIN verwijderen heeft een aparte keuze.
- Iedere gebruiker, ook de admin, kiest vervolgens zelf onder `/preferences#metadata` voor metadata. Beide schakelaars staan initieel uit; globaal uit blokkeert iedereen zonder persoonlijke keuzes te wissen.
- Serveer `/assets/metadata.js` als JavaScript en herlaad met Ctrl+F5. Volledige details verrijken op aanvraag; overzicht/preview/retrieval blijven provideronafhankelijk. Originele informatie en foutmeldingen blijven beschikbaar.
- Publiceer ook de aangeleverde `/assets/images/tvdb-dark.png` en `/assets/images/tvdb-light.png`: lokale thema-afhankelijke attributie bij getoonde data. De compacte **TVDB link** in de hero opent status en admin-only matchbeheer in een slide-inpaneel. Deze presentatie-update vereist geen extra migratie of retrieval.
- Afbeeldingsbeleid geldt ook voor TVDB-posters, fanart en cast. Bescherm de database en backups: key, PIN en bearer token moeten voor uitgaande authenticatie herstelbaar blijven. Ze staan niet in de HTML, auditdetails of `.env`.
- Zie de [TVDB-gebruikersuitleg](../README.md#thetvdb-films-series-en-afleveringen) en [Linux-productieacceptatie](./linux-server-testing.md#thetvdb-metadata-posters-en-fanart).

## Upgrade vanaf de generieke headerindex
- Voer `php bin/console db migrate` uit en controleer de Spotnet-groepen in admin.
- De oude exacte standaardinstelling met `comments:free.ptd` wordt gemigreerd naar `comments:free.usenet`.
- Aangepaste groepsinstellingen worden niet overschreven; vervang generieke `category:group` configuratie door de rollen `spots` en `comments`.
- Voer `php bin/console retrieve --days=30 --verbose` uit. Oude headers blijven bewaard, maar worden niet als spots weergegeven.
- `db reindex-spots` is vervangen door opnieuw ophalen van de echte Spotnet-inhoud.

## Opslag
- Alle runtime data wordt opgeslagen in MySQL/MariaDB tabellen.
- Zorg dat ingestelde DB user rechten heeft op create/read/update/delete.

## Upgrade categorieen en zichtbaarheid
- Pauzeer webverkeer en retrieve-jobs, maak een databasebackup en voer `php bin/console db migrate` uit.
- Migraties 0007/0008 voegen classificatie en facetten toe en delen alle bestaande spots opnieuw in; opnieuw ophalen is niet nodig. Bij een onderbroken backfill kan `db migrate` worden herhaald.
- Erotica blijft standaard verborgen. Zet dit indien gewenst globaal aan in admin; daarna moet iedere gebruiker zelf opt-innen onder voorkeuren.
- Controleer `/assets/spotty.css`: deze moet met `Content-Type: text/css` worden geserveerd. Bij Apache met projectroot als documentroot is de aangepaste root `.htaccess` nodig; met `public/` als documentroot wordt het bestand direct geserveerd.

## Upgrade persoonlijke nieuwtellers en Plex-accenten
- Pauzeer webverkeer en retrieve-jobs, maak een beveiligde databasebackup, installeer dependencies en draai `php bin/console db migrate` voor `20261018_0014_browse_visits`.
- Deze migratie voegt persoonlijke browseactiviteit toe en verandert de importtijd naar microseconden met index. Op een grote spotstabel kan de ALTER tijd kosten; plan een onderhoudsvenster.
- Geen nieuwe retrieve of providerkey nodig. Eerste bezoek na upgrade legt een beginpunt vast; bestaande historie verschijnt niet als nieuw. Volgende browsersessies tellen nieuwe imports sinds de laatste browseactiviteit, met een vaste grens tijdens dezelfde sessie.
- Herlaad de stylesheet met Ctrl+F5. Knoppen/badges/filterlabels gebruiken oranje/goud in donker/licht; de overige layout blijft gelijk.
- Zie [Linux-validatie](./linux-server-testing.md#persoonlijke-nieuwtellers-en-plex-accenten) voor servercommando's en een controle met twee sessies.

## Upgrade interactief overzicht en kolominstellingen
- Voer `composer install --no-dev --optimize-autoloader` en `php bin/console db migrate` uit. Migratie `20261018_0009_user_spot_columns` voegt persoonlijke kolomkeuzes toe, zonder bestaande inhoud te veranderen of nieuwe retrieval.
- Controleer dat `/assets/spots.js` als JavaScript en `/assets/spotty.css` als CSS worden geserveerd; herlaad zonder oude assetcache.
- Stel in admin kolomstandaarden per hoofdgroep in. Gebruikers volgen deze tot ze een eigen selectie opslaan. **Globale standaard gebruiken** in voorkeuren verwijdert die override na opslaan.
- `items_per_page` blijft als bestaande databasevoorkeur behouden, maar betekent nu het aantal resultaten per laadbatch.
- Zie [Linux server testen](./linux-server-testing.md#interactief-overzicht-filters-en-kolommen) voor deployment, Composer-autoloadconflicten en functionele controles.

## Consolecommando's
- `php bin/console db status`
- `php bin/console db health`
- `php bin/console db cleanup --days=90`
- `php bin/console user list`
- `php bin/console user create <username> <password> --admin=1`
- `php bin/console retrieve --since-last`
- `php bin/console retrieve --days=30`
- `php bin/console retrieve --all`
- `php bin/console retrieve --days=30 --verbose`

## Security baseline
- POST formulieren zijn CSRF-beveiligd.
- Login heeft rate limiting (lockout na 5 mislukte pogingen binnen 15 minuten).

## NNTP connectie troubleshooting
- Fout `SSL routines::wrong version number` wijst meestal op een mismatch tussen poort en encryptie.
- Gebruik als richtlijn:
  - `port 563` + `encryptie ssl`
  - `port 119` + `encryptie none` (of soms `tls`, provider-afhankelijk)

## Composer troubleshooting
- Fout over `hirak/prestissimo` op Composer v2?
  - Deze plugin is legacy (Composer v1). Verwijder de globale plugin:
    ```bash
    composer global remove hirak/prestissimo
    ```
  - Of voer tijdelijk uit zonder plugins:
    ```bash
    composer update --no-dev --optimize-autoloader --no-plugins
    ```

## .env troubleshooting
- Krijg je bij `php bin/console ...` een melding dat `DB_HOST`, `DB_DATABASE` of `DB_USERNAME` ontbreekt?
  - Controleer of `.env` echt in de projectroot staat:
    ```bash
    ls -la .env
    ```
  - Controleer of de keys exact zo heten:
    - `DB_HOST`
    - `DB_DATABASE`
    - `DB_USERNAME`

## Apache/shared-hosting troubleshooting
- Krijg je `404` of `403` op routes zoals `/health`?
  - Zorg dat de projectroot [index.php](../index.php) en [`.htaccess`](../.htaccess) mee deployed zijn.
  - Controleer dat Apache rewrite actief is via `.htaccess` (AllowOverride moet aan staan bij non-shared hosting).
  - Controleer permissies:
    ```bash
    find . -type d -exec chmod 755 {} \;
    find . -type f -exec chmod 644 {} \;
    chmod 600 .env
    ```

## VS Code
- Aanbevolen extensies staan in [`.vscode/extensions.json`](../.vscode/extensions.json).
- Beschikbare taken:
  - `php: check syntax`
  - `php: serve`
