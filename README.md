<p align="center">
  <img src="./public/assets/images/logo.png" alt="NNSpotty logo" width="384">
</p>

# NNSpotty

**Een moderne Spotnet-webapplicatie voor het vinden, bekijken en downloaden van Usenet-spots.**

NNSpotty haalt individuele spots op, inclusief omschrijving, categorieen, afbeeldingen, NZB-verwijzingen en reacties. Je kunt zoeken en filteren, NZB's downloaden, rechtstreeks naar SABnzbd sturen en NNSpotty als Newznab-indexer gebruiken. Optionele TheTVDB-verrijking geeft films en series een Plex-geinspireerde detailpagina met posters, fanart en cast.

Dit is een nieuwe, modulaire PHP-applicatie met een eigen codebasis, geen aangepaste installatie van Spotweb. Applicatiedata, instellingen, indexen en caches staan in MySQL/MariaDB.

**Versie: 0.0.1 - Delta Integrale.** Actief in ontwikkeling; nog geen volledige Spotweb-vervanger. Het doel voor de eerste definitieve release is 1.0.0.

## Inhoud

- [Functionaliteiten](#functionaliteiten)
- [Installatie vanaf nul](#installatie-vanaf-nul)
- [Eerste retrieve en dagelijks bijwerken](#eerste-retrieve-en-dagelijks-bijwerken)
- [Newznab en NZB downloaden](#newznab-en-nzb-downloaden)
- [SABnzbd instellen](#sabnzbd-instellen)
- [TheTVDB: films, series en afleveringen](#thetvdb-films-series-en-afleveringen)
- [Muziekmetadata: MusicBrainz en Cover Art Archive](#muziekmetadata-musicbrainz-en-cover-art-archive)
- [Console, onderhoud en updates](#console-onderhoud-en-updates)
- [Spotter whitelist en blacklist](#spotter-whitelist-en-blacklist)
- [Tests en controles](#tests-en-controles)
- [Beperkingen en vervolgwerk](#beperkingen-en-vervolgwerk)
- [Documentatie en bijdragen](#documentatie-en-bijdragen)
- [Third-party applicaties, code en tools](#third-party-applicaties-code-en-tools)

## Functionaliteiten

### Spots ophalen en opslaan

- Echte Spotnet-inhoud via NNTP en **php-nntp**, niet alleen een overzicht van algemene Usenet-headers.
- Spotidentiteit op basis van message-ID: verschillende spots met dezelfde titel blijven afzonderlijke items.
- Verwerking van herhaalde `X-XML`-headers voor titel, omschrijving, spotter, datum, bestandsgrootte, categorieen, tag, website en afbeelding-/NZB-segmenten; ook hoofdgroep en gebruikte newsreader worden in details getoond.
- Aparte Spotnet-groepen voor spots en reacties: standaard `spots:free.pt,comments:free.usenet`. Een lege groepsinstelling gebruikt deze defaults, geen automatische groepsontdekking.
- Historische imports over een aantal dagen of de volledige beschikbare historie; incrementeel bijwerken met een databasecursor per groep.
- Incrementele artikellimiet per groep, retentie in dagen, afzonderlijk reacties ophalen en expliciet verversen van bestaande spotmetadata.
- Instelbare NNTP-pipelining voor bulk-`HEAD` (spots) en `ARTICLE` (reacties), op dezelfde verbinding via een eigen php-nntp-uitbreiding: **1-128**, standaard **32**, **1 = uitgeschakeld**. Retentie/dubbele artikelen worden voor transport gefilterd; geen extra verbindingen, provider of dependency.
- Dubbele berichten overslaan, verdwenen/ongeldige artikelen afzonderlijk verwerken, diagnostische batchoutput en opgeslagen retrieve-runstatussen. Transportfouten worden expliciet gemeld; cursors worden niet voorbij ongelezen ranges geschoven.
- Afbeeldingen en NZB-inhoud worden op aanvraag opgehaald, gevalideerd en in de database gecachet, niet massaal tijdens indexing gedownload.
- Refresh bewaart reacties en vernieuwt metadata/categorie-index atomair; afbeelding-/NZB-caches vervallen alleen bij gewijzigde segmentbron.

### Overzicht, zoeken en navigatie

- **Favorieten:** persoonlijke bladwijzers met ster in overzicht, preview en detail, ook bij muziek-/TVDB-metadata. Klik om toe te voegen (oranje), klik opnieuw om te verwijderen. Via **Mijn spots > Favorieten** in de linker sidebar zie je je eigen selectie; zoeken, categorieen en lazyload blijven werken. **Alle spots** boven het favorietenoverzicht brengt je terug. Wijzigingen worden server-side opgeslagen en alle zichtbare knoppen voor dezelfde spot worden bijgewerkt. JavaScript is vereist voor de knop; fouten verschijnen als Bootstrap-alert.
- Favorieten zijn gekoppeld aan je username, niet aan de gedeelde spot of browser. Ze veranderen zichtbaarheid niet en beschermen spots niet tegen retentie/cleanup: verwijderen van een spot of gebruiker verwijdert ook de bijbehorende favorieten. Bestaande installaties moeten eerst `php bin/console db migrate` uitvoeren voor migratie `20261019_0019_favorites`; ververs daarna de browsercache.

- Na inloggen direct het spotsoverzicht; geen tussenhomepage. De root-URL verwijst naar `/spots`.
- Vaste header met de enige zoekbalk. De zoekterm blijft bij hoofdgroep- en subcategoriefilters behouden.
- Linker sidebar met eerst **Alle** en de hoofdgroepen **Beeld, Geluid, Spellen en Applicaties**, gevolgd door de volledige subcategorie-indeling van de gekozen hoofdgroep: type, formaat/platform, bron, taal/ondertiteling, bitrate en genre waar van toepassing.
- Onder andere films, series, boeken, afbeeldingen, muziek, livesets, podcasts, luisterboeken, spellen en applicaties.
- Subcategorieen als stapelbare **EN-filters**, ook binnen dezelfde dimensie; actieve filters zijn afzonderlijk verwijderbaar en staan naast de kolominstellingen. Zoektekst en filters blijven behouden bij bijladen.
- Categorieaantallen volgen de zichtbaarheid van de gebruiker. Onbekende categoriecodes blijven bewaard en zichtbaar in technische details.
- Achter subcategoriefilters staat **nieuw sinds je vorige bezoek**, niet het historische totaal. De grens is persoonlijk en blijft tijdens dezelfde browsersessie gelijk bij navigeren/herladen. Nieuwe imports tijdens het bezoek komen erbij; het overzicht berekent geen exacte totaaltelling over alle passende spots.
- De eerste keer na installatie/upgrade wordt een bezoekgrens vastgelegd: bestaande historie begint niet als nieuw. Bij een volgende browsersessie (of uit-/inloggen) geldt je laatste overzicht-/detailbezoek als grens. Tabs delen de sessie; de browser kan sessiecookies bij sessieherstel behouden. Dit zijn geen individuele gelezen/ongelezen-markeringen.
- Infinite loading zonder paginanummers, met een knop **Meer resultaten laden** en opnieuw proberen na een fout. Persoonlijke laadbatch van 10-200 resultaten. De eerste request haalt alleen deze batch op; vervolgspots worden pas bij scrollen opgehaald. Een exacte totaalcount wordt niet bij iedere paginalaad berekend.
- Nieuwtellers laden apart na het overzicht/de volledige detailpagina, uitsluitend bij een geselecteerde categorie. Tijdens laden staat er `...`, geen verzonnen nul; bij een fout verschijnt **Opnieuw proberen**. Filters werken meteen, ook zonder JavaScript; alleen de tellers vereisen JavaScript. Achtergrondtellingen veranderen de persoonlijke bezoekgrens niet.
- Browser-terug vanuit een detail herstelt de geladen lijst en je scrollpositie, ook na 500 of 1000 spots en op mobiel. Elke geschiedenisvermelding houdt een eigen plek bij met behoud van zoektekst/filters. Zonder browser-paginacache worden batches opnieuw opgehaald tot de eerdere lijstlengte en wordt de zichtbare spot als anker gebruikt; wijzigingen in de index kunnen de lijst veranderen. Bij een ophaalfout kun je het herstel opnieuw proberen. Alleen gebruikersgebonden positiegegevens worden in de browsergeschiedenis bewaard, geen spotinhoud.
- Een enkele klik opent een sluitbare preview die van rechts in-/uitschuift. Dubbelklik opent de volledige detailpagina.
- Preview met eigen scrollgebied, Escape en focusherstel; toetsenbordvriendelijke titellinks. Zonder JavaScript openen titels de volledige spot en is er een vervolglink.
- Instelbare overzichtskolommen per hoofdgroep: globale adminstandaarden en persoonlijke overrides met herstel naar de actuele globale standaard. Titel blijft verplicht; **Alle** gebruikt een vaste gemengde kolomset.
- Responsive kaarten op kleine schermen, afbrekende lange waarden en geen horizontale tabelscroll.
- Plex-geinspireerde oranje/gouden accenten voor primaire knoppen, badges, filterlabels, selectieknoppen en schakelaars, in donker en licht; overige layout en semantische fout-/succes-/informatiekleuren blijven behouden.

### Browseperformance en diagnose

- Afbeeldingen en andere lees-/providerrequests geven de PHP-sessielock vroeg vrij. Een trage Usenet-cover houdt zo niet meer het overzicht, bijladen of de retrieve-status van dezelfde gebruiker tegen. CSRF en bezoekregistratie gebeuren voor het vrijgeven waar nodig.
- Gewone lijstqueries selecteren eerst een beperkte set IDs en halen daarna alleen de benodigde lijstvelden op, zonder omschrijving of volledige XML. Migratie `20261019_0015_browse_performance` voegt datum-/ID-indexen voor zichtbaarheid en type toe; er is geen nieuwe retrieve nodig.
- Titelzoeken blijft een **letterlijke deeltekstzoekactie**, inclusief `%`, `_` en `!`. Eerst krijgt de normale geordende zoekquery ongeveer 500 ms databasebudget (`max_statement_time` op MariaDB, `MAX_EXECUTION_TIME` op MySQL). Veel voorkomende termen en kleine selecties kunnen meteen terugkomen. Alleen bij een database-timeout volgt een volledige primary-key-scan met dezelfde filters, sortering en keyset, zonder resultaatverlies of maximale zoekdiepte. Andere databasefouten blijven fouten. Zo worden dure willekeurige rijlezingen via een datumindex begrensd; niet iedere individuele zoekterm is op iedere cache/hardware sneller.
- Een exacte nieuwtelling en zeldzame titelzoekactie blijven afhankelijk van de indexgrootte; asynchroon tellen maakt de SQL zelf niet gratis. Afbeeldingen zonder databasecache blijven afhankelijk van je Usenet-provider. Newznab heeft een afzonderlijk zoekpad en wordt hiermee niet opnieuw ontworpen.
- Optioneel `APP_TIMING=true` in `.env`: **uitsluitend ingelogde admins** krijgen de HTTP-header `Server-Timing` met sessiewachttijd (`session`), databaseverbinding (`database`) en routeverwerking (`application`), in milliseconden. Bekijk die in DevTools > Network > Timing voor het overzicht en de aparte tel-/afbeeldingsrequests. Geen SQL, credentials of stacktraces in de header; standaard uit. Browserdownload/rendering en wachttijd voor PHP-FPM vallen buiten deze servermeting.
- Zie de [Linux-performancechecks](./docs/linux-server-testing.md#browseperformance-en-sessielocks) voor veilige migratie en metingen op je eigen server. De reproduceerbare benchmark hieronder gebruikt alleen synthetische data, geen productiespots.

### Detailpagina, afbeeldingen en releasekwaliteit

- Hero met cover, omschrijving, genres en compacte acties voor reacties, NZB en SABnzbd rechtsboven.
- Originele titel, spotter, UTC-datum, bestandsgrootte, categorieen, tag, website, newsreader, message-ID en NZB-segmentinformatie blijven beschikbaar.
- Vast coverframe: grote beelden proportioneel verkleind, kleine beelden met vervaagde vulling. De oorspronkelijke hero gebruikt een uitgerekte, licht vervaagde cover als achtergrond.
- Klikbare cover in detail en preview met lightbox, Escape, sluiten buiten het venster en focusherstel. Zonder JavaScript opent het beeld in een nieuw tabblad.
- Eigen NNSpotty-logo, favicon, touch-icon en coverplaceholders; ontbrekende/defecte covers tonen alleen de placeholder, ook als hero-achtergrond.
- Herkende **Video / Audio / Taal / Ondertiteling** uit de releasetitel, ook zonder TVDB: onder andere resolutie, HDR, codec, bron, IMAX, AI-enhanced, RIFE/60 fps, audioformaat, kanalen en subtitleclaims.
- Releasekwaliteit is nadrukkelijk **niet gecontroleerd in mediabestanden**. Onbekende waarden worden niet ingevuld en een taalclaim is niet automatisch de audiotaal.

### Omschrijvingen, reacties en emoticons

- Veilige HTML-/Spotnet-opmaak in omschrijvingen en geimporteerde reacties: onder andere `[br]`, vet, cursief, onderstrepen, doorhalen, quotes, codeblokken, links en lijsten.
- Kleur-, font-, grootte- en uitlijningstags behouden de tekst zonder externe stijlen uit te voeren. Scripts, embeds, eventhandlers en remote CSS worden niet uitgevoerd; afbeeldingen in omschrijvingen/reacties worden niet geladen.
- Usenet-reacties uit `free.usenet`, gekoppeld aan de oorspronkelijke spot. De gecodeerde reactie-ID gaat voor een eventueel foutief `References`-veld; bestaande oudere koppelingen worden via migratie hersteld.
- Reacties tonen auteur, datum en bron. Lokale reacties plaatsen vanuit de detailpagina; deze blijven in NNSpotty en worden **niet op Usenet gepubliceerd**.
- Alle 30 bekende Spotweb-emoticoncodes uit de reactieknoppen worden hoofdletterongevoelig herkend, met moderne statische emoji's/tekstbadges in plaats van animated GIF's.
- Onder andere `[img=buigen]`, `[img=klappen]`, `[img=respekt]`, alias `[img=respect]` en `[img=knipoog]`/`[img=wink]`. Codeblokken blijven letterlijk; onbekende codes en afbeelding-URLs laden geen beelden.
- Lokale reacties blijven geescapete tekst met emoticonondersteuning. Emoticons werken ook bij uitgeschakelde postafbeeldingen.

### Downloads en koppelingen

- NZB downloaden vanuit detail en preview, onafhankelijk van de globale Newznab-schakelaar.
- Gedeelde downloadservice voor browser, Newznab en SABnzbd: segmenten samenvoegen, Spotnet-decodering/decompressie, begrensde XML-validatie en databasecache. Defecte/verlopen NZB's geven een expliciete fout en worden niet gecachet.
- **Newznab XML-API** met persoonlijke API-keys, standaardcategorieen, titelzoeken, filmjaar- en seizoen/afleveringfilters, paginering en aanvraaglimiet; te gebruiken via onder andere Prowlarr.
- **SABnzbd-forwarding** met centrale adminkoppeling, persoonlijke gebruikerskoppelingen, admin-categoriemapping en bevestigde NZB-upload zonder een Newznab-keylink door te sturen.
- **Downloads-paneel** met echte SABnzbd-wachtrij, voortgang, snelheid, resterende tijd en een snelheidsgrafiek van de laatste vijf minuten van de panelsessie.
- Lokale verzendhistorie met expliciete statussen, bescherming tegen dubbele upload van dezelfde bevestiging en een zichtbare onbekende uitkomst bij onzekere verzending.
- Zie de configuratie en grenzen van deze koppelingen hieronder.

### TheTVDB-metadata

- Optionele verrijking van volledige film-, serie- en afleveringsdetails, met centrale API V4-key/optionele abonnee-PIN en persoonlijke opt-in.
- Asynchroon ophalen bij detailbezoek; overzicht, preview en indexing starten geen TVDB-fetch.
- Conservatieve unieke titel-/aliasmatching en filmjaarcontrole; opschoning van nummerprefixen en technische/editiesuffixen.
- Exacte officiele seizoen-/afleveringskoppeling, met eerlijke serie-fallback als de aflevering ontbreekt. Filmcollecties worden niet automatisch aan een enkele film gekoppeld.
- Poster, echte fanart, afleveringstill, synopsis, genres, datum, speelduur, cast/crew en beschikbare studio-/netwerkinformatie.
- Ronde lokale placeholders voor ontbrekende of defecte cast-/crewfoto's.
- Compacte **TVDB link** linksboven in de hero opent een slide-in met status en admin-only zoeken, handmatig kiezen, resetten en blokkeren. Correcties gelden gedeeld voor alle gebruikers.
- Nederlands met Engelse fallback of Engels, databasecache, aanvraagbudget en zichtbare foutfeedback met behoud van de oorspronkelijke spot.
- Zichtbare **Metadata van TheTVDB** met lokaal donker/licht-logo en een directe link naar het juiste providerrecord.

### Muziekmetadata

- MusicBrainz voor artiesten en albumgroepen (albums/singles/EPs), met aparte globale en persoonlijke opt-in zonder API-key.
- Conservatieve artiest-/album-/jaardiscovery, gedeeld adminzoek-/keuze-/reset-/blokkeerbeheer en tracklists voor expliciet gekozen uitgaven.
- Vierkante Cover Art Archive-hoezen, eigen artiestweergave, MusicBrainz-attributie en cachehergebruik in kleine previews.
- Persistente aanvraaglimiet, databasecache en zichtbare fouten; geen metadata-backfill tijdens retrieve.

### Gebruikers, beheer en weergave

- Sessie-login/logout met gehashte wachtwoorden, adminrechten, CSRF-beschermde mutaties en login-rate-limiting: blokkering na vijf mislukte pogingen binnen vijftien minuten. De loginpagina gebruikt altijd het donkere thema; ingelogde gebruikers kiezen Default, Plex Dark of Plex Light.
- Username-menu in de header met Voorkeuren en, uitsluitend voor admins, Beheerpanel. Admins kunnen vanuit de header een incrementele retrieve (`--since-last`) starten; een bewegende indicator toont actieve runs en de laatste uitvoeringstijd geldt voor zowel handmatige als cron-runs.
- Consolebeheer voor gebruikers aanmaken, verwijderen, adminrol wijzigen, wachtwoord wijzigen en gebruikers tonen.
- Username-gebonden voorkeuren, los van centrale instellingen: **Default**, **Plex Dark**, **Plex Light**, laadbatch, kolommen, erotica, Newznab-key, eigen SABnzbd en TVDB-toestemming.
- Erotica standaard globaal en persoonlijk verborgen; pas zichtbaar na beide toestemmingen. Globaal uit geldt ook voor admins. Dit beleid geldt voor zoeken, aantallen, facetten, details, afbeeldingen, reacties, API en lokale verzendhistorie; indexing verwijdert deze inhoud niet.
- Admin met zeven deelbare submenu's: **Usenet**, **Inhoud en weergave**, **Newznab**, **SABnzbd**, **TVDB metadata**, **Moderatie en reacties**, **Auditlog**. Zonder JavaScript blijven de secties zichtbaar.
- Usenet-host, poort, login, encryptie, retentie, optionele startdatum, groepsrollen en incrementele artikellimiet centraal beheren. `--full`/`--all` respecteert de ingestelde grenzen; geslaagde retrieves ruimen alle verlopen spots op.
- Reacties indexeren, reacties tonen/lokaal plaatsen en afbeeldingen ophalen/tonen afzonderlijk schakelen. Uitschakelen verwijdert geen data; afbeeldingen uit blokkeert ook cachehits en TVDB-afbeeldingen. Lokale TVDB-attributie blijft wel zichtbaar.
- Sleutelgebaseerde Spotlist-whitelist/blacklist synchroniseren, nieuwe blacklisted spots overslaan en whitelist-badges tonen. Oude handmatige naamlijsten blijven alleen registratie; namen bepalen geen identiteit. Lokale reacties toevoegen/bekijken in admin.
- Auditlog voor beheeracties, database-migraties/status/health/cleanup en publiek `/health` met applicatieversie/codenaam.
- Modulaire domeinlagen voor indexing, metadata, UI, Newznab en downloader; eigen PHP-/JavaScriptcode, zonder frontend-buildstap.

## Installatie vanaf nul

### 1. Benodigdheden

- Linux-server met Git, **PHP 8.2+** voor zowel CLI als webserver en **Composer 2.x**.
- PHP-extensies `curl`, `dom`, `iconv`, `openssl`, `pdo_mysql`, `SimpleXML` en `zlib`; werkende TLS/CA-certificaten voor HTTPS en versleutelde NNTP-streams.
- MySQL of MariaDB, met een eigen database en databasegebruiker.
- Apache of Nginx met PHP-FPM voor productie. De PHP-developmentserver is alleen voor lokaal testen.
- Een Usenet-abonnement/provider die de Spotnet-groepen aanbiedt. Beschikbare historie hangt af van de provider.
- Optioneel: SABnzbd, Prowlarr/Sonarr/Radarr en een passende TheTVDB API-licentie.
- De browser laadt Bootstrap-CSS van jsDelivr. TVDB-artwork en eventuele externe spotcovers vereisen browsertoegang tot hun bronhosts.

### 2. Broncode en dependencies

Vervang de voorbeeldlocatie door je eigen deploypad. Gebruik de clone-URL van jouw repository; de huidige projectremote staat hieronder.

```bash
git clone http://gitlab.hopseflop.nl/ivar/nnspotty.git nnspotty
cd nnspotty
umask 022
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
cp .env.example .env
chmod 600 .env
```

Gebruik bij voorkeur een HTTPS- of SSH-clone-URL als jouw Git-server die aanbiedt. `composer.lock` is meegeleverd: gebruik voor installatie **install**, niet **update**. Node.js/npm is niet nodig.

### 3. Database en basisconfiguratie

Maak met een bevoegde databasebeheerder een lege database en een aparte gebruiker, bijvoorbeeld via `sudo mariadb`:

```sql
CREATE DATABASE nnspotty CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nnspotty'@'localhost' IDENTIFIED BY 'VERVANG_DOOR_EEN_UNIEK_LANG_WACHTWOORD';
GRANT ALL PRIVILEGES ON nnspotty.* TO 'nnspotty'@'localhost';
```

Stem gebruikershost en netwerktoegang af op je databaseopstelling. Migraties hebben ook schemawijzigingsrechten nodig; geef geen globale databasebeheerrechten aan de applicatie.

Bewerk `.env` in de projectroot:

```dotenv
APP_NAME=NNSpotty
APP_ENV=production
APP_DEBUG=false
APP_TIMING=false
APP_URL=https://nnspotty.hopseflop.nl
APP_VERSION=0.0.1
APP_CODENAME=Delta Integrale

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nnspotty
DB_USERNAME=nnspotty
DB_PASSWORD=VERVANG_DOOR_HET_DATABASEWACHTWOORD
DB_CHARSET=utf8mb4
```

Gebruik je eigen domein voor `APP_URL`: een publiek HTTP(S)-origin zonder subpad, query of fragment; in productie HTTPS. Voor lokaal testen gebruik je `http://localhost:8080`. CLI en PHP-FPM moeten dezelfde configuratie kunnen lezen.

**Usenet, SABnzbd en TVDB stel je in via admin/voorkeuren, niet via `.env`.** De SABnzbd/NZBGet/IMDb/TVDB-velden die nog in het voorbeeldbestand staan zijn geen vervanging voor deze database-instellingen; NZBGet en een aparte IMDb-provider zijn nog niet geimplementeerd.

### 4. Migraties en eerste admin

```bash
php bin/console db migrate
php bin/console db status
php bin/console db health

read -rsp 'Wachtwoord voor de eerste admin: ' ADMIN_PASSWORD; echo
php bin/console user create admin "$ADMIN_PASSWORD" --admin=1
unset ADMIN_PASSWORD
```

Dit maakt je eerste account; er is geen standaardlogin. Het consolecommando ontvangt het wachtwoord als procesargument: voer dit alleen in een vertrouwde beheeromgeving uit. De verborgen invoer voorkomt een letterlijk wachtwoord in shellhistorie, niet inzage door bevoegde procesinspectie.

### 5. Webserver en eerste login

**Productie:** stel de documentroot in op de map `public/`. Laat bestaande assets direct serveren en overige routes naar `public/index.php` gaan. Apache kan de meegeleverde `public/.htaccess` gebruiken met `mod_rewrite`/passende `AllowOverride`; configureer bij Nginx de overeenkomstige frontcontroller-routing en PHP-FPM.

Gebruik HTTPS en geef de web-/CLI-gebruiker passende leesrechten. Publiceer geen `.env`, broncode, dependencies, backups of databasebestanden. De meegeleverde root-frontcontroller bestaat voor compatibiliteit met bestaande hosting; `public/` als documentroot heeft de voorkeur.

**Alleen lokaal testen:**

```bash
php -S localhost:8080 -t public
```

Open `/login`, log in met je aangemaakte account en ga naar `/admin#usenet`. Stel in:

- providerhost, poort, login en encryptie (`none`, `ssl`, `tls`); gebruik bij voorkeur de versleutelde NNTP-poort van je provider, meestal **563 + ssl**. `none`, vaak op 119, versleutelt login en inhoud niet; de TLS-keuze is geen ingebouwde STARTTLS-onderhandeling;
- retentie in dagen;
- `spots:free.pt,comments:free.usenet`;
- het maximumaantal artikelen per groep voor incrementele runs.
- **NNTP pipeline-diepte:** standaard **32**, instelbaar van **1 t/m 128**. Kies **1** als jouw provider problemen met pipelining heeft.

Sla de instellingen op. Controleer onder **Inhoud en weergave** of reacties en afbeeldingen naar wens zijn ingeschakeld. Op een schone installatie staat er nog geen inhoud in het overzicht: voer nu de eerste retrieve uit.

## Eerste retrieve en dagelijks bijwerken

Voer de commando's uit vanuit de projectroot, met dezelfde databaseconfiguratie als de website. Kies **een** van de onderstaande importmodi per run.

### NNTP-pipelining

**Beheerpanel > Usenet > NNTP pipeline-diepte** bepaalt hoeveel `HEAD`-/`ARTICLE`-verzoeken maximaal uitstaan op dezelfde verbinding. Standaard **32**, bereik **1-128**, **1** verstuurt steeds een verzoek en wacht op het antwoord. Dit geldt voor `--full`/`--all`, `--days`, `--since-last` (ook cron), `--comments-only` en `--refresh-spots`. De instelling wordt bij runstart ingelezen; bestaande installaties gebruiken 32 als de instelling nog niet is opgeslagen. Geen nieuwe databasemigratie vereist.

XOVER blijft in batches van maximaal 250 overzichtsregels werken; binnen zo'n batch worden bestaande/te oude artikelen eerst weggelaten en de overige verzoeken in begrensde pipelinevensters verstuurd. Ieder venster wordt volledig afgehandeld voor het volgende. Antwoorden worden op volgorde en message-ID gecontroleerd en via de php-nntp-commandhandlers verwerkt. Ontbrekende artikelen (423/430) worden afzonderlijk gemeld en overgeslagen; andere NNTP-/transportfouten breken de run expliciet af. De cursor verschuift pas na verwerking van een hele XOVER-batch. Reeds opgeslagen spots blijven behouden en worden bij een volgende gewone run niet opnieuw opgehaald. Retentie, datumgrensstop, moderatie en retentieopruiming blijven gelden.

De snelheidswinst hangt af van netwerkvertraging, provider, artikelgrootte en databasewerk; diepte 128 is niet automatisch sneller dan 32. Een lokale synthetische TCP-test met 131 artikelen en 20 ms wachttijd per venster gaf circa **4,0 s bij 1**, **0,15 s bij 32** en **0,06 s bij 128** voor zowel HEAD als ARTICLE. Dit is geen productiemeting of garantie voor een volledige importduur. Er wordt alleen een artikelantwoord tegelijk geparseerd, met veiligheidsgrenzen van 1 MiB per regel en 16 MiB totale antwoordtekst; overschrijding is een expliciete runfout. De php-nntp-socket gebruikt 10 s connecttimeout en de lees-timeout uit PHP `default_socket_timeout`.

**Update tijdens een lange import:** pauzeer cron en laat de actieve run eerst afronden of stop je eigen consoleproces gecontroleerd, voordat je de code bijwerkt. Een lopende run verandert niet van pipeline-instelling. Start een onderbroken eerste historische import opnieuw met **dezelfde `--full`/`--days`-modus en datuminstellingen**, niet blind met `--since-last`: dat is zonder voltooide historische cursor geen hervatting vanaf de laatst getoonde datum. Er wordt opnieuw XOVER gescand, maar opgeslagen spots worden overgeslagen. Wis geen spots/cursors en gebruik hiervoor geen `--refresh-spots`.

### Aanbevolen eerste import: recente spots

```bash
php bin/console retrieve --days=30 --verbose
```

Dit importeert spots en ingeschakelde reacties van de laatste dertig dagen. De spotgroep wordt eerst verwerkt, daarna de reactiegroep. Open vervolgens `/spots`.

Een datumimport begint bij de hoogste artikelnummers en stopt per groep na **3 opeenvolgende volledig oude batches** van maximaal 250 headers. Iedere ontvangen regel moet een geldige datum strikt voor de datumgrens hebben. Een recente of ongeldige regel, of een lege batch, zet de reeks terug op nul. De stop wordt ook zonder `--verbose` gemeld. De incrementele artikellimiet begrenst deze historische scan niet.

**Let op:** dit is een snelheidsafweging, geen gegarandeerde volledige datumzoekactie: artikelnummers zijn niet gegarandeerd op datum gesorteerd. Recente berichten die pas achter drie volledig oude batches staan, kunnen worden gemist. Dezelfde stopregel geldt voor reacties en `--refresh-spots`. Zonder datumgrens wordt wel de hele beschikbare historie gescand; `--since-last` behoudt zijn oplopende, begrensde verwerking.

### Alternatief: volledige retrieve binnen de ingestelde retentie

```bash
php bin/console retrieve --full --verbose
```

`--full` (alias: `--all`) begint bij de hoogste artikelnummers, maar slaat niets op dat ouder is dan de ingestelde retentie of de optionele datum **Spots ophalen vanaf**. De meest recente van deze grenzen geldt. Retentie `0` schakelt de leeftijdsgrens uit; een ingestelde begindatum blijft wel gelden. Ook deze scan stopt na drie opeenvolgende volledig oude batches volgens de bovenstaande regel. Oudere headers worden overgeslagen voordat de volledige spot wordt opgehaald. Je hoeft niet eerst `--days` en daarna `--full` te draaien.

### Nieuwe inhoud blijven ophalen

Na een geslaagde historische import, inclusief een normale datumgrensstop, staat de cursor op het groepseinde zoals gemeld bij het begin van de run. Gebruik daarna:

```bash
php bin/console retrieve --since-last --verbose
```

Dit verwerkt vanaf de oudste nog niet verwerkte positie maximaal de ingestelde artikellimiet per groep. Herhaal bij een achterstand. Zonder eerder opgeslagen cursor begint deze modus bij het oudste beschikbare artikel, **niet** automatisch bij de nieuwste spots; gebruik daarom bij een schone installatie bij voorkeur eerst `--days`.

Zonder modusoptie gebruikt `retrieve` ook `--since-last`. Retentie begrenst alle modi; `--days=N` kan die grens verder aanscherpen. De optionele adminbegindatum werkt eveneens als extra ondergrens. Retentie `0` bewaart alle leeftijden en schakelt automatische opruiming uit.

Een cronvoorbeeld voor na de eerste import, onder de deploymentgebruiker, elke vijf minuten:

```cron
*/5 * * * * cd /var/www/nnspotty && /usr/bin/flock -n /var/www/nnspotty/var/retrieve.lock /usr/bin/php bin/console retrieve --since-last >> /var/www/nnspotty/var/retrieve.log 2>&1
```

Pas projectpad en PHP-/flock-pad aan. Zorg dat `var/` schrijfbaar is voor deze gebruiker, laat niet meerdere handmatige/automatische retrieve-runs overlappen en beheer logrotatie. Het lock-/logbestand is geen applicatiedatabase; inhoud en cursors blijven in MySQL/MariaDB.

### Alleen reacties of bestaande spots aanvullen

```bash
# Alleen reacties, zonder eerst de spotgroep te scannen:
php bin/console retrieve --comments-only --days=7 --verbose

# Bestaande spotmetadata expliciet vernieuwen:
php bin/console retrieve --refresh-spots --days=7 --verbose
```

- Ook deze datumruns scannen de volledige beschikbare headers van de geselecteerde groep.
- `--comments-only` laat de spotcursor ongemoeid en respecteert de admininstelling voor reacties.
- `--refresh-spots` verwerkt alleen spots en vereist `--days`, `--all` of `--full`; normale runs slaan reeds opgeslagen spots over.
- Na het ophalen verwijdert de retriever alle spots ouder dan de ingestelde retentie, inclusief gekoppelde reacties en lokale reacties. Dat gebeurt in transactiebatches van maximaal 1000 spots, zonder maximum per run: ook 10.000 verlopen spots worden in dezelfde retrieve opgeruimd. De run is pas geslaagd als de opruiming klaar is; databasefouten laten de run expliciet mislukken. Bij retentie `0` wordt niets verwijderd. Maak voor de eerste run of een retentieverlaging een databasebackup; een grote achterstand kan de run verlengen.
- Beide opties zijn niet combineerbaar.
- `--verbose` toont ranges/datums en onder andere `te_oud`, `bestaand`, `geen_spot`, `ongeldig`, `verdwenen` en `opgeslagen`.

Ontbreken reacties? Controleer de juiste groep, beide reactieschakelaars en providerbeschikbaarheid. Geen opgehaalde reacties betekent niet dat er op Usenet geen reacties zijn. Bij een poort/encryptiemismatch kan bijvoorbeeld `SSL routines::wrong version number` verschijnen.

## Newznab en NZB downloaden

1. Controleer het publieke `APP_URL` en schakel de API in onder `/admin#newznab` (standaard uit).
2. Maak onder `/preferences#api-key` een persoonlijke key. Hij verschijnt eenmaal en wordt alleen gehasht opgeslagen. Roteren/intrekken maakt oude keys en downloadlinks direct ongeldig.
3. Voeg in Prowlarr een **Generic Newznab**-indexer toe:

   | Instelling | Waarde |
   |---|---|
   | URL | `https://nnspotty.hopseflop.nl` of je eigen NNSpotty-origin |
   | API Path | `/api` |
   | API Key | Je persoonlijke NNSpotty-key |

4. Test niet alleen de verbinding, maar ook een titelzoektocht en daadwerkelijke NZB-download. Koppel Prowlarr vervolgens aan Sonarr/Radarr volgens hun eigen configuratie.

Ondersteund:

| Aanvraag/filter | Werking |
|---|---|
| `t=caps` | Publieke XML-capabilities na globale inschakeling; zonder key, zonder XXX-categorieen |
| `t=search` | Titelzoeken via `q` |
| `t=tvsearch` | Titelzoeken plus numerieke `season` en `ep` |
| `t=movie` | Titelzoeken plus `year` |
| `t=get` | NZB-download met key en zichtbaarheidstoegang |
| `cat` | Meerdere Newznab-categorieen als OF-selectie |
| `limit`, `offset` | Paginering, maximaal 100 resultaten per aanvraag |
| `maxage`, `extended=0|1` | Leeftijdsfilter en uitgebreide attributen |

Standaard hoofdcodes: **1000 Console, 2000 Movies, 3000 Audio, 4000 PC, 5000 TV, 6000 XXX, 7000 Books, 8000 Other**, met afleidbare subcategorieen. Ook UHD 2045/5045, PS4 1180 en Xbox One 1140. Spotweb-specifieke 100000+-duplicaatcodes worden niet overgenomen. Newznab-categorie-OF staat los van de EN-filters in het web-overzicht.

Zoeken/downloaden vereist een geldige persoonlijke key en volgt globale/persoonlijke erotica-instellingen. Limiet: **120 aanvragen per minuut per key**. API-verkeer start geen browsersessie. IMDb/TVDB-ID-filters worden niet geadverteerd en expliciet geweigerd; geen ongerichte fallbackzoektocht.

Feeds bevatten keydragende downloadlinks: bescherm feeds, client-/proxy-/accesslogs en browserhistorie. Laat productie-accesslogs querystrings weglaten. De gewone **NZB**-knop gebruikt sessieautorisatie en werkt ook met Newznab uitgeschakeld. De downloadservice begrenst gecomprimeerde invoer en uitgepakte XML tot minder dan 16 MiB.

## SABnzbd instellen

1. `/admin#downloads`: configureer de centrale verbinding met basis-URL en **volledige SABnzbd API-key**, niet de beperkte NZB-key. Laat `/api` weg; een reverse-proxypad zoals `/sabnzbd` mag wel. Schakel in, sla op en test de opgeslagen verbinding.
2. Stel exacte SABnzbd-categorienamen in per spottype: Films, Series, Boeken, Erotica, Afbeeldingen, Muziek, Livesets, Podcasts, Luisterboeken, Spellen, Applicaties en Overig. Leeg gebruikt SABnzbd Default; een niet-lege categorie moet op de doelhost bestaan, zonder stille fallback. De test toont beschikbare categorieen.
3. Gebruikers stellen onder `/preferences#downloads` een eigen koppeling in. De globale categoriemapping geldt ook voor persoonlijke hosts. Gewone gebruikers mogen alleen publiek routeerbare hosts gebruiken; alleen admins mogen interne/LAN-hosts en de centrale koppeling gebruiken.
4. Klik **SABnzbd** bij een spot, controleer verbinding/titel/categorie en bevestig. NNSpotty uploadt de gevalideerde NZB als multipart-inhoud. Veranderde host/key/mapping vereist een nieuwe bevestiging; dezelfde bevestiging veroorzaakt geen tweede upload.

**Downloads** in de header opent de live slide-in. Verversen gebeurt elke vijf seconden zolang paneel en tabblad zichtbaar zijn; bij fouten stopt polling tot je opnieuw probeert. Wachtrijen boven dertig items hebben vorige/volgende. Het paneel ondersteunt Escape, focusherstel, mobiel, thema's en verminderde beweging.

Je ziet de hele externe wachtrij van je gekozen host, ook jobs die niet vanuit NNSpotty zijn gestuurd. Admins kunnen ook de centrale wachtrij kiezen. Externe jobs volgen niet automatisch NNSpotty-eroticafilters; de lokale spot-/verzendhistorie wel.

De laatste dertig lokale verzendpogingen blijven zichtbaar, ook bij een uitgeschakelde/onbereikbare/verwijderde koppeling. **Verzonden** betekent door SABnzbd geaccepteerd, niet voltooid. **Geweigerd**, **Uitkomst onbekend** of een nog onbevestigde poging blijven expliciet herkenbaar. Stuur bij een timeout niet blind opnieuw: SABnzbd kan de upload al hebben ontvangen. Voltooide SABnzbd-historie synchroniseren, pauzeren/verwijderen en NZBGet zijn nog niet beschikbaar.

Keys worden herstelbaar in de database bewaard, nooit teruggetoond in HTML/browser-JSON of applicatie-audits. Leeg keyveld behoudt de bestaande key; verwijderen wist de verbinding, niet downloads/historie. Gebruik HTTPS op publieke hosts; HTTP versleutelt de key niet. DNS/adressen worden ook bij aanvragen gecontroleerd en vastgepind; TLS-verificatie blijft aan, redirects/omgevingsproxies uit.

## TheTVDB: films, series en afleveringen

1. `/admin#metadata`: voer je **API V4-key**, eventueel vereiste abonnee-PIN en taal in, schakel globaal in en test de opgeslagen toegang. Controleer je eigen TVDB-licentie.
2. Iedere gebruiker, ook de admin, schakelt onder `/preferences#metadata` persoonlijk metadata in. Globaal en persoonlijk staan standaard uit; globaal uit blokkeert iedereen zonder persoonlijke keuzes te wissen.
3. Open een volledige filmdetail of serie-/afleveringsdetail. Metadata verschijnt asynchroon; oorspronkelijke titel, omschrijving, reacties en NZB/SAB/lightbox blijven bruikbaar.

Automatisch matchen vereist een unieke exact genormaliseerde titel/alias/vertaling, plus hetzelfde jaar bij films. Nummerprefix `03. ` en suffixen zoals IMAX, Theatrical Version, 4K, Ultra HD en Blu-ray worden opgeschoond, numerieke titels zoals 1917 blijven intact. Gelijknamige remakes worden niet gegokt; ontbrekend jaar kan een automatische filmkeuze voorkomen.

`SxxEyy`/`NxYY` gebruiken officiele afleveringnummering en verificatie van serie-ID, seizoen en nummer. Seizoens-/multiafleveringpakketten blijven op serieniveau. Ontbreekt het gevraagde paar, dan alleen seriedata met een expliciete onbevestigde seizoen-/afleveringsmelding, geen omzetting naar een andere ordering. Zo bood de publieke TVDB-pagina van De Bondgenoten bij onderzoek alleen officiele seizoenen 1-3, geen S04E01. Filmcollecties/trilogieen en meerdere BD-discs worden niet automatisch aan een enkele film gekoppeld.

De hero toont beschikbare poster/fanart of een widescreen afleveringstill, synopsis, datum, speelduur en genres. Fanart wordt volledig en proportioneel rechts ingepast, zonder uitsnijden boven/onder of uitrekken; de linkerkant vloeit over in een rustige tekstachtergrond met thema-afhankelijke overlay. Dit geldt ook voor muziek en kleine previews. Op smalle schermen kan vrije ruimte boven/onder ontstaan: volledige beeldinhoud gaat voor een beeldvullende uitsnede. De bestaande inhoud bepaalt de bannerhoogte. Daaronder cast/crew en beschikbare bedrijven/netwerken; geen foto betekent een ronde lokale placeholder. Geen fictieve ratings: TVDB-`score` is populariteit, geen beoordeling.

De kleine preview in het overzicht hergebruikt uitsluitend een **al opgehaalde, nog geldige match voor dezelfde spot, taal en brongegevens**. Zo verschijnen daar ook de TVDB-titel, synopsis, artwork en cast zodra de volledige detailview deze heeft opgehaald. De preview start nooit een TVDB-zoekactie of cachevernieuwing. Zonder bruikbare match, bij verlopen cache of blokkering blijft de oorspronkelijke spotweergave staan; open de volledige detailview om metadata op te halen of te vernieuwen. Globale en persoonlijke toestemming en de afbeeldingsvoorkeur blijven gelden. Artwork-URL's staan in de database; de afbeeldingsbestanden worden rechtstreeks door de browser geladen, niet lokaal opgeslagen. De overzichtsregels zelf blijven ongewijzigd.

**TVDB link** linksboven opent status/opnieuw controleren. Alleen admins kunnen een gedeelde film/serie/aflevering zoeken/kiezen, automatisch matchen herstellen of metadata blokkeren. Een onvindbare handmatige aflevering overschrijft geen geldige keuze. De drawer blijft bruikbaar na asynchrone vervanging en sluit met focusherstel.

Cache: providerrecords en geslaagde matches **zeven dagen**, zoeken/ontbrekende/ambigue matches **een dag**, fouten **vijf minuten**. Opnieuw controleren respecteert TTL; centraal budget **90 uitgaande requests/minuut**, locks en begrensde HTTPS-aanvragen. Parserupdates herbeoordelen oude matchresultaten met behoud van handmatige keuzes/blokkeringen; providerrecords houden hun eigen TTL.

De gebruiker geeft toestemming om de titel met TVDB te delen en artwork rechtstreeks in de browser te laden. Globaal afbeeldingen uit onderdrukt ook TVDB-posters/fanart/cast. De lokale bronvermelding **Metadata van TheTVDB** met donker/licht-logo blijft zichtbaar bij getoonde metadata en linkt naar het betreffende record, conform de [attributierichtlijn](https://www.thetvdb.com/api-information#attribution). Zonder TVDB-data geen attributie. Key/PIN/token staan herstelbaar in de database, niet in HTML/audits; bescherm backups.

## Muziekmetadata: MusicBrainz en Cover Art Archive

1. Zet onder `/admin#music-metadata` **Muziekmetadata globaal toestaan** aan. Geen API-key, databasedump of OAuth-registratie nodig voor deze publieke leesfunctie. Stel `APP_URL` in op de publieke project-/contact-URL van je installatie: deze staat in de verplichte herkenbare User-Agent. Gebruik geen geheimdragende URL.
2. Iedere gebruiker, ook de admin, zet onder `/preferences#music-metadata` **Muziekmetadata voor mij ophalen en tonen** aan. Globaal en persoonlijk staan standaard uit. De muziekschakelaars staan los van TVDB; globaal uit bewaart persoonlijke voorkeuren.
3. Open de volledige muziekspot. Metadata verschijnt asynchroon, zonder de oorspronkelijke omschrijving, titel, reacties of NZB/SAB-acties te vervangen. Een verse match wordt ook in de kleine preview hergebruikt, zonder providerrequests of cachevernieuwing vanuit het overzicht.

Slim herkennen ondersteunt `Artiest - Album (jaar)`, `Artiest-Album-LP-32BIT-WAVPACK-jaar-groep`, underscoretitels en `jaar - Artiest - Album [broninformatie]`. FLAC/MP3/WAVPACK/DTS-HD, bitdiepte, LP/vinylbronnotities en een afsluitend Deluxe/(Deluxe Edition) worden voor albumgroepdiscovery opgeschoond. Numerieke albumtitels zoals **1989** en **1999** blijven behouden. Een vooraan geplaatst bronjaar of een jaar bij LP/vinyl/CDRIP wordt niet als verplicht oorspronkelijk albumjaar gebruikt: een heruitgave kan nieuwer zijn dan het album. Een overig afzonderlijk slotjaar blijft een exacte matchvoorwaarde.

Albumgroepdiscovery en adminzoeken met `Artiest - Album` gebruiken een gerichte [MusicBrainz-zoekquery](https://musicbrainz.org/doc/MusicBrainz_API/Search) met **artiest EN albumtitel**, niet een brede zoekactie op alle titelwoorden. Speciale zoektekens worden letterlijk ontsnapt. Maximaal een zoekrequest met 25 kandidaten per actie; alleen een unieke exact genormaliseerde artiest-/albumtitelcombinatie wordt automatisch gekozen, nooit blind de eerste hit. Een afsluitende live-/concertnotitie wordt alleen voor het **zoeken** weggelaten: voor automatische selectie blijft de volledige concerttitel vereist. `Simply Red - Holding Back The Years (Live In Santiago 2026)` kan dus suggesties opleveren, maar wordt niet automatisch aan de studioversie gekoppeld. Ontbrekende specifieke liveopnamen kunnen handmatige keuze nodig hebben. Gelijke titels en andere artiesten worden niet gegokt. Geen garantie dat iedere titel gevonden wordt; podcasts/luisterboeken krijgen geen muziekkoppeling.

Albums, singles en EPs worden standaard op **albumgroepniveau** gekoppeld: titel, artiest, eerste uitgavedatum, type en beschikbare genres. Dit bewijst geen specifieke persing, remaster of bestanden. Een discografie of expliciete `Artiest: Naam` krijgt een eigen artiestweergave met beschikbare soort, land, begin-/einddatum en toelichting; geen volledige discografie, uitgebreide biografie of gegarandeerde artiestfoto. Albumhoezen zijn vierkant; artiesten krijgen een duidelijk decoratief muzieksymbool, geen verzonnen portret.

**MusicBrainz link** opent het gedeelde status-/beheerpanel. Alleen admins kunnen zoeken, een albumgroep, specifieke uitgave of artiest koppelen, automatisch matchen herstellen of metadata blokkeren. Zoekresultaten tonen beschikbare artiest/jaar/toelichting en editiegegevens. Alleen een expliciet gekozen **specifieke uitgave** krijgt een beschikbare tracklist (maximaal 200 tracks), label, land en speelduur; ontbrekende speelduur wordt niet verzonnen. Wijzigingen gelden ook voor andere gebruikers en de preview.

Metadata/providerrecords worden **zeven dagen** in MySQL/MariaDB gecachet; zoekacties en negatieve matches een dag, fouten vijf minuten. `db cleanup` verwijdert verlopen providercache, niet instellingen, het aanvraagtijdstip of handmatige keuzes. Geen massale metadata-import tijdens retrieve. Een persistente installatielimiet laat MusicBrainz-requeststarts minimaal 1,1 seconde uiteen liggen, ook over PHP-workers. Cache/spotlocks voorkomen dubbel werk; bezette limieten en providerfouten blijven zichtbaar. Bij meerdere installaties achter hetzelfde publieke IP moet je de gezamenlijke limiet zelf bewaken. HTTPS-certificaatcontrole blijft aan; vaste providerhost, geen server-side redirects/proxies, begrensde tijd/antwoordgrootte.

De verbeterde discoveryversie beoordeelt oude automatische/negatieve matches opnieuw bij de volgende detailweergave; preview haalt nog steeds niets op. Bestaande handmatige koppelingen en blokkeringen blijven behouden zolang de oorspronkelijke spottitel/categorie/subcategorieen gelijk zijn. Voor alleen de matchingverbetering geen migratie, cacheflush of retrieve nodig; de optionele fanartuitbreiding hieronder heeft wel een migratie.

De provider krijgt de zoekterm bij detail-discovery; `APP_URL` wordt meegestuurd als contactinformatie. Alleen afbeeldings-URL's worden opgeslagen: de browser haalt hoezen via [Cover Art Archive](https://coverartarchive.org/) en diens redirects naar Internet Archive op. Niet ieder album heeft een hoes; ontbrekende afbeeldingen tonen de gewone placeholder. **Afbeeldingen uit** onderdrukt ook externe muziekhoezen. Getoonde metadata linkt via **Metadata van MusicBrainz** naar de bron, met aparte Cover Art Archive-vermelding bij artwork.

Bronnen/voorwaarden: [MusicBrainz API](https://musicbrainz.org/doc/MusicBrainz_API), [aanvraaglimieten](https://musicbrainz.org/doc/MusicBrainz_API/Rate_Limiting), [datalicenties](https://musicbrainz.org/doc/About/Data_License), [Cover Art Archive API](https://musicbrainz.org/doc/Cover_Art_Archive/API). Publieke MusicBrainz-API gratis voor niet-commercieel gebruik; stem commercieel gebruik af met MetaBrainz. Kerndata is CC0; aanvullende data heeft eigen CC BY-NC-SA-voorwaarden. Artwork valt niet automatisch onder de datalicentie: respecteer de rechten en voorwaarden voor jouw gebruik.

### Optionele artiestachtergronden via fanart.tv

Na `php bin/console db migrate` (migratie `20261019_0018_music_fanart`) kun je naast MusicBrainz een artiestachtergrond van [fanart.tv](https://fanart.tv/) gebruiken:

1. Onder `/admin#music-metadata`, bij **Artiest-fanart: fanart.tv**, vul je de API-key in en zet je **Artiest-fanart globaal toestaan** aan.
2. Iedere gebruiker, ook de admin, zet onder `/preferences#music-metadata` **Artiest-fanart voor mij ophalen en tonen** aan. Deze extra schakelaars staan standaard uit; bestaande muziektoestemming blijft nodig.
3. Open een volledige muziekspot met een geslaagde match. De exacte MusicBrainz-artiest-ID wordt gebruikt voor fanart, nooit alleen de artiestnaam. Album, gekozen specifieke uitgave en artiest/discografie kunnen dezelfde artiestachtergrond hergebruiken. Credits met meerdere artiesten en Various Artists krijgen geen willekeurige artiestachtergrond.
4. De beschikbare `artistbackground` met de meeste stemmen wordt volledig en proportioneel rechts in de muziekhero ingepast, zonder uitsnijden boven/onder. Links vloeit de afbeelding over naar een rustige tekstzone; een thema-afhankelijke leesbaarheidslaag en **Artiest-fanart: fanart.tv**-bronlink blijven aanwezig. De vierkante albumhoes blijft van Cover Art Archive. Ontbrekend artwork behoudt de bestaande neutrale achtergrond; een laadfout van de afbeelding gebruikt de gewone placeholder.

Fanart is een aparte verrijking: een storing verwijdert geen geslaagde MusicBrainz-match. Bij providerfouten zie je een waarschuwing in plaats van een stille mislukking; opnieuw proberen na vijf minuten. De installatieserver vraagt het vaste HTTPS-endpoint `webservice.fanart.tv/v3/music/{artiest-MBID}` op met de key. Alleen gevalideerde HTTPS-artwork-URLs van `assets.fanart.tv` worden gepubliceerd; de browser haalt de afbeeldingen rechtstreeks op, zonder API-key. Er worden geen afbeeldingsbestanden lokaal opgeslagen. De API-key staat in de database zoals de bestaande providergeheimen; beveilig database en backups. Leeg keyveld bewaart de bestaande key; uitzetten plus **Opgeslagen key wissen** verwijdert deze.

Artiestcache wordt gedeeld over spots en gebruikers die toestemming hebben: zeven dagen bij artwork, een dag bij ontbrekend artwork en vijf minuten bij fouten. Gelijktijdige opvragingen voor dezelfde artiest/key worden gelockt; geen automatische retries. De kleine preview leest uitsluitend verse bestaande muziek- en fanartcache en schrijft/vernieuwt niets. **Afbeeldingen uit**, globale/persoonlijke muziek-opt-out en globale/persoonlijke fanart-opt-out blokkeren ophalen en tonen; globaal uit bewaart persoonlijke voorkeuren. Een gewijzigde key gebruikt geen oude foutcache. Oude muziekresultaten zonder artiest-ID worden bij detail opnieuw beoordeeld, zonder geldige handmatige keuzes/blokkeringen te verliezen. Geen retrieve/backfill nodig; `db cleanup` ruimt verlopen artworkcache mee op.

Fanartbeschikbaarheid is niet gegarandeerd. Een API-key geeft niet automatisch rechten op de afbeeldingen; controleer de actuele [API-informatie en voorwaarden](https://fanart.tv/api-info/) en rechten op artwork, zeker bij commercieel gebruik. NNSpotty bundelt geen fanart.tv-clientbibliotheek of artwork; deze koppeling gebruikt een eigen begrensde HTTPS-client. Officiele fanart.tv-documentatie gaf tijdens ontwikkeling HTTP 403; geauthenticeerde productieacceptatie met je eigen key blijft nodig.

## Console, onderhoud en updates

```bash
php bin/console help

php bin/console db migrate
php bin/console db status
php bin/console db health
php bin/console db cleanup --days=90

php bin/console user list
php bin/console user create <username> <password>
php bin/console user create <username> <password> --admin=1
php bin/console user edit <username> --admin=1
php bin/console user edit <username> --admin=0
php bin/console user password <username> <nieuwWachtwoord>
php bin/console user delete <username>
```

Vervang placeholders en quote echte argumenten waar nodig. De retrieve-commando's staan hierboven.

`db cleanup` verwijdert oude **lokale reacties**, retrieve-runs, loginpogingen en auditregels volgens de dagengrens, plus verlopen TVDB-providercache onafhankelijk van die grens. Dit is geen algemene spotretentie-purge; voer het niet blind uit als je die historie wilt bewaren.

Nieuwtellers gebruiken de **eerste importtijd** van een spot, niet de publicatiedatum: ook een nieuw geimporteerde oudere post kan nieuw voor je zijn. Refresh/duplicaten maken bestaande spots niet opnieuw nieuw. Alleen volledige overzicht-/detailbezoeken bewaren browseactiviteit; achtergrondbatches, Newznab, afbeeldingen, TVDB en SABnzbd-polling schuiven de bezoekgrens niet op. Zoektekst, actieve EN-filters en erotica-zichtbaarheid blijven van toepassing.

Migratie `20261018_0014_browse_visits` bewaart de laatste browseactiviteit per username, verhoogt de importtijdprecisie naar microseconden en voegt een tijdindex toe. Geen nieuwe retrieve nodig. Pauzeer verkeer/retrieval en maak een backup voor de schemawijziging; de wijziging aan de spotstabel kan bij een grote index tijd kosten.

### Bestaande installatie bijwerken

Maak een beveiligde databasebackup en configuratiebackup; pauzeer retrieve-jobs en webverkeer bij schemawijzigingen. Voer vanuit de bestaande deploymap op `nnspotty.hopseflop.nl` uit:

```bash
umask 022
git status --short
# Los onverwachte lokale wijzigingen eerst gericht op.
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php bin/console db migrate
php bin/console db status
php bin/console db health
php tests/run.php
curl -fsS https://nnspotty.hopseflop.nl/health
curl -fsS -o /dev/null -w 'CSS: %{http_code}\n' https://nnspotty.hopseflop.nl/assets/spotty.css
```

Hervat verkeer/jobs na geslaagde controles en herlaad met Ctrl+F5. Niet iedere presentatie-update vereist een migratie of nieuwe retrieve; volg de changelog en [Linux-serverchecks](./docs/linux-server-testing.md).

`composer.json` en `composer.lock` horen in Git; `vendor/`, gegenereerde autoloads, `.env`, Composer-authenticatie, runtime/logs, dumps en coverage niet. Installeer dependencies op de server. Overschrijf de bestaande `.env` niet.

**Oude installaties:** `.env` en `vendor/` waren eerder gevolgd; de eenmalige opschoning kan die bestanden bij pull verwijderen. Volg eerst de [backup-/restoreprocedure](./docs/linux-server-testing.md#git-opruimen-inhoudsinstellingen-en-afbeeldingsvergroting). `.gitignore` verwijdert geen oude secrets uit Git-historie: roteer credentials die ooit zijn vastgelegd.

Bij upgrade vanaf de oude generieke headerindex: migreer, controleer de Spotnet-groepsrollen en haal echte spots op met `retrieve --days=30 --verbose`. Oude headers blijven bewaard maar worden niet als spots getoond; `db reindex-spots` is vervallen. Categorie-/commentmigraties herstellen bestaande data waar mogelijk; `--refresh-spots` kan ontbrekende bronmetadata aanvullen.

## Spotter whitelist en blacklist

Onder **Beheerpanel > Moderatie** kun je de [Spotlist-whitelist](https://spotlist.store/spotnet/whitelist.xml) en [blacklist](https://spotlist.store/spotnet/blacklist.xml) synchroniseren en sleutelmoderatie inschakelen. Standaard staat dit uit: installatie/migratie doet geen externe requests. Beide URL's staan vast; geen willekeurige externe/internal URL-invoer. De console biedt dezelfde bediening:

```bash
php bin/console spotters sync
php bin/console spotters enable
php bin/console spotters status
# Tijdelijk uitschakelen, met behoud van lijsten en opgeslagen identiteit:
php bin/console spotters disable
```

Voor een schone installatie: migreer, synchroniseer en activeer **voor** de eerste `retrieve --all`/`--full` of `--days=<n>`. De gewone retrieve blijft retentie en de optionele begindatum respecteren. Haal deze update niet binnen terwijl een oude retrieve nog draait; stop die gecontroleerd of wacht tot hij klaar is. Het opnieuw leegmaken van je spotdatabase is een aparte beheerhandeling: deze feature verwijdert geen bestaande spots.

### Beoordeling en badges

- De parser controleert de RSA/SHA1-handtekening over `<message-ID>` uit `X-User-Signature` met de publieke `X-User-Key`. Spotnet `-s`/`-p`-encoding en ontbrekende base64padding worden ondersteund. Alleen exponent 65537 wordt geaccepteerd, omdat de externe lijsten uitsluitend moduli publiceren; een andere exponent met dezelfde modulus mag geen identiteit opleveren.
- Een SHA256-fingerprint van de canonieke RSA-modulus koppelt de identiteit aan de lijsten, **niet de spotternaam** of een korte botsingsgevoelige gebruikers-ID. De eerste import bewaart de fingerprint en verificatiestatus in de database.
- Een gecontroleerde sleutel op de blacklist wordt tijdens retrieve **niet opgeslagen**, ook als hij tevens op de whitelist staat. De NNTP-header is hiervoor wel nodig; dit bespaart dus niet alle headerverkeer. Verbose batchlogs bevatten `blacklist` en `onbevestigd`; de runmelding vermeldt het aantal wegens blacklist overgeslagen berichten.
- Een gecontroleerde sleutel op de whitelist krijgt een **Whitelist**-badge bij de spotter in overzicht (als de spotterkolom zichtbaar is), preview en volledige detail, ook na TVDB-verrijking. Niet op de whitelist staan is geen reden om een spot te weigeren.
- Ontbrekende, ongeldige of niet-ondersteunde handtekeningen blijven behouden, zonder whitelist-badge en zonder blokkade op alleen een naam. Oude XML-signature-only varianten en afwijkende exponenten blijven onbevestigd. Dit is compatibiliteitsgedrag, geen garantie dat spam volledig wordt tegengehouden.
- De badge betekent uitsluitend dat een message-ID met de betreffende sleutel is ondertekend en die sleutel op de gekozen externe whitelist staat. Het is **geen inhoudscontrole, antivirusuitslag of bewijs van de echte persoon**; de inhoud/XML is met deze controle niet cryptografisch gevalideerd.
- Bestaande spots worden niet automatisch verwijderd, verborgen of via NNTP opnieuw gecontroleerd. Bij een latere lijstwijziging verandert de badge van reeds gecontroleerde spots mee (eventueel **Blacklist**); overslaan geldt voor volgende te importeren spots. Een lopende retrieve gebruikt zijn startsnapshot. Reacties worden niet op spottersleutels gemodereerd. Oude naamregistraties blijven apart en hebben geen effect op badges of filtering.

### Synchronisatie en fouten

Bij ingeschakelde moderatie controleert elke normale spot-retrieve of de laatste succesvolle synchronisatie ouder is dan 24 uur; gerichte `--comments-only`-runs doen dit niet. Handmatig synchroniseren kan altijd, ook als filtering uit staat. De applicatiedatabase bewaart de sleutels, namen, laatste poging/succes, fout en waarschuwing. Downloads gebruiken HTTPS met certificaatcontrole, geen redirects, een maximale omvang van 4 MiB per feed, maximaal 20.000 vermeldingen en begrensde connectie-/requesttimeouts.

Beide feeds worden eerst opgehaald/gevalideerd en daarna **samen transactioneel** vervangen. Ontbrekende/lege/onbruikbare feeds of een transportfout laten beide vorige lijsten intact. Ongeldige sleutelvermeldingen in een verder bruikbare feed worden overgeslagen met een opgeslagen waarschuwing in admin, console en retrieve-log; een feed zonder geldige sleutels wordt geweigerd. Dubbele moduli worden samengevoegd, verschillende sleutels met dezelfde naam blijven verschillend.

Als dagelijks vernieuwen mislukt, gebruikt retrieve de laatst succesvolle lijsten met een expliciete waarschuwing. Als nog nooit een geldige synchronisatie is gelukt, **faalt een retrieve met ingeschakelde moderatie**, zodat er niet stilzwijgend zonder blacklist wordt geimporteerd. Een databasefout blijft een fout. Bij cURL-code 60: herstel de CA-configuratie van zowel CLI-PHP als PHP-FPM (`curl.cainfo`/systeemcertificaten); TLS-controle niet uitschakelen. Deze lijsten worden niet tijdens paginaweergave of per spot extern opgehaald.

## Tests en controles

```bash
# Zonder echte Usenet-/TVDB-/SABnzbd-verbinding of database:
php tests/run.php
# Equivalent:
composer test
```

De suite bevat protocol-, parser-, categorie-, metadata-, NZB-, opmaak- en rendererchecks. Database-/HTTP-integratietests gebruiken synthetische providers en een **aparte** MySQL/MariaDB-testserver:

```bash
export NNSPOTTY_TEST_DSN='mysql:host=127.0.0.1;port=3306;charset=utf8mb4'
export NNSPOTTY_TEST_USER='nnspotty_test'
read -rsp 'Wachtwoord testdatabase: ' NNSPOTTY_TEST_PASSWORD; echo
export NNSPOTTY_TEST_PASSWORD
php tests/run.php --integration
# Optioneel: synthetische browsebenchmark (standaard 300.000 spots, 1.000-2.000.000 toegestaan).
# Kan minuten duren en meerdere GiB opslag gebruiken. Alleen een aparte testserver gebruiken.
NNSPOTTY_PERF_SPOTS=300000 php tests/browse-performance.php
# Grotere fixture, bijvoorbeeld:
NNSPOTTY_PERF_SPOTS=1300000 php tests/browse-performance.php
unset NNSPOTTY_TEST_DSN NNSPOTTY_TEST_USER NNSPOTTY_TEST_PASSWORD
```

Gebruik **geen productiecredentials**: de testgebruiker moet tijdelijke testschema's kunnen maken/verwijderen. Zie [Linux-serverchecks](./docs/linux-server-testing.md) voor voorwaarden, asset-/routechecks en echte productieacceptatie.
De benchmark verwijdert zijn eigen willekeurig benoemde testschema na afloop, vergelijkt oude/nieuwe zoekresultaten, controleert batchgrootte en keysetvolgorde, begrenst gewone eerste batches tot maximaal 512 gemeten indexstappen en bewaakt maximaal 32 MiB PHP-piekgeheugen. Looptijden zijn metingen, geen hardware-onafhankelijke SLA. De HTTP-suite controleert met twee echte PHP-workers dat een drie seconden vertraagde NNTP-afbeelding een batch en statusrequest van dezelfde sessie niet blokkeert (samen minder dan 1,5 seconde).

Een fixturetest bewijst geen providerbeschikbaarheid. Controleer met je eigen accounts een echte spot, reacties, afbeelding, NZB-download, Prowlarr-zoekactie, SABnzbd-upload/wachtrij en TVDB-match. Test mobiel/desktop en donker/licht. Gedeelde VS Code-taken bieden syntaxcontrole en lokaal serveren; aanbevolen extensies staan in [extensions.json](./.vscode/extensions.json).

## Beperkingen en vervolgwerk

- Nog geen installatiewizard, ingebouwde scheduler/jobqueue of begin-/einddatumkalender voor historische retrieve; gebruik voorlopig console/cron en retentie in dagen.
- Spottercontrole ondersteunt RSA/SHA1 over message-ID met exponent 65537, geen volledige header-/XML-/commenthandtekeningverificatie of echte-persoonsidentificatie. Ontbrekende/ongeldige handtekeningen blijven toegestaan. De externe Spotlist-inhoud is een moderatiebron, geen garantie voor betrouwbare of veilige inhoud.
- Niet alle exotische Spotnet-tags/legacy-XML, dagelijkse/absolute afleveringscodes of multi-episodevarianten worden volledig ondersteund.
- Geen Usenet-publicatie van lokale reacties, playback, reviews of verzonnen verificatie-/beoordelingsbadges.
- Geen NZBGet-forwarding, afgeronde SABnzbd-historiesynchronisatie of SABnzbd-pauzeer/verwijderbediening.
- Geen aparte IMDb-provider, klikbare cast-/crew-/studio-/netwerkontdekfilters of Newznab IMDb/TVDB-ID-zoeken. Externe IDs worden waar beschikbaar wel bewaard voor vervolgwerk.
- Geen automatische filmkeuze voor onduidelijke collecties of niet-unieke titel-/jaarmatches.
- Muziekmetadata kiest geen specifieke editie automatisch, biedt geen volledige discografie/biografie en geen artiestfoto's; tracklists horen bij een handmatig gekozen uitgave. Hoezen kunnen ontbreken en zijn geen inhoudsverificatie.
- Roadmap: metadata voor games via IGDB/RAWG, software via AppStream-catalogi en boeken via Open Library. Dit zijn nog geen geimplementeerde koppelingen of definitieve providerkeuzes; API-toegang, matching, licentie, attributie en limieten moeten per domein worden uitgewerkt.
- Geimporteerde inhoud blijft onbevestigd; erotica-classificatie gebruikt categoriecodes, geen inhoudsanalyse. Zorg zelf voor passende toegang, providerabonnementen en rechten op inhoud.

## Documentatie en bijdragen

- [Installatie en hosting/troubleshooting](./docs/installation.md)
- [Linux-serverupdates, backups en acceptatietests](./docs/linux-server-testing.md)
- [Architectuur](./docs/architecture.md)
- [Projectrichtlijnen en productdoelen](./docs/project-guidelines.md)
- [Versioning en codenames](./docs/versioning.md)
- [Second brain: besluiten en vervolgwerk](./docs/second-brain.md)
- [Changelog](./CHANGELOG.md)
- [UI-referenties](./docs/screenshots/)
- [Huisstijlpresentatie](./docs/branding/presentation.png) en originele [logo](./docs/branding/logo.png), [favicon](./docs/branding/favicon.png), [placeholder](./docs/branding/placeholder.png)
- [Copilot-projectinstructies](./.github/copilot-instructions.md)

Houd bij nieuwe of gewijzigde features ook deze README actueel: functionaliteit, configuratie, installatie/retrieve-commando's, beperkingen en eventuele third-party afhankelijkheden. Werk bij besluiten/scopewijzigingen tevens de second brain en relevante vervolgdocumentatie bij. Productdoelen in de richtlijnen zijn niet automatisch al geimplementeerde functies.

## Third-party applicaties, code en tools

### Runtime en gebruikte code

| Project | Gebruik in NNSpotty |
|---|---|
| [PHP](https://www.php.net/) | Backend en console; PDO/MySQL, DOM/SimpleXML, iconv, zlib en cURL voor opslag, parsing, decompressie en HTTP |
| [php-nntp / rvdv/nntp](https://github.com/robinvdvleuten/php-nntp) | NNTP-client van Robin van der Vleuten, via Composer; vastgezet op v1.0.0, MIT |
| [Composer](https://getcomposer.org/) | Dependencybeheer en PSR-4-autoloading; lockfile voor reproduceerbare installatie |
| [MySQL](https://www.mysql.com/) / [MariaDB](https://mariadb.org/) | Applicatiedatabase, indexen, instellingen, historie en caches |
| [Spotlist](https://spotlist.store/) | Optionele externe Spotnet-whitelist/blacklist met publieke RSA-moduli; namen zijn labels, geen geverifieerde echte identiteit |
| [Bootstrap 5.3.7](https://getbootstrap.com/) via [jsDelivr](https://www.jsdelivr.com/) | CSS/componentstyling; interacties via eigen JavaScript en native browserdialogen, geen vereiste Bootstrap-JavaScriptbundle |
| [libcurl / cURL](https://curl.se/) | Via PHP-cURL voor TVDB/SABnzbd; CLI-curl voor serverchecks |

`rvdv/nntp` is de enige externe Composer-package in de huidige lockfile. Er is geen verplicht frontendframework, npm-build, PHPUnit- of PHPStan-installatie; de applicatie heeft een eigen PHP-testharnas. Algemene runtime-/dependencylicenties blijven bij hun respectieve projecten; `composer.json` vermeldt MIT voor NNSpotty.

### Providers, protocollen en externe applicaties

| Project | Relatie |
|---|---|
| [TheTVDB](https://www.thetvdb.com/) en [API V4-specificatie](https://thetvdb.github.io/v4-api/) | Optionele metadata/artwork; eigen key/licentie vereist, met zichtbare [attributie](https://www.thetvdb.com/api-information#attribution) |
| [SABnzbd](https://sabnzbd.org/) | Geimplementeerde NZB-forwarding en live wachtrij via de [SABnzbd API](https://sabnzbd.org/wiki/configuration/4.5/api) |
| [Newznab API](https://newznab.readthedocs.io/en/latest/misc/api/) | XML-protocol en categoriecompatibiliteit; geen Newznab-codebasis ingebouwd |
| [Prowlarr](https://prowlarr.com/), [Sonarr](https://sonarr.tv/), [Radarr](https://radarr.video/) | Externe clients via Newznab, eventueel via Prowlarr-synchronisatie; geen ingebouwde onderdelen |
| [Spotweb](https://github.com/spotweb/spotweb) | Functionele/protocolreferentie voor Spotnet, categorieen en oude emoticoncodes; NNSpotty gebruikt een nieuwe eigen implementatie, geen legacy Spotweb-codebasis |
| [Plex](https://www.plex.tv/) | Visuele inspiratie voor thema/detailpresentatie; geen Plex-koppeling of playback |
| [NZBGet](https://nzbget.com/) en [IMDb](https://www.imdb.com/) | Genoemd als vervolgdoel, niet als huidige werkende downloader-/metadataprovider |
| [MusicBrainz](https://musicbrainz.org/) en [Cover Art Archive](https://coverartarchive.org/) / [Internet Archive](https://archive.org/) | Optionele muziekmetadata en externe hoezen; publieke API zonder key, User-Agent met APP_URL, persistente aanvraaglimiet, databasecache en zichtbare bronvermelding. Eigen data-/artwork-/commerciele gebruiksvoorwaarden |
| [fanart.tv](https://fanart.tv/) | Optionele artiestachtergronden via exacte MusicBrainz-ID; eigen HTTPS-client met server-side API-key, extra globale/persoonlijke toestemming, databasecache en bronvermelding. Geen gebundelde clientbibliotheek/artwork; actuele API-voorwaarden en afbeeldingsrechten controleren |
| [IGDB](https://www.igdb.com/), [RAWG](https://rawg.io/), [AppStream](https://www.freedesktop.org/software/appstream/docs/), [Open Library](https://openlibrary.org/) | Roadmap-opties voor game-/software-/boekmetadata; nog niet gebruikt als koppeling. AppStream is een standaard/catalogus-ecosysteem, geen universele zoek-API |

### Hosting, ontwikkeling en validatie

- [Apache HTTP Server](https://httpd.apache.org/) of [Nginx](https://nginx.org/) met [PHP-FPM](https://www.php.net/manual/en/install.fpm.php): productiehosting; geen meegeleverde complete serverinstallatie.
- [Git](https://git-scm.com/) en [GitLab](https://about.gitlab.com/): versiebeheer en huidige repositoryhosting.
- [cron](https://man7.org/linux/man-pages/man5/crontab.5.html) en [flock (util-linux)](https://man7.org/linux/man-pages/man1/flock.1.html): optionele periodieke retrieve met een extern runlock.
- [Visual Studio Code](https://code.visualstudio.com/) en [GitHub Copilot](https://github.com/features/copilot): ontwikkelomgeving en ontwikkelassistentie.
- [PowerShell](https://learn.microsoft.com/powershell/): Windows-ontwikkelcommando's en gedeelde VS Code-syntaxtaken; niet nodig voor installatie op Linux.
- [Playwright](https://playwright.dev/): browserautomatisering voor UI-validatie tijdens ontwikkeling; geen productieafhankelijkheid of vereiste voor de PHP-tests.
- Aanbevolen VS Code-extensies: [Intelephense](https://marketplace.visualstudio.com/items?itemName=bmewburn.vscode-intelephense-client), [PHP Debug](https://marketplace.visualstudio.com/items?itemName=xdebug.php-debug), [GitLens](https://marketplace.visualstudio.com/items?itemName=eamodio.gitlens) en [Prettier](https://marketplace.visualstudio.com/items?itemName=esbenp.prettier-vscode). Aanbevolen betekent niet dat deze tools verplicht zijn voor installatie of runtime.
