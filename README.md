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
- [Console, onderhoud en updates](#console-onderhoud-en-updates)
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
- Dubbele berichten overslaan, verdwenen/ongeldige artikelen afzonderlijk verwerken, diagnostische batchoutput en opgeslagen retrieve-runstatussen. Transportfouten worden expliciet gemeld; cursors worden niet voorbij ongelezen ranges geschoven.
- Afbeeldingen en NZB-inhoud worden op aanvraag opgehaald, gevalideerd en in de database gecachet, niet massaal tijdens indexing gedownload.
- Refresh bewaart reacties en vernieuwt metadata/categorie-index atomair; afbeelding-/NZB-caches vervallen alleen bij gewijzigde segmentbron.

### Overzicht, zoeken en navigatie

- Na inloggen direct het spotsoverzicht; geen tussenhomepage. De root-URL verwijst naar `/spots`.
- Vaste header met zoeken en uitsluitend de hoofdgroepen **Beeld, Geluid, Spellen en Applicaties**, plus **Alle**.
- Linker sidebar met de volledige subcategorie-indeling van de gekozen hoofdgroep: type, formaat/platform, bron, taal/ondertiteling, bitrate en genre waar van toepassing.
- Onder andere films, series, boeken, afbeeldingen, muziek, livesets, podcasts, luisterboeken, spellen en applicaties.
- Subcategorieen als stapelbare **EN-filters**, ook binnen dezelfde dimensie; actieve filters zijn afzonderlijk verwijderbaar. Zoektekst en filters blijven behouden bij bijladen.
- Categorieaantallen volgen de zichtbaarheid van de gebruiker. Onbekende categoriecodes blijven bewaard en zichtbaar in technische details.
- Achter subcategoriefilters staat **nieuw sinds je vorige bezoek**, niet het historische totaal. De grens is persoonlijk en blijft tijdens dezelfde browsersessie gelijk bij navigeren/herladen. Nieuwe imports tijdens het bezoek komen erbij; de overzichtskop blijft het totale aantal passende spots tonen.
- De eerste keer na installatie/upgrade wordt een bezoekgrens vastgelegd: bestaande historie begint niet als nieuw. Bij een volgende browsersessie (of uit-/inloggen) geldt je laatste overzicht-/detailbezoek als grens. Tabs delen de sessie; de browser kan sessiecookies bij sessieherstel behouden. Dit zijn geen individuele gelezen/ongelezen-markeringen.
- Infinite loading zonder paginanummers, met een knop **Meer resultaten laden** en opnieuw proberen na een fout. Persoonlijke laadbatch van 10-200 resultaten.
- Een enkele klik opent een sluitbare preview die van rechts in-/uitschuift. Dubbelklik opent de volledige detailpagina.
- Preview met eigen scrollgebied, Escape en focusherstel; toetsenbordvriendelijke titellinks. Zonder JavaScript openen titels de volledige spot en is er een vervolglink.
- Instelbare overzichtskolommen per hoofdgroep: globale adminstandaarden en persoonlijke overrides met herstel naar de actuele globale standaard. Titel blijft verplicht; **Alle** gebruikt een vaste gemengde kolomset.
- Responsive kaarten op kleine schermen, afbrekende lange waarden en geen horizontale tabelscroll.
- Plex-geinspireerde oranje/gouden accenten voor primaire knoppen, badges, filterlabels, selectieknoppen en schakelaars, in donker en licht; overige layout en semantische fout-/succes-/informatiekleuren blijven behouden.

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

### Gebruikers, beheer en weergave

- Sessie-login/logout met gehashte wachtwoorden, adminrechten, CSRF-beschermde mutaties en login-rate-limiting: blokkering na vijf mislukte pogingen binnen vijftien minuten.
- Consolebeheer voor gebruikers aanmaken, verwijderen, adminrol wijzigen, wachtwoord wijzigen en gebruikers tonen.
- Username-gebonden voorkeuren, los van centrale instellingen: **Default**, **Plex Dark**, **Plex Light**, laadbatch, kolommen, erotica, Newznab-key, eigen SABnzbd en TVDB-toestemming.
- Erotica standaard globaal en persoonlijk verborgen; pas zichtbaar na beide toestemmingen. Globaal uit geldt ook voor admins. Dit beleid geldt voor zoeken, aantallen, facetten, details, afbeeldingen, reacties, API en lokale verzendhistorie; indexing verwijdert deze inhoud niet.
- Admin met zeven deelbare submenu's: **Usenet**, **Inhoud en weergave**, **Newznab**, **SABnzbd**, **TVDB metadata**, **Moderatie en reacties**, **Auditlog**. Zonder JavaScript blijven de secties zichtbaar.
- Usenet-host, poort, login, encryptie, retentie, groepsrollen en incrementele artikellimiet centraal beheren.
- Reacties indexeren, reacties tonen/lokaal plaatsen en afbeeldingen ophalen/tonen afzonderlijk schakelen. Uitschakelen verwijdert geen data; afbeeldingen uit blokkeert ook cachehits en TVDB-afbeeldingen. Lokale TVDB-attributie blijft wel zichtbaar.
- Spotter-whitelist/blacklist registreren en tonen, en lokale reacties toevoegen/bekijken in admin. **De spotterlijsten worden nog niet als retrieval-/zichtbaarheidsfilter afgedwongen; spotteridentiteiten zijn nog niet cryptografisch geverifieerd.**
- Auditlog voor beheeracties, database-migraties/status/health/cleanup en publiek `/health` met applicatieversie/codenaam.
- Modulaire domeinlagen voor indexing, metadata, UI, Newznab en downloader; eigen PHP-/JavaScriptcode, zonder frontend-buildstap.

## Installatie vanaf nul

### 1. Benodigdheden

- Linux-server met Git, **PHP 8.2+** voor zowel CLI als webserver en **Composer 2.x**.
- PHP-extensies `curl`, `dom`, `iconv`, `pdo_mysql`, `SimpleXML` en `zlib`; daarnaast OpenSSL voor versleutelde NNTP-streams en werkende TLS/CA-certificaten voor HTTPS.
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

Sla de instellingen op. Controleer onder **Inhoud en weergave** of reacties en afbeeldingen naar wens zijn ingeschakeld. Op een schone installatie staat er nog geen inhoud in het overzicht: voer nu de eerste retrieve uit.

## Eerste retrieve en dagelijks bijwerken

Voer de commando's uit vanuit de projectroot, met dezelfde databaseconfiguratie als de website. Kies **een** van de onderstaande importmodi per run.

### Aanbevolen eerste import: recente spots

```bash
php bin/console retrieve --days=30 --verbose
```

Dit importeert spots en ingeschakelde reacties van de laatste dertig dagen. De spotgroep wordt eerst verwerkt, daarna de reactiegroep. Open vervolgens `/spots`.

**Let op:** een datumimport scant de volledige beschikbare headerhistorie om de datumfilter betrouwbaar toe te passen. Artikelnummer en datum zijn niet gegarandeerd gesorteerd. Hoogste artikelnummers worden bij `--days` eerst verwerkt, zodat recente spots doorgaans eerder zichtbaar zijn, maar ook een kleine dagenwaarde kan lang duren. De incrementele artikellimiet begrenst deze historische scan niet.

### Alternatief: alle beschikbare historie

```bash
php bin/console retrieve --all --verbose
```

Dit negeert de ingestelde retentie en verwerkt alle nog bij je provider beschikbare historie. Het herstelt geen artikelen die de provider niet meer aanbiedt en kan veel tijd/databasecapaciteit vragen. Je hoeft niet eerst `--days` en daarna `--all` te draaien.

### Nieuwe inhoud blijven ophalen

Na een volledig geslaagde historische import staat de cursor aan het gescande einde. Gebruik daarna:

```bash
php bin/console retrieve --since-last --verbose
```

Dit verwerkt vanaf de oudste nog niet verwerkte positie maximaal de ingestelde artikellimiet per groep. Herhaal bij een achterstand. Zonder eerder opgeslagen cursor begint deze modus bij het oudste beschikbare artikel, **niet** automatisch bij de nieuwste spots; gebruik daarom bij een schone installatie bij voorkeur eerst `--days`.

Zonder modusoptie gebruikt `retrieve` ook `--since-last`. Hierbij geldt de adminretentie als datumfilter; `--days=N` gebruikt zijn eigen venster en `--all` geen datumgrens. Kies geen conflicterende modi in hetzelfde commando.

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
- `--refresh-spots` verwerkt alleen spots en vereist `--days` of `--all`; normale runs slaan reeds opgeslagen spots over.
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

De hero toont beschikbare poster/fanart of een widescreen afleveringstill, synopsis, datum, speelduur en genres. Daaronder cast/crew en beschikbare bedrijven/netwerken; geen foto betekent een ronde lokale placeholder. Geen fictieve ratings: TVDB-`score` is populariteit, geen beoordeling.

**TVDB link** linksboven opent status/opnieuw controleren. Alleen admins kunnen een gedeelde film/serie/aflevering zoeken/kiezen, automatisch matchen herstellen of metadata blokkeren. Een onvindbare handmatige aflevering overschrijft geen geldige keuze. De drawer blijft bruikbaar na asynchrone vervanging en sluit met focusherstel.

Cache: providerrecords en geslaagde matches **zeven dagen**, zoeken/ontbrekende/ambigue matches **een dag**, fouten **vijf minuten**. Opnieuw controleren respecteert TTL; centraal budget **90 uitgaande requests/minuut**, locks en begrensde HTTPS-aanvragen. Parserupdates herbeoordelen oude matchresultaten met behoud van handmatige keuzes/blokkeringen; providerrecords houden hun eigen TTL.

De gebruiker geeft toestemming om de titel met TVDB te delen en artwork rechtstreeks in de browser te laden. Globaal afbeeldingen uit onderdrukt ook TVDB-posters/fanart/cast. De lokale bronvermelding **Metadata van TheTVDB** met donker/licht-logo blijft zichtbaar bij getoonde metadata en linkt naar het betreffende record, conform de [attributierichtlijn](https://www.thetvdb.com/api-information#attribution). Zonder TVDB-data geen attributie. Key/PIN/token staan herstelbaar in de database, niet in HTML/audits; bescherm backups.

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
unset NNSPOTTY_TEST_DSN NNSPOTTY_TEST_USER NNSPOTTY_TEST_PASSWORD
```

Gebruik **geen productiecredentials**: de testgebruiker moet tijdelijke testschema's kunnen maken/verwijderen. Zie [Linux-serverchecks](./docs/linux-server-testing.md) voor voorwaarden, asset-/routechecks en echte productieacceptatie.

Een fixturetest bewijst geen providerbeschikbaarheid. Controleer met je eigen accounts een echte spot, reacties, afbeelding, NZB-download, Prowlarr-zoekactie, SABnzbd-upload/wachtrij en TVDB-match. Test mobiel/desktop en donker/licht. Gedeelde VS Code-taken bieden syntaxcontrole en lokaal serveren; aanbevolen extensies staan in [extensions.json](./.vscode/extensions.json).

## Beperkingen en vervolgwerk

- Nog geen installatiewizard, ingebouwde scheduler/jobqueue of begin-/einddatumkalender voor historische retrieve; gebruik voorlopig console/cron en retentie in dagen.
- Geen cryptografische Spotnet-handtekeningverificatie of geverifieerde spotteridentiteit; geregistreerde whitelist/blacklist is nog geen afgedwongen moderatiefilter.
- Niet alle exotische Spotnet-tags/legacy-XML, dagelijkse/absolute afleveringscodes of multi-episodevarianten worden volledig ondersteund.
- Geen Usenet-publicatie van lokale reacties, playback, reviews of verzonnen verificatie-/beoordelingsbadges.
- Geen NZBGet-forwarding, afgeronde SABnzbd-historiesynchronisatie of SABnzbd-pauzeer/verwijderbediening.
- Geen aparte IMDb-provider, klikbare cast-/crew-/studio-/netwerkontdekfilters of Newznab IMDb/TVDB-ID-zoeken. Externe IDs worden waar beschikbaar wel bewaard voor vervolgwerk.
- Geen automatische filmkeuze voor onduidelijke collecties of niet-unieke titel-/jaarmatches.
- Roadmap: metadata voor andere categorieen, bijvoorbeeld muziek via MusicBrainz, games via IGDB/RAWG, software via AppStream-catalogi en boeken via Open Library. Dit zijn nog geen geimplementeerde koppelingen of definitieve providerkeuzes; API-toegang, matching, licentie, attributie en limieten moeten per domein worden uitgewerkt.
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
| [MusicBrainz](https://musicbrainz.org/), [IGDB](https://www.igdb.com/), [RAWG](https://rawg.io/), [AppStream](https://www.freedesktop.org/software/appstream/docs/), [Open Library](https://openlibrary.org/) | Roadmap-opties voor muziek-/game-/software-/boekmetadata; nog niet gebruikt als koppeling. AppStream is een standaard/catalogus-ecosysteem, geen universele zoek-API |

### Hosting, ontwikkeling en validatie

- [Apache HTTP Server](https://httpd.apache.org/) of [Nginx](https://nginx.org/) met [PHP-FPM](https://www.php.net/manual/en/install.fpm.php): productiehosting; geen meegeleverde complete serverinstallatie.
- [Git](https://git-scm.com/) en [GitLab](https://about.gitlab.com/): versiebeheer en huidige repositoryhosting.
- [cron](https://man7.org/linux/man-pages/man5/crontab.5.html) en [flock (util-linux)](https://man7.org/linux/man-pages/man1/flock.1.html): optionele periodieke retrieve met een extern runlock.
- [Visual Studio Code](https://code.visualstudio.com/) en [GitHub Copilot](https://github.com/features/copilot): ontwikkelomgeving en ontwikkelassistentie.
- [PowerShell](https://learn.microsoft.com/powershell/): Windows-ontwikkelcommando's en gedeelde VS Code-syntaxtaken; niet nodig voor installatie op Linux.
- [Playwright](https://playwright.dev/): browserautomatisering voor UI-validatie tijdens ontwikkeling; geen productieafhankelijkheid of vereiste voor de PHP-tests.
- Aanbevolen VS Code-extensies: [Intelephense](https://marketplace.visualstudio.com/items?itemName=bmewburn.vscode-intelephense-client), [PHP Debug](https://marketplace.visualstudio.com/items?itemName=xdebug.php-debug), [GitLens](https://marketplace.visualstudio.com/items?itemName=eamodio.gitlens) en [Prettier](https://marketplace.visualstudio.com/items?itemName=esbenp.prettier-vscode). Aanbevolen betekent niet dat deze tools verplicht zijn voor installatie of runtime.
