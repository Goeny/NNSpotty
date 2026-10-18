# Changelog

Alle noemenswaardige wijzigingen worden hier bijgehouden.

## [Unreleased] - 0.0.1 - Delta Integrale

### Added
- Newsreader/clientnaam in detail en preview; expliciete `retrieve --refresh-spots --days=<n>` voor aanvullen van eerder opgeslagen metadata en `--comments-only` voor onafhankelijke reactieretrieval.
- Gitignore voor dependencies, lokale configuratie, runtime/logs/dumps en testoutput; `.env` en `vendor/` uit de index gehaald, Composer-manifesten blijven gevolgd. Veilige eenmalige deploymentprocedure met configuratiebackup toegevoegd.
- Coververgroting in detail en preview met native modaal lightboxvenster, toetsenbordfocus, Escape/sluiten, laadfeedback en proportionele schaling; linkfallback zonder JavaScript.
- Adminschakelaars voor Usenet-reacties indexeren, reacties tonen/lokaal plaatsen en afbeeldingen ophalen/tonen. Standaard bestaand gedrag; geen dataverwijdering en geen cursorverplaatsing voor uitgeschakelde reactiegroepen.
- Veilige Spotnet-/BBCode-weergave, inclusief `[br]`, nadruk, citaten, code, lijsten en HTTP(S)-links, gedeeld door omschrijvingen en geimporteerde reacties. Remote styling blijft geblokkeerd.
- Initieel gesloten, asynchroon geladen preview met slide-in/slide-out, sluitknop, Escape en focusherstel; dezelfde veilige omschrijvingsopmaak als de detailpagina.
- Automatisch bijladen nabij het lijsteinde met stabiele datum/ID-keysets, zichtbare foutmelding en handmatige retry; geen paginanummers.
- Stapelbare subcategorie-EN-filters, verwijderbare filterchips en facetaantallen op de huidige selectie.
- Globale kolomstandaarden per hoofdgroep en username-gebonden overrides/reset, met CSRF/audit en migratie `20261018_0009_user_spot_columns`.
- Volledige Spotnet-categoriedimensies met leesbare labels, typegroepering, subcategoriefilters en titelzoekfunctie; compatibiliteit met oudere type-, genre- en boektaalcodes.
- Globale admininstelling voor erotica en persoonlijke opt-in op username. Standaard uit; een globale blokkade geldt ook voor admins en voor details, afbeeldingen, filters, aantallen en reacties.
- Migraties voor categoriefacetten, classificatie en persoonlijke zichtbaarheid; hervatbare, automatische herindeling van bestaande spots zonder nieuwe retrieval.
- Donkere responsive sidebar, compacte overzichtstabel met geselecteerde preview en detailhero op basis van de screenshots; werkende lichte themavariant.
- Regressies voor alle categorielabels, legacy adult-codes, migratie/backfill, gebruikersisolatie en HTTP-zichtbaarheid inclusief gecachte afbeeldingen en beide reactieformulieren.

### Fixed
- Spotnet-reactie-ID heeft voorrang op onbetrouwbare provider-References; migratie 0010 herstelt bestaande reactiekoppelingen. Lege reactiepanelen leggen uit dat nul lokaal opgeslagen reacties geen bewijs is dat er op Usenet geen reacties zijn.
- Deploymentprocedure gebruikt umask 022 voor Git/Composer en beperkt 077 tot geheime backups; gericht rechtenherstel voor publieke CSS/JS na HTTP 403 gedocumenteerd.
- Horizontale tabelscroll vervangen door instelbare kolommen, afbrekende waarden en compacte mobiele regels; hero-achtergrond duidelijker met minder blur.
- Header en zoeken blijven vast in beeld; op brede schermen zijn lijst en volledig rechter detailpaneel afzonderlijk scrollbaar, ook met toetsenbordfocus.
- Alleen hoofdcategorieen staan bovenin; alle bijbehorende subcategorieen verschijnen links, ook zonder resultaten, met behoud van de erotica-instellingen.
- Detailcovers krijgen een vast, proportioneel geschaald frame met vervaagde vulling voor te kleine of afwijkende beelden. De hero toont een uitgerekte, vervaagde versie van dezelfde hoofdafbeelding.
- HTML-omschrijvingen worden in preview en detail veilig opgemaakt in plaats van als HTML-brontekst getoond; DOM-gebaseerde allowlist en expliciete ext-dom-platformvereiste toegevoegd. Reacties blijven tekst.
- Overviews met ontbrekende optionele staartvelden breken de retrieval niet meer af; werkelijk onbruikbare regels worden per regel gemeld met range, positie en veldaantallen.
- `--days` begint bij de hoogste artikelnummers, zodat recente spots doorgaans eerder zichtbaar worden, zonder oudere ranges ongecontroleerd over te slaan.
- Verbose batchlogging toont het UTC-datumbereik en afzonderlijke aantallen voor oude, bestaande, niet-Spotnet, ongeldige, verdwenen en opgeslagen berichten.
- Regressietests voor afwijkende provider-overviews, aflopende volledige scans, niet-monotone datums en cursorbehoud bij transportfouten toegevoegd.

### Changed
- Generieke onderwerp-/headergroepering vervangen door een eigen Spotnet-parser: individuele spots uit `free.pt`, omschrijving en metadata uit `X-XML`, reacties uit `free.usenet`.
- Afbeeldingsegmenten worden op aanvraag gedecodeerd en in de database gecachet; oude externe afbeelding-URLs worden ondersteund.
- Spotsoverzicht en detailpagina tonen individuele spots, omschrijving, afbeelding en reacties; lokale reacties zijn CSRF-beveiligd en worden niet naar Usenet gepubliceerd.
- Nieuwe niet-destructieve migratie `20261018_0006_spotnet`, aparte Spotnet-cursors en behoud van historische generieke data buiten het overzicht.
- `db reindex-spots` geeft nu een expliciete instructie om echte Spotnet-inhoud opnieuw op te halen.
- `--days` scant alle beschikbare headers voor een volledige datumfilter; `--all` negeert retentie; `--since-last` gebruikt de ingestelde artikellimiet zonder artikelranges over te slaan.
- Transportfouten falen expliciet; corrupte XML en verlopen artikelen worden gemeld in plaats van als lege succesvolle resultaten behandeld.
- Vereiste PHP-extensies toegevoegd aan Composer en protocol-/integratietests toegevoegd met synthetische NNTP-fixtures.
- Usenet-identiteit/inhoud wordt expliciet als onbevestigd getoond; handtekeningverificatie en geavanceerde opmaak zijn nog niet aanwezig.

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
