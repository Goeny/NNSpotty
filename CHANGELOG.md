# Changelog

Alle noemenswaardige wijzigingen worden hier bijgehouden.

## [Unreleased] - 0.0.1 - Delta Integrale

### Fixed
- Installatiehandleiding gecorrigeerd: `db migrate` dekt volledige schema-opbouw voor nieuwe installaties; losse migratiestappen zijn niet nodig.
- Film-/seriemetadata probeert automatisch de andere ingeschakelde provider wanneer de voorkeursprovider geen unieke match heeft, een zoek-/detailfout geeft of de gevraagde aflevering mist. Een geslaagde primaire match blijft leidend en wordt via externe IDs aangevuld. Handmatige keuzes/blokkeringen blijven behouden; providerfouten blijven zichtbaar, met vijf minuten cache voor deelresultaten. Oude automatische/negatieve matches worden bij detail opnieuw beoordeeld, zonder migratie of cacheflush.
- RSA/SHA1-testfout verwijst naar de OpenSSL-diagnostiek en het gebruik van de bestaande aparte `OPENSSL_CONF`-configuratie; geen cryptografietests overgeslagen of beleid gewijzigd.
- Muziekmatching slimmer: scenegroep, `Hi-Res`/HRA, WEB, `3CD`/`2xCD` en editiehaakjes (Anniversary/Deluxe/Remastered) worden opgeschoond; `EP`/`Single` wordt typehint; `VA`/`V.A.`/`Various`/`Diverse Artiesten`/`Verschillende Artiesten` worden Various Artists (ook achteraan); `vol.2` = `Volume 2`; scene-/editiejaar accepteert een oudere albumgroep; MusicBrainz-artiestaliassen tellen mee; detail-discovery probeert gericht, breed en daarna omgedraaide volgorde (max. vier zoekrequests). Oude automatische/negatieve matches worden bij de volgende detailweergave opnieuw beoordeeld; handmatige keuzes blijven.
- Detailhero: trailer als filmrol-icoon in de knoppengroep met speler in een venster; bronvermeldingen TVDB/TMDB bovenaan naast Metadata link; TVDB-logo op teksthoogte; Reacties/NZB-knoppen verticaal gecentreerd.
- Muziekmatching: `Artiest - jaar - Album` wordt herkend, bij gelijke artiest/titel wint het album van single/EP en een lege gerichte MusicBrainz-zoekactie valt terug op een bredere zoekactie (exacte selectie blijft vereist).
- Vanuit Favorieten openen een hoofdcategorie en **Alle** in de sidebar weer het reguliere overzicht.
- Muziek-/film-/serie-/afleveringfanart past volledig en proportioneel rechts in de metadatahero, zonder afgesneden boven-/onderkant. Zachte linkerovergang en gedeelde donker/licht-tekstoverlay; geldt ook voor previews, zonder extra paginahoogte, requests of cachewijzigingen.

### Added
- Uitgebreidere CLI-documentatie met alle beschikbare `bin/console` commandgroepen, opties en voorbeelden.
- Snelle overzichtsindex (migratie `20261023_0024_spot_browse_index`): smalle leestabel `spot_browse`, gedeelde categoriecombinaties met spotaantallen en titel-FULLTEXT. Filters/aantallen lezen combinaties, de lijst wandelt op datum zonder filesort, brede zoekopdrachten zoeken eerst in de nieuwste spots en hebben een tijdslimiet met terugval (ook bij MariaDB-fout 188); beschikbare filters worden 10 minuten gecachet in `spot_filter_cache`; opbouw in datumvolgorde. Opbouw met voortgang tijdens `db migrate`; `db reindex-spots` bouwt opnieuw op, `db cleanup` herstelt aantallen. Overbodige brede indexen op `spotnet_spots` vervallen. Benchmark `tests/browse-performance.php` tot 5 miljoen spots.
- Zoeken op hele woorden en exacte woordreeksen in overzicht en Newznab: `The` vindt geen `Thesaurus`, `The Juggler` vindt niet `Juggler in the streets`.
- Drill-down filters: na een filter of zoekterm toont de sidebar alleen subcategorieen die nog resultaten opleveren (Films EN Series is niet meer te kiezen).
- Metadata-tab in het beheerpanel met subtabs Providers (TheTVDB, TMDB, fanart.tv), Films, Series en Muziek; applicaties, boeken en games volgen later. Oude adminlinks (`#music-metadata`, `#fanart`) openen de juiste subtab.
- TMDB als tweede film-/serieprovider (migratie `20261022_0023_tmdb_metadata`): per type een primaire provider (standaard TMDB voor films, TheTVDB voor series), de andere vult lege velden aan via IMDb-/TVDB-ID's. TMDB levert gebruikersscore (ook per aflevering), NL/US-leeftijdsclassificatie en trailers die pas na een klik via youtube-nocookie.com of Vimeo laden. Bronvermelding met TMDB-logo en verplichte vermelding, provider per kandidaat bij handmatig zoeken, budget 240 requests/minuut.
- Retrieverperformance: reacties standaard op aanvraag (alleen XOVER-headers indexeren, tekst bij openen spot via pipelined batches en databasecache), aparte reactie-retentie, opslag per batch in een transactie met een bestaat-query en multi-row inserts. Admininstelling voor reactiemodus/-retentie, nieuw recht `comments.load` (standaard voor alle groepen), `tekst_later`-teller in verbose output. Migratie `20261021_0022_lazy_comments`.
- Gebruikers-/groepenbeheer in de UI, meerdere groepen en rechtenmatrix, beschermde laatste beheerder, directe sessie-/API-controles, deactivering en verplichte wijziging van tijdelijke wachtwoorden. CLI behoudt bestaande acties met actieve status en groeps-ID's. Profieltab met publieke reactienaam, vaste handtekening, prive-e-mail en veilige lokale avatarverwerking via upload/HTTPS/Gravatar; headeravatar en volledige publicatiepreview. Migratie `20261020_0021_accounts`.
- Optionele aparte Usenet-postingserver met eigen poort/encryptie in het beheerpanel. Standaard dezelfde verbinding voor ophalen en publiceren; aparte postingserver erft de actuele standaardlogin tenzij expliciet een eigen gebruikersnaam en wachtwoord zijn gekozen. Geen wijziging aan retrieve/afbeeldingen/NZB of nieuwe migratie.
- Usenet-reacties publiceren vanuit spotdetails, zonder lokale plaatsingsoptie. Persoonlijke/server-RSA-identiteiten in database, Spotnet proof-of-work en bodygebonden RSA/SHA1-handtekening, php-nntp POST met bevestiging en directe Usenet-cache, idempotente formulier-ID, gebruikerslock/ratelimiet en expliciete onbekende uitkomst zonder automatische retry. Zichtbare configuratiefout bij geblokkeerde SHA1, conceptbehoud en publieke toestemming; oud lokaal adminformulier vervallen. Migratie `20261020_0020_comment_publication`.
- Klikbare, toetsenbordbedienbare emoticonbalk bij reactieveld met compatibele codes op cursorpositie, geselecteerde tekst vervangen, invoerlimiet en publicatievoortgang. Zonder JavaScript blijft handmatige invoer/publicatie mogelijk.
- Eigen lokale typografische releasekwaliteitsbadges voor originele en TVDB-verrijkte film-/seriedetails en previews; donker/licht en mobiel, geen externe afbeeldingsrequests. Uitgebreide herkenning met losse x264/x265/H.264/H.265/HEVC, VP9/8K/SDR, DTS:X/HRA, Dolby Digital Plus, MP3/DVD/BDRemux en HDR10Plus; geen onterechte generieke DTS/DD/Remux-dubbelen. Geen officiele merkafbeeldingen zonder verifieerbare hergebruikvoorwaarden.
- NNTP-pipelining voor bulk-HEAD/ARTICLE via eigen begrensde php-nntp-verbinding: admininstelling 1-128, standaard 32, 1 = uit. Datum-/bestaandfilters voor transport, volledige vensters op dezelfde verbinding, partiele writes, CRLF/dot-framing en message-ID-controle; per-artikel 423/430, expliciete fatale fouten zonder cursorverlies. Alle console-/cron-/web-retrievemodi inclusief reacties/refresh; geen vendorpatch, nieuwe dependency of migratie. Synthetische TCP-doorvoertest en regressies voor midden-vensterfouten/hervatten.
- Persoonlijke spotfavorieten met bladwijzer-/sterknop (neutraal/oranje) in lijst, preview, detail en asynchroon geladen metadata. Eigen sidebaroverzicht met bestaande zoek-/categorie-/keysetfilters; directe verwijdering uit de favorietenlijst en gesynchroniseerde zichtbare knoppen. CSRF-beveiligde idempotente opslag op sessieusername, zichtbare Bootstrap-feedback en geen N+1-query per spot. Migratie `20261019_0019_favorites` met persoonlijke primary key en cascade bij gebruiker-/spot-/retentieverwijdering.
- Optionele fanart.tv-artiestachtergronden bij muziekdetails en cache-only previews, op exacte MusicBrainz-artiest-ID met bronvermelding en donker/licht-overlay. Eigen server-side HTTPS-client, artist/key-cache (zeven dagen/ontbrekend een dag/fout vijf minuten), zichtbare waarschuwingen zonder muziekmetadata te verliezen, geen willekeurige compilatie-/multi-artiestkeuze. Migratie `20261019_0018_music_fanart` voor default-off globale/persoonlijke schakelaars en server-side API-key met behoud-/wisactie; afbeeldings- en muziek-opt-outs blijven gelden.
- Verbeterde muziekdiscovery voor uitgebreide scene-/vinyl-/DTS-HD-/WAVPACK-titels, bronjaar vooraan en afsluitend Deluxe; gerichte artiest-EN-albumquery met letterlijke escaping en maximaal 25 kandidaten/een zoekrequest. Numerieke albums blijven intact, live-/concertsuggesties leiden niet automatisch tot een studiokoppeling. Oude resultaatcache wordt opnieuw beoordeeld zonder bestaande handmatige koppelingen/blokkeringen te verliezen; geen extra migratie of retrieve nodig.
- Optionele MusicBrainz-muziekmetadata met aparte default-off admin-/gebruikersschakelaars, conservatieve artiest/album/jaar-discovery en adminzoek-/keuze-/reset-/blokkeerbeheer. Albumgroepen/singles/EPs en artiest-/discografieweergave; tracklist/label/speelduur alleen bij gekozen specifieke uitgave.
- Migratie `20261019_0017_music_metadata`: instellingen, persoonlijke toestemming, gedeelde matches en providercache in MySQL/MariaDB; vaste HTTPS-provider zonder API-key, APP_URL-contact-User-Agent, persistente 1,1-seconde-requestlimiet en zichtbare fouten. Eigen vierkante muziekhoezen via Cover Art Archive, artiestplaceholder, attributie en cache-only preview. TVDB blijft afzonderlijk werken via gedeelde drawerinteractie.
- Kleine spotpreview hergebruikt reeds opgehaalde verse TVDB-matches voor dezelfde spotinvoer/taal, met titel/synopsis/artwork/cast en attributie. Geen nieuwe TVDB-aanvragen of cachevernieuwing vanuit het overzicht; oorspronkelijke weergave bij ontbrekende/verlopen/ongeldige match. Globale/persoonlijke toestemming, blokkeringen, spotzichtbaarheid en afbeeldingsvoorkeur blijven gelden.
- Datumgerichte retrieve (`--days`, `--all`/`--full`, ook comments-only/refresh-spots) stopt per groep na drie opeenvolgende niet-lege batches met uitsluitend geldige datums strikt voor de ingestelde grens. Recente/ongeldige regels en lege batches onderbreken de reeks; expliciete stopmelding. Zonder grens en bij `--since-last` blijft bestaand scangedrag behouden. Bewuste snelheidsafweging: recente berichten achter deze stop kunnen worden gemist bij ongeordende datums.
- Optionele sleutelgebaseerde Spotlist-whitelist/blacklist: gecontroleerde RSA/SHA1-message-ID-identiteit, SHA256-modulusfingerprints, blacklistvoorrang en overslaan van nieuwe gecontroleerde blacklisted spots; onbekende/ongeldige identiteiten blijven behouden. Whitelistbadge in overzicht/preview/detail, zonder inhoudsclaim.
- Migratie `20261019_0016_spotter_keys`, atomische database-snapshots met dagelijks vernieuwen tijdens spot-retrieve, admin/console `spotters status|sync|enable|disable`, expliciete synchronisatiefouten/ongeldige-keywaarschuwingen en behoud van vorige lijsten. OpenSSL is een expliciete PHP-platformvereiste. Bestaande spots/naamlijsten worden niet automatisch verwijderd of gemodereerd.
- Browseperformance: sessielocks vrijgegeven voor lees-/providerrequests, asynchrone exacte nieuwtellers met fout/retry, ID-first lijstqueries en een geordende zoekpoging met database-tijdbudget en volledige sequentiele fallback. Letterlijke deeltekstsemantiek, filters en keysets blijven behouden.
- Migratie `20261019_0015_browse_performance` voor zichtbaarheid-/type-datumindexen, optionele admin-only `APP_TIMING`-diagnose, synthetische 300k/1,3M-benchmark en echte gelijktijdige HTTP/NNTP-sessieregressie.
- Volledige retrieve (`--full`/`--all`) respecteert retentie, met een optionele admin-begindatum. Geslaagde retrieves verwijderen alle verlopen spots en gekoppelde reacties in transactiebatches van 1000, zonder maximum per run; retentie `0` houdt alles bij.
- Browser-terug/-vooruit herstelt eerder bijgeladen spotlijsten en hun scrollpositie, met een eigen gebruikersgebonden positie per geschiedenisvermelding, desktop-/mobielondersteuning, zichtbaar herstel en opnieuw proberen bij ophaalfouten. Geen spot-HTML in browseropslag; ontbrekende batches worden opnieuw opgehaald met de bestaande filters.
- Persoonlijke filterbadges met nieuw geimporteerde spots sinds het vorige browsebezoek, met vaste grens tijdens de huidige browsersessie; overzichttotalen blijven totaalwaarden. Eerste bezoek begint zonder historische nieuwmeldingen. Ook detail-sidebars tonen dezelfde tellers; zoeken/EN-filters/zichtbaarheid blijven gelden.
- Migratie `20261018_0014_browse_visits` voor username-gebonden browseactiviteit, microseconde-importtijden en tijdindex; refresh/duplicaten tellen niet opnieuw als nieuw.
- Plex-geinspireerde oranje/gouden kleuraccenten voor knoppen, badges, filterlabels en schakelaars; bestaande layout en semantische statuskleuren blijven behouden. Metadata voor muziek/games/software/boeken vastgelegd als toekomstwerk, niet als bestaande koppelingen.
- Verbeterde TVDB-zoektermen voor genummerde releases en editie-/kwaliteitssuffixen; filmcollecties en meerdere Blu-ray-discs blijven bewust zonder automatische enkelvoudige filmkeuze. Oude matchresultaten worden opnieuw beoordeeld met behoud van handmatige keuzes/blokkeringen. Ontbrekende officiele afleveringen noemen het gevraagde seizoen/nummer expliciet als onbevestigd.
- Plex-geinspireerde releasekwaliteit in hero/preview (video, audio, taal, ondertiteling), uitsluitend uit herkende titelclaims en onafhankelijk van TVDB. Ronde lokale placeholders voor ontbrekende en defecte cast-/crewfoto's, met behoud van de globale afbeeldingsvoorkeur.
- Zichtbare TVDB-attributie bij getoonde metadata: directe recordlink met klein origineel lokaal logo passend bij donker/licht. Compacte "TVDB link" linksboven in de hero opent matchstatus en admin-only handmatige opties in een rechts in-/uitschuivend toegankelijk paneel met Escape, zelfstandig scrollen en focusherstel na asynchrone correcties.
- TheTVDB API V4-verrijking op volledige filmdetails en serie-/afleveringdetails, met centrale admin-key/optionele PIN, persoonlijke default-off toestemming, taal met Engelse fallback en asynchrone veilige weergave zonder wijziging van oorspronkelijke spotdata.
- Conservatieve unieke titel-/jaarmatching, exacte officiële afleveringskoppeling en admin-only gedeelde zoek-/keuze-/reset-/blokkeeracties. Plex-geinspireerde hero met echte fanart/poster of afleveringstill, synopsis, genres, speelduur, cast/crew en studio/netwerk; bestaande reacties/NZB/SABnzbd/lightbox blijven werken.
- Migratie `20261018_0013_tvdb`: instellingen/token, username-voorkeuren, provider- en matchcache in MySQL/MariaDB. Begrensde HTTPS-client met tokenhergebruik/401-vernieuwing, timeouts, vaste hosts, requestbudget, locks, expliciete fouten en verlopen-cachecleanup. Geen ratings afgeleid van populariteit en nog geen klikbare castfilters of Newznab-ID-zoeken.
- Enkele klik op overzichtsregel opent de preview, dubbelklik opent de volledige spot; compacte hero-acties rechtsboven. Ontbrekende/onbereikbare covers tonen alleen de placeholder, ook als blurred hero-achtergrond, zonder waarschuwingsoverlay.
- Eigen NNSpotty-huisstijl: lokale favicon/touch-icon op alle pagina's, headericoon, logo op start/login en coverplaceholder in overzicht/preview/detail, ook met expliciete feedback bij laadfouten. Originele beelden en presentatie bewaard onder docs/branding, kleinere webversies onder public/assets/images.
- SABnzbd-forwarding met centrale adminverbinding en persoonlijke verbindingen, globale categorietoewijzing per spottype, volledige-key-invoer zonder terugtonen, verbindingstest en echte multipart NZB-upload vanuit detail/preview.
- SABnzbd downloadpaneel met slide-in/out, echte wachtrij/voortgang/snelheid, vijfminutengrafiek, begrensde polling, paginering, foutfeedback en persistente lokale verzendhistorie. Herhaalde bevestigingen uploaden niet opnieuw; onzekerheid na verzending blijft expliciet zichtbaar.
- Migratie `20261018_0012_sabnzbd` voor verbindingen, categorietoewijzing en historie; PHP-extensie curl als platformvereiste. Persoonlijke publieke hosts voor gewone gebruikers, centrale/interne hosts alleen admins, DNS-validatie/pinning en geen redirects/proxies.
- Adminonderdelen met fragmentnavigatie: Usenet, Inhoud en weergave, Newznab, SABnzbd, Moderatie en reacties, Auditlog; bestaande formulieren en ankerlinks blijven bruikbaar.
- Newznab-MVP met publieke caps, persoonlijke gehashte API-keys, standaardcategorieen, XML search/tvsearch/movie/get, categorie-OR, titel/aflevering/jaarfilters, paginering en keygebonden aanvraaglimiet. Default-off adminsysteemschakelaar; eigen keybeheer met CSRF, eenmalige weergave en bevestigingen voor rotatie/intrekken. Geen onterecht geadverteerd IMDb/TVDB-ID-zoeken.
- Gedeelde NZB-downloadservice voor browser en API: NNTP BODY, multipart Spotnet-unescape/raw-DEFLATE, begrensde veilige NZB-validatie, databasecache en expliciete foutafhandeling. Werkende downloadknop in detail/preview; migratie `20261018_0011_newznab_nzb` backfillt bestaande categorieen en voegt keys/cache toe. NZB-cache vervalt bij expliciet gewijzigde segmentbron.
- Protocol-/database-/HTTP-regressies voor NZB-transport en cache, categorievertaling, API-feeds, sleutelbeheer, limieten, gebruikersisolatie, zichtbaarheid en migratie/backfill; Prowlarr-configuratie en productieacceptatie gedocumenteerd.
- Moderne statische emoji's en compacte badges voor alle 30 Spotweb-emoticoncodes, plus respect/respekt-alias; gedeeld door Usenet-/lokale reacties en omschrijvingen, zonder GIF's of externe afbeeldingsverzoeken.
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
- Homepage met knoppen verwijderd: root-URL en geslaagde login gaan direct naar het spotsoverzicht; navigatielinks in admin/voorkeuren aangepast.
- Generieke onderwerp-/headergroepering vervangen door een eigen Spotnet-parser: individuele spots uit `free.pt`, omschrijving en metadata uit `X-XML`, reacties uit `free.usenet`.
- Afbeeldingsegmenten worden op aanvraag gedecodeerd en in de database gecachet; oude externe afbeelding-URLs worden ondersteund.
- Spotsoverzicht en detailpagina tonen individuele spots, omschrijving, afbeelding en reacties; lokale reacties zijn CSRF-beveiligd en worden niet naar Usenet gepubliceerd.
- Nieuwe niet-destructieve migratie `20261018_0006_spotnet`, aparte Spotnet-cursors en behoud van historische generieke data buiten het overzicht.
- `db reindex-spots` geeft nu een expliciete instructie om echte Spotnet-inhoud opnieuw op te halen.
- `--days`, `--all` en `--full` scannen vanaf de hoogste artikelnummers; datumgrenzen worden toegepast voordat volledige spots worden opgehaald. De scan bezoekt ook oudere ranges om recente spots met lagere artikelnummers niet te missen; `--since-last` gebruikt de ingestelde artikellimiet zonder artikelranges over te slaan.
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
