# Installatie (ontwikkelomgeving)

## Vereisten
- PHP 8.2 of hoger
- Composer 2.x
- MySQL/MariaDB en PHP-extensies `pdo_mysql`, `SimpleXML`, `dom`, `iconv`, `zlib`

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
   - `http://localhost:8080`
   - `http://localhost:8080/health`
   - `http://localhost:8080/login`
   - `http://localhost:8080/admin` (admin login vereist)
   - `http://localhost:8080/preferences` (login vereist)
6. Stel via `/admin` je Usenet/retrieve instellingen in (server, login, retentie, Spotnet-groepen, max artikelen per incrementele run).
   Gebruik standaard `spots:free.pt,comments:free.usenet`; leeg gebruikt dezelfde defaults.
7. Haal echte spots op met `php bin/console retrieve --days=30 --verbose` en open `/spots`.
   Dit scant alle beschikbare headers om de datumfilter correct toe te passen en kan lang duren.

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
