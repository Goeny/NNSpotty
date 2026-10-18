# Changelog

Alle noemenswaardige wijzigingen worden hier bijgehouden.

## [0.0.1] - 2026-10-17 - Delta Integrale

### Added
- Eerste projectdocumentatie met productrichtlijnen.
- Versioning- en codenaambeleid.
- Initiële second-brain documentatie.
- Basis PHP scaffold met:
  - `GET /`
  - `GET /health`
  - minimale router/response lagen
  - env-config en voorbeeld `.env.example`
- Eerste php-nntp integratie-entrypoint via `NntpClientFactory` (`rvdv/nntp`).
- VS Code setup:
  - extensie-aanbevelingen
  - build/run tasks
  - launch config voor Xdebug.
- Eerste admin/preferences/moderatie/comments scaffold:
  - admin beheerpanel met Usenet settings (retentie, server, login, encryptie);
  - username-gebaseerde user preferences;
  - spotter whitelist/blacklist beheer;
  - comments op posts;
  - MySQL/MariaDB opslag via PDO.
- Database en console fundament:
  - migraties via `bin/console db migrate`;
  - db status/health/cleanup commands;
  - user create/delete/edit/password/list commands;
  - retrieve command met `--all`, `--days`, `--since-last`.

### Changed
- Composer dependency constraint voor `rvdv/nntp` aangepast naar `^1.0` (compatibel met beschikbare releases).
- Root package versie expliciet gezet op `0.0.1` om root-version fallback warning te voorkomen.
- Apache deployment routing toegevoegd met root front controller + `.htaccess` rewrite regels.
- HTTP router ondersteunt nu `HEAD` op `GET` routes (bijv. `curl -I` op `/admin`/`/health`).
- Sessiegebaseerde authenticatie toegevoegd met `/login` en `/logout`.
- Admin endpoints afgeschermd tot admin users; preferences vereisen login en werken op ingelogde user.
- CSRF-bescherming toegevoegd op alle POST-formulieren (login, logout, admin acties, preferences).
- Login rate limiting toegevoegd (DB-backed lockout na 5 mislukte pogingen binnen 15 minuten).
- Sessieveiligheid verbeterd met `session_regenerate_id` bij login/logout.
- Migrator transaction-flow aangepast voor MySQL DDL, zodat `db migrate` niet faalt met "There is no active transaction".
- Admin audit logging toegevoegd met actor/IP/details en weergave in adminpanel.
- `retrieve` voert nu echte NNTP xover synchronisatie uit op basis van `NNTP_GROUPS`, met opslag in `usenet_posts` en cursor tracking.
- `retrieve` doet nu standaard auto-discovery van groepen; `NNTP_GROUPS` is optioneel override geworden.
- Documentatie verduidelijkt trust-model: whitelist/blacklist als moderatieoptie, niet als verplichte basisfilter.
- Usenet/retrieve runtime instellingen verplaatst naar admin panel (server, login, retentie, group filters, max artikelen).
- `.env` teruggebracht naar basis app/database configuratie.
- NNTP connectiefouten geven nu expliciete foutdetails en poort/encryptie hints (o.a. voor SSL wrong version number).
- Retrieve group-normalisatie toegevoegd om providers met afwijkende group-list structuren robuust te ondersteunen.
- Retrieve-loop extra defensief gemaakt tegen integer keys in group-maps om runtime type errors te voorkomen.
- `bin/console retrieve --verbose` toegevoegd met live detailoutput per groep, range en batch-insertresultaten.
- Retrieve-robuustheid verbeterd voor afwijkende provider-overview data (safe xover, veldfallbacks, ruisgroepen filter).
- Retrieve loopt nu per groep door bij fouten (groep-skip i.p.v. volledige run-fail) en meldt overgeslagen groepen in de eindstatus.
- Admin/default retrieve settings sturen nu naar Spotnet-startgroepen (`free.pt` / `free.ptd`) met veiligere discovery-defaults.
- Nieuwe browsepagina `/spots` toegevoegd met categorie-filters, paginering en weergave van opgehaalde usenet-posts.
- `/spots` toont nu gegroepeerde releases (Spotweb-achtig) i.p.v. losse headerregels, met detailpagina per release.
- Retrieve indexeert nieuwe headers direct naar `spot_releases` (`release_key`/`release_title`) voor bruikbare browse-resultaten.
- Nieuwe migratie toegevoegd voor release-index (`20261017_0005_release_index`).
- Nieuw consolecommando `php bin/console db reindex-spots` toegevoegd om bestaande headers opnieuw naar release-index op te bouwen.
