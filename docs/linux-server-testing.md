# Linux server testcommando's (nnspotty.hopseflop.nl)

Pas `<pad-naar-project>` aan naar jouw deploylocatie.

```bash
cd <pad-naar-project>
cp .env.example .env
# vul daarna .env met jouw basis app + DB gegevens
```

## TheTVDB metadata, posters en fanart
Pauzeer webverkeer en retrieve-jobs en maak eerst een beveiligde databasebackup volgens de bestaande backup-procedure hieronder. Bestaande `.env` en lokale serverwijzigingen niet overschrijven. Voer in de bestaande deploymap uit:

```bash
cd <pad-naar-project>
umask 022
git status --short
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php bin/console db migrate
php bin/console db status
php bin/console db health
php tests/run.php
curl -fsS https://nnspotty.hopseflop.nl/health
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/metadata.js -o /dev/null
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/spotty.css -o /dev/null
```

Migratie `20261018_0013_tvdb` moet toegepast zijn. Beide assets moeten HTTP 200 en het juiste JavaScript/CSS-contenttype geven. Geen nieuwe retrieval, nieuwe API-key voor Newznab of versie-/codenaamwijziging nodig. Herstart webverkeer/retrieval na de migratie en herlaad met Ctrl+F5.

### Instellen met je eigen TVDB-key
1. Open als admin `/admin#metadata`: het nieuwe onderdeel **TVDB metadata** (zeven beheeronderdelen in totaal). Kies globale toegang, voer je **API V4-key** en alleen indien nodig je abonnee-PIN in; selecteer Nederlands (met Engelse fallback) of Engels. Sla op, controleer dat geheimvelden leeg blijven en klik **Opgeslagen TVDB-toegang testen**. Deel key/PIN niet in chat, screenshots of shellcommando's.
2. Controleer het licentie-/abonnementstype op [TheTVDB](https://thetvdb.com/api-information). Een ongeschikte key of ontbrekende vereiste PIN moet zichtbare foutfeedback geven, geen gesimuleerde succesvolle verbinding. De PHP-server moet met geldige CA's HTTPS naar `api4.thetvdb.com` kunnen gebruiken; TLS-verificatie blijft aan.
3. Open als dezelfde admin `/preferences#metadata` en schakel persoonlijke toestemming in. Herhaal met een gewone testgebruiker. Beide schakelaars staan initieel uit. De voorkeur vermeldt het delen van de titel en het rechtstreeks laden van TVDB-afbeeldingen.
4. Bescherm database/backups: key, PIN en bearer token zijn herstelbaar opgeslagen, niet gehasht. Lege velden bewaren de key/PIN; PIN verwijderen is expliciet. Controleer in browser-HTML, JSON en audit dat geen key/PIN/token wordt teruggestuurd.

### Werkelijke functionele acceptatie
1. Open een volledige filmdetail met een duidelijke titel en jaar. Eerst verschijnt de oorspronkelijke spot, daarna asynchroon de juiste TVDB-titel, synopsis, poster, echte fanart zonder blur en beschikbare genres/datum/speelduur. Controleer de attributielink en of de afbeelding werkelijk bij deze film hoort; alleen een geslaagde toegangstest is onvoldoende.
2. Open een serie met `SxxEyy`, controleer **serie, seizoen en aflevering** tegen TheTVDB, inclusief afleveringtitel, synopsis en widescreen still. Een seizoens-/multiafleveringpakket mag niet als een losse aflevering verschijnen. Een niet-beschikbare aflevering toont eerlijk alleen de serie. Dagelijkse/absolute codes zijn nog niet ondersteund.
3. Open als admin **TVDB-match kiezen / wijzigen**, zoek een bekende titel, kies en sla op; controleer de gedeelde correctie als gewone gebruiker. Test automatisch herstellen en **Geen metadata voor deze spot**. Onvindbare handmatige afleveringen mogen een geldige bestaande keuze niet overschrijven. Gewone gebruikers zien deze correctieacties niet en mogen ze ook niet via POST uitvoeren.
4. Controleer cast/crew, studio/netwerk, originele releasetitel/omschrijving/technische informatie en reacties. Test de coverlightbox en bestaande NZB-/SABnzbd-acties na asynchroon laden. Ratings, reviews, playback en klikbare cast-/bedrijfsfilters zijn geen onderdeel van deze stap; populariteit is geen beoordeling.
5. Heropen dezelfde spot en een andere release van dezelfde titel: databasecache moet herhaalde providerrequests beperken. Entiteiten/matches zeven dagen, zoeken/negatieve matches een dag, fouten vijf minuten. **Metadata opnieuw controleren** negeert deze TTL niet. Er is een centrale limiet van 90 uitgaande requests/minuut, geen onbegrensde achtergrondbackfill.
6. Test een onduidelijke/gelijknamige titel: geen gegokte metadata. Test met tijdelijk ongeldige TVDB-toegang: expliciete fout, oorspronkelijke spot bruikbaar, geen retrylus. Na key/PIN/taalwijziging worden matchstatussen opnieuw controleerbaar; reeds gecachte providerrecords behouden hun eigen TTL.
7. Schakel metadata persoonlijk uit: nieuwe details/requests mogen geen TVDB-data laden. Globaal uit blokkeert ook eerder toegestane admins/gebruikers, maar bewaart hun voorkeur. Test een verborgen spot/erotica: geen metadata-toegang. Schakel postafbeeldingen uit: ook TVDB-posters/fanart/castfoto's verdwijnen, tekst en oorspronkelijke spot blijven beschikbaar.
8. Overzicht/preview en gewone retrieval mogen geen TVDB-requests starten. Test volledige film- en afleveringsdetails op 320/390/768/1440px in donker/licht, zonder horizontale pagina-overflow of overlappende acties.

Lokale automatische regressies gebruiken synthetische TVDB-antwoorden en een echte aparte MariaDB; browsercontroles gebruiken voorgevulde cache en onderschepte testafbeeldingen, geen echte providerkey. Productieacceptatie hierboven blijft nodig voor jouw key/licentie, echte titels, artworkbeschikbaarheid en providerlimieten. Integratietests alleen met de afzonderlijke testdatabasecredentials volgens de bestaande sectie hieronder uitvoeren.

`php bin/console db cleanup --days=90` ruimt naast de bestaande onderhoudsdata ook verlopen TVDB-providercache op (`tvdb_cache=` in de output). Dit commando verwijdert eveneens oude lokale reacties/runs/audit/loginpogingen; voer het alleen uit als die reguliere onderhoudsactie gewenst is. Het verwijdert geen geldige providerrecords of opgeslagen handmatige keuzes.

## Huisstijl en lokale afbeeldingen
Deze update vereist geen migratie of retrieval. Voer vanuit de bestaande deploymap uit:

```bash
umask 022
git pull --ff-only origin main
php tests/run.php
for asset in favicon-32.png brand-icon.png apple-touch-icon.png logo.png placeholder.png placeholder-small.png; do
  curl -fsS -o /dev/null -w "$asset: %{http_code}\n" "https://nnspotty.hopseflop.nl/assets/images/$asset"
done
```

Alle zes afbeeldingen moeten HTTP 200 geven. Herlaad met Ctrl+F5 en controleer het tabbladicoon, het logo op start/login en het headericoon. Open een spot zonder afbeelding: overzicht, preview en detail moeten de eigen coverplaceholder tonen, zonder waarschuwingstekst. Ook de hero gebruikt deze als blurred achtergrond, net als bij een mislukte cover. Uitgeschakelde postafbeeldingen blijven tekst zonder placeholder of achtergrond. Test mobiel en beide thema's. De presentatie en ongewijzigde bronbestanden staan onder `docs/branding/`; alleen verkleinde afgeleiden worden publiek geladen. Houd de nieuwe directories voor de webserver toegankelijk en PNG's leesbaar, zonder `.env`-rechten te verruimen.

Controleer enkele klik op een overzichtsregel (slide-in-preview) en dubbelklik (direct volledige detailpagina), ook na lazy loading en op andere cellen dan de titel. Ctrl/Cmd-klik op de titel blijft een gewone browseractie; Enter op de titel opent de preview en Escape sluit die. Controleer compacte reacties/NZB/SABnzbd-knoppen rechtsboven in de hero, afbrekend zonder overlap op 320px, in preview en volledige detailview. NZB-download en SABnzbd-bevestiging moeten ongewijzigd werken.

## SABnzbd, categorietoewijzing en downloadpaneel
Maak eerst een beveiligde databasebackup volgens de procedure hieronder en pauzeer retrieval en webverkeer tijdens de schema-update. Voer daarna in de bestaande projectmap op `nnspotty.hopseflop.nl` uit:

```bash
cd <pad-naar-project>
umask 022
git status --short
git pull --ff-only origin main
php -m | grep -i '^curl$'
# Alleen wanneer curl ontbreekt, bijvoorbeeld op Debian/Ubuntu:
# PHP_MINOR="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
# sudo apt-get install "php${PHP_MINOR}-curl"
# Herlaad de bijpassende PHP-FPM/Apache-service volgens je serverconfiguratie.
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php bin/console db migrate
php bin/console db status
php bin/console db health
php tests/run.php
curl -fsS https://nnspotty.hopseflop.nl/health
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/downloader.js -o /dev/null
```

Ga alleen verder wanneer Composer geen ontbrekende extensies meldt. Migratie `20261018_0012_sabnzbd` maakt verbindingen, categorietoewijzingen en verzendhistorie aan. Geen nieuwe retrieval of Newznab-keyrotatie nodig. Herstart webverkeer/retrieval na de migratie en herlaad de browser met Ctrl+F5. Houd `.env` en databasebackups geheim; SABnzbd-keys moeten voor uitgaande authenticatie herstelbaar in de database staan.

### Centrale koppeling en categorieen
1. Open als admin `/admin#downloads`. Het beheer is nu verdeeld in Usenet, Inhoud en weergave, Newznab, SABnzbd, Moderatie en reacties, Auditlog.
2. Voer het SABnzbd-**basisadres** in: bijvoorbeeld `http://192.168.1.10:8080` of `https://sab.example.nl/sabnzbd`, zonder `/api`. Interne adressen zijn uitsluitend voor admins toegestaan. Kies bij voorkeur HTTPS; TLS-verificatie staat altijd aan en self-signed certificaten zonder vertrouwde CA werken niet.
3. Gebruik de **volledige API-key** uit SABnzbd Config > General, niet de beperkte NZB-key. Schakel de verbinding in, sla op en klik **Opgeslagen verbinding testen**. De test toont de beschikbare categorienamen. Een leeg keyveld bewaart de bestaande key, zonder die terug te tonen.
4. Stel exacte namen per spottype in, bijvoorbeeld Films `movies`, Series `tv`, Muziek `music`, Boeken `books`. Maak deze namen eerst in SABnzbd aan. Leeg betekent Default. Deze mapping geldt ook voor persoonlijke verbindingen; dezelfde namen moeten op die hosts bestaan.
5. Open een spot met NZB, klik **SABnzbd**, kies **Centrale SABnzbd (admin)** en controleer titel/categorie. Bevestig de upload. Controleer zowel de NNSpotty-verzendhistorie als de werkelijke SABnzbd-wachtrij en uiteindelijke verwerking. Een geslaagde verbindingstest alleen is niet voldoende.

### Persoonlijke koppeling en afscherming
1. Open als gewone gebruiker `/preferences#downloads` en voer je eigen publiek bereikbare SABnzbd-basisadres/key in. Gebruik HTTPS. Een proxy mag een subpad hebben, maar aanvullende Basic Auth, interactieve logins en redirects zijn niet ondersteund. Bestaande SABnzbd-host-whitelisting/firewall moet NNSpotty toestaan; zet geen SABnzbd-beveiliging globaal uit.
2. Controleer dat localhost, 127.0.0.1, ::1, 192.168.x.x, 10.x.x.x, private IPv6 en een DNS-hostnaam met interne antwoorden worden geweigerd. De centrale verbinding mag niet als keuze verschijnen en `/downloader?connection=central` moet voor deze gebruiker 403 geven.
3. Log als tweede gebruiker in: die mag de host/key, wachtrij en lokale historie van de eerste gebruiker niet kunnen opvragen. Een meegegeven `username` of vervalste connectionnaam mag daar geen invloed op hebben. Twee gebruikers die zelf dezelfde SABnzbd-host instellen, zien uiteraard diezelfde externe wachtrij.
4. Controleer een categorie die op de eigen host niet bestaat: versturen moet expliciet stoppen, zonder fallback of upload. Een tussentijds gewijzigde host/key/mapping vereist opnieuw openen van de bevestiging.
5. Globaal geblokkeerde erotica mag niet voorbereid of verstuurd kunnen worden, ook niet door admin. De externe wachtrij zelf kan jobs bevatten die buiten NNSpotty zijn gestart; daarop kan NNSpotty geen betrouwbare erotica-filtering toepassen.

### Live paneel, grafiek en foutgevallen
- **Downloads** in de header opent een slide-in-paneel met je eigen wachtrij; admins kunnen daarnaast expliciet de centrale verbinding kiezen. Test 320px mobiel en breed scherm, beide thema's, onafhankelijk scrollen, toetsenbordfocus en Escape/focusherstel. Escape in het downloadpaneel mag niet tegelijk de spotpreview sluiten.
- De status/snelheid/voortgang komt van SABnzbd. De grafiek bouwt alleen tijdens deze panelsessie de laatste vijf minuten op. Verversen gebeurt elke vijf seconden, stopt bij gesloten paneel/verborgen tabblad en stopt na fouten totdat je handmatig opnieuw probeert.
- Controleer een wachtrij met meer dan 30 jobs via Vorige/Volgende. Externe jobnamen moeten als tekst worden getoond, niet als uitvoerbare HTML.
- Controleer een echte upload: NZB-inhoud gaat via multipart POST met correcte `cat` en `nzbname`, niet via een URL naar NNSpotty. Herhaald indienen van dezelfde bevestiging mag geen tweede upload starten.
- Bij een expliciete SAB-weigering staat de poging op Geweigerd; bij een onzekere transport/JSON/confirmatiefout op Uitkomst onbekend. Een afgebroken proces kan een onbevestigde Verzending gestart achterlaten. Controleer dan eerst SABnzbd voordat je bewust een nieuwe poging start.
- **Verzonden** betekent door SAB geaccepteerd, niet voltooid. Lokale historie (laatste 30) blijft zichtbaar wanneer de verbinding uit/onbereikbaar/verwijderd is; voltooide SABnzbd-historie wordt nog niet gesynchroniseerd. Er zijn nog geen pauze-/verwijderknoppen.
- Bescherm database en backups en gebruik HTTPS voor publieke hosts. SAB API-keys mogen niet in HTML, browser-JSON, auditdetails of URL-queries voorkomen. Verbindfouten mogen geen ruwe SAB-responses met geheimen tonen. Overweeg aanvullend een egress-firewall op de NNSpotty-host.

De geisoleerde integratietests hieronder gebruiken ook een synthetische SABnzbd HTTP-server en controleren echte multipart-bytes, categoriekeuze, scopes, dubbele pogingen, IP-beleid, foutstatussen en historie. Ze bewijzen niet dat jouw externe SABnzbd-configuratie/provider correct bereikbaar is.

## Newznab en NZB-downloads
Voer dit vanuit de bestaande deploymap uit; overschrijf `.env` niet. Pauzeer eerst retrieve-cronjobs en webverkeer tijdens de migratie. Maak een databasebackup met de eigen databasegebruiker, bijvoorbeeld:

```bash
cd <pad-naar-project>
umask 022
read -r -p 'Databasegebruiker: ' DB_USER
read -r -p 'Databasenaam: ' DB_NAME
# Voer het databasewachtwoord in bij de prompt, niet in het commando.
(umask 077; mariadb-dump --single-transaction --host=localhost --user="$DB_USER" --password "$DB_NAME" > "$HOME/nnspotty-before-newznab-$(date +%Y%m%d-%H%M%S).sql")
# Ga alleen verder als de backup is geslaagd.
git status --short
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php bin/console db migrate
php bin/console db status
php bin/console db health
php tests/run.php
curl -fsS https://nnspotty.hopseflop.nl/health
```

Gebruik bij een andere databaseserver de bestaande host/poort. Migratie `20261018_0011_newznab_nzb` maakt drie tabellen aan en bouwt standaardcategorieen voor bestaande spots op. Grote indexes kunnen even duren. Stop niet na alleen de DDL: de migratie is pas gereed wanneer `db status` hem als uitgevoerd meldt. Na een onderbreking mag `db migrate` opnieuw worden uitgevoerd.

Controleer in `.env` dat `APP_URL=https://nnspotty.hopseflop.nl` staat, zonder `/api`, subpad, query of fragment; een afsluitende slash is toegestaan. Start daarna webverkeer en retrieve-jobs weer. Laat publieke assets leesbaar (directories 755, assets 644); bescherm `.env` voor de applicatiegebruiker. Gebruik geen globale `chmod -R 777`.

1. Log in als admin en schakel **Newznab API** in. Zonder inschakeling geeft `/api?t=caps` HTTP 503/Newznab 203.
2. Open als de gewenste gebruiker **Voorkeuren > Persoonlijke Newznab API-key** en maak een key. Kopieer de eenmalige weergave naar een wachtwoordkluis en Prowlarr.
3. Prowlarr: **Add Indexer > Usenet > Generic Newznab**, URL `https://nnspotty.hopseflop.nl`, API Path `/api`, API Key de persoonlijke key. Test en sla op. Gebruik de door caps aangeboden categorieen, niet Spotwebs custom 100000+-codes.
4. Zoek handmatig een bekende filmtitel en serietitel in Prowlarr. Controleer TV 5000/5040 en Movies 2000/2040; categorieen zijn OF-selecties. Test ook aflevering/seizoen en inspecteer de echte NZB met je downloader. Een seizoenpakket is niet automatisch een afzonderlijke aflevering. NNSpotty claimt alleen titelzoeken; ID-gebaseerd zoeken en dagelijkse/absolute episodes komen later.
5. Controleer de gebruikelijke Prowlarr-appsync naar Sonarr. Een geslaagde caps-test bewijst niet dat iedere release correct herkend of via jouw provider nog gedownload kan worden.
6. Vernieuw de key onder Voorkeuren: oude links moeten direct falen. Werk Prowlarr daarna bij. Intrekken werkt ook wanneer admin Newznab uitzet.
7. Controleer persoonlijke/globale erotica-blokkades ook met de API-key. Globaal uit blokkeert zelfs admins; publieke caps adverteren geen XXX.
8. In detail/preview werkt **NZB** met een browsersessie ook als Newznab uit staat. Een providerfout levert een zichtbare fout op, geen leeg succesbestand.

Protocolcontrole zonder key in de shellhistorie of procesargumenten:

```bash
curl -fsS 'https://nnspotty.hopseflop.nl/api?t=caps'
# Bash: key alleen in een lokale variabele; deel API-responses/logs niet.
read -r -s -p 'Persoonlijke API-key: ' NNSPOTTY_API_KEY; printf '\n'
printf '%s' "$NNSPOTTY_API_KEY" |
  curl -fsS --get --data-urlencode 'apikey@-' \
    --data-urlencode 't=tvsearch' --data-urlencode 'q=Snowfall' \
    --data-urlencode 'season=3' --data-urlencode 'cat=5000' \
    'https://nnspotty.hopseflop.nl/api'
unset NNSPOTTY_API_KEY
```

Verwacht geldige RSS met `newznab:response`, standaardcategorieen, stabiele GUIDs en `application/x-nzb`-enclosures. De enclosure-URL bevat de key; bewaar of deel deze uitvoer niet publiek. `size` is de releasegrootte, enclosure length is voorlopig 0 (NZB wordt pas bij download geladen). Ongeldige parameters geven XML-code 201, ongeldige keys 100, onbekende functies/ID-zoeken 203, ontbrekende NZBs 300; aanvraaglimiet geeft HTTP 429. ID-zoeken wordt niet stilletjes omgezet naar ongefilterde resultaten.

**Logging:** standaard Newznab zet keys in querystrings. Pas Apache/Nginx/reverse-proxy-accesslogs en observability aan om querystrings niet te loggen. Een Apache-vhost kan bijvoorbeeld een eigen `LogFormat "%a %t \"%m %U %H\" %>s %b" nnspotty_noquery` gebruiken met `CustomLog ${APACHE_LOG_DIR}/nnspotty-access.log nnspotty_noquery`; gebruik daarin geen `%r`, `%q` of Referer. Verwijder/redigeer ook querystrings in eventuele proxy-/Prowlarr-debuglogs. De applicatie-auditlog bevat alleen acties, geen keys.

De bestaande geisoleerde integratietests omvatten ook de volledige API/NZB-route; zie hieronder. SABnzbd-forwarding en lokale verzendhistorie zijn inmiddels beschikbaar; NZBGet nog niet.

## Dependencies + database
```bash
composer install --no-dev --optimize-autoloader
php bin/console db migrate
php bin/console db status
php bin/console db health
```

## Basis user + retrieve checks
```bash
php bin/console user create admin '<sterk-wachtwoord>' --admin=1
php bin/console user list
```

## Stel Usenet/retrieve in via admin
- Log in op `/login`, ga naar `/admin`.
- Vul daar in:
  - server/port/login/encryptie
  - retentie (leeftijd artikelen)
  - groepen: `spots:free.pt,comments:free.usenet`
  - max artikelen per groep voor `--since-last`

## Retrieve checks
```bash
# na opslaan van instellingen in admin:

php bin/console retrieve --since-last
php bin/console retrieve --days=30
php bin/console retrieve --all
php bin/console retrieve --days=30 --verbose
```

`--since-last` verwerkt vanaf de oudste nog niet verwerkte positie maximaal het ingestelde aantal artikelen per groep. Herhaal dit om achterstand in te lopen. `--days` scant vanaf de hoogste artikelranges terug en slaat recente spots direct op. Ook na oude of lege batches gaat de scan door, omdat artikelnummers niet betrouwbaar op datum gesorteerd zijn. `--days` en `--all` scannen alle beschikbare headers en kunnen lang duren; `--all` negeert retentie.

Bij verbose output staat nu per batch het UTC-datumbereik en `te_oud`, `bestaand`, `geen_spot`, `ongeldig`, `verdwenen`, `opgeslagen` plus het totale aantal opgeslagen berichten. `te_oud=250` verklaart bijvoorbeeld waarom een batch niets opslaat. Ontbrekende optionele overview-velden zijn toegestaan. Een echt afgebroken regel wordt met range, regelnummer en veldaantallen gemeld en overgeslagen; de overige regels gaan door. Een transportfout blijft fataal.

Voor de overview-fix is geen extra databasemigratie nodig. Na deployment:

```bash
php tests/run.php
php bin/console retrieve --days=30 --verbose
```

Controleer dat de eerste `free.pt batch` nu bij het hoogste artikelnummer eindigt. Herhaal de run na een fout gerust: opgeslagen spots/reacties worden niet verdubbeld en een onvolledige omgekeerde scan verplaatst de incrementele cursor niet voorbij ongelezen historie.

## Upgrade en functionele Spotnet-controle
Voer vanuit de bestaande deploymap op `nnspotty.hopseflop.nl` uit (overschrijf je bestaande `.env` niet):

```bash
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php bin/console db migrate
php bin/console db status
php bin/console db health
php tests/run.php
php bin/console retrieve --days=30 --verbose
curl -I https://nnspotty.hopseflop.nl/spots
```

Controleer ingelogd in de browser:
- `/spots` toont individuele spots, niet gewone headers of synthetische releases.
- Gelijke titels van verschillende spots mogen afzonderlijk voorkomen.
- Een detailpagina toont de echte titel, poster, bestandsgrootte, omschrijving en afbeelding (als beschikbaar).
- Reacties uit `free.usenet` staan bij de juiste spot. Dezelfde retrieve nogmaals uitvoeren verdubbelt spots/reacties niet.
- Plaats een lokale reactie; deze verschijnt met bron `NNSpotty`, niet als Usenet-reactie.
- Controleer een afbeelding na herladen: deze wordt uit de databasecache geserveerd.
- Zonder login is `/spots/image?key=...` afgeschermd.
- Usenet-inhoud blijft expliciet onbevestigd zolang handtekeningverificatie niet is geimplementeerd.

De nieuwe tabellen zijn `spotnet_spots`, `spotnet_comments` en `spotnet_images`. Oude `usenet_posts`/`spot_releases` blijven bewaard en worden niet meer getoond. Gebruik geen `db reindex-spots` meer.

## Reproduceerbare integratietests zonder echte Usenet-provider
Gebruik uitsluitend een aparte MySQL/MariaDB-testserver. Het testsysteem maakt een willekeurig benoemde `nnspotty_test_*` database aan en verwijdert die na afloop; het leest geen `.env` en gebruikt geen productiedatabase.

```bash
export NNSPOTTY_TEST_DSN='mysql:host=127.0.0.1;port=3306;charset=utf8mb4'
export NNSPOTTY_TEST_USER='nnspotty_test'
read -rsp 'Testdatabase-wachtwoord: ' NNSPOTTY_TEST_PASSWORD; echo
export NNSPOTTY_TEST_PASSWORD
php tests/run.php --integration
unset NNSPOTTY_TEST_DSN NNSPOTTY_TEST_USER NNSPOTTY_TEST_PASSWORD
```

## Upgrade categorieen, erotica en screenshot-layout
Voer uit op `nnspotty.hopseflop.nl`, vanuit je bestaande deploymap. Pauzeer eerst webverkeer en retrieve-jobs en maak een databasebackup. Laat je bestaande `.env` staan.

```bash
git pull --ff-only
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php bin/console db migrate
php bin/console db status
php bin/console db health
php tests/run.php
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/spotty.css -o /dev/null
```

Migraties 0007 en 0008 moeten uitgevoerd zijn. De laatste deelt bestaande spots in batches opnieuw in: geen volledige retrieve nodig. Bij een onderbreking kun je `php bin/console db migrate` herhalen. De stylesheet moet HTTP 200 en `Content-Type: text/css` geven. Hervat daarna webverkeer en retrieve-jobs.

Controleer met twee accounts:
1. Kies een bekende eroticaspot en noteer de detail- en afbeeldingslink terwijl je toegang hebt.
2. Globaal uit: geen erotica voor beide accounts, ook niet voor admin, via zoeken, filters, preview, directe detail-/afbeeldingslinks of beide reactieformulieren. Directe verborgen links geven 404.
3. Globaal aan, persoonlijke voorkeur uit: nog steeds verborgen. Zet opt-in aan voor account A: alleen A krijgt toegang; account B niet.
4. Zet globaal uit terwijl A opt-in heeft: toegang wordt direct ingetrokken, ook voor al gecachte afbeeldingen. De opt-in blijft opgeslagen en wordt in voorkeuren als geblokkeerd getoond.
5. Controleer films/series/boeken/afbeeldingen en muziektypen, plus formaat/platform, bron, taal, bitrate en genre. Filters en titelzoektekst moeten behouden blijven bij bijladen; aantallen mogen geen verborgen spots meetellen.
6. Controleer de sidebar, compacte lijst, geselecteerde preview en detailhero op desktop en mobiel. De tabel/pagina mag niet horizontaal scrollen. Test ook Plex Light en een onbeschikbare afbeelding.

De integratiesuite hierboven test daarnaast de volledige globale/persoonlijke zichtbaarheidsmatrix via echte HTTP-routes, gebruikersisolatie, CSRF, legacy adult-codes, gecachte afbeeldingen en migratie/backfill. Gebruik daarvoor nooit je productiedatabase.

## Header, scrollen, coverframes en veilige HTML-opmaak
Vanuit de bestaande deploymap op `nnspotty.hopseflop.nl`:

```bash
git pull --ff-only
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php -r 'exit(extension_loaded("dom") ? 0 : 1);'
php tests/run.php
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/spotty.css -o /dev/null
```

Deze UI-update heeft geen nieuwe migratie en vereist geen nieuwe retrieval. De PHP-extensie `dom` is nodig; ontbreekt deze, installeer het XML-pakket voor jouw PHP-versie (bijvoorbeeld `sudo apt install php8.4-xml` bij PHP 8.4 op Debian/Ubuntu) en herstart de bijbehorende PHP-FPM-service.

Controleer na herladen zonder oude CSS-cache:
- Scroll op desktop boven de tabel en daarna boven het rechter detailpaneel. Alleen het aangewezen paneel moet bewegen; de laatste technische details/waarschuwing moeten bereikbaar zijn. Header en zoekfunctie blijven zichtbaar.
- Klik Beeld/Geluid/Spellen/Applicaties: alleen de bijbehorende volledige subcategorieen staan links. Een lege subcategorie mag nul resultaten geven. Test ook de persoonlijke/globale erotica-blokkade.
- Open detailpagina's met een grote staande, grote liggende, kleine en ontbrekende cover. Het frame moet gelijk blijven, met proportionele voorgrond en vervaagde vulling/hero-achtergrond. Een mislukte afbeelding moet een waarschuwing tonen.
- Vergelijk een omschrijving met HTML-alineas, vet/cursief, lijsten en links tussen preview en detail. HTML-tags mogen geen broncode meer zijn. Eventhandlers, scripts, remote CSS en embeds mogen niet werken; bestaande platte tekst en reacties moeten leesbaar blijven.
- Herhaal de layoutcontrole bij een mobiele viewport en met Plex Light; de volledige preview moet in het schuifpaneel bereikbaar zijn zonder horizontale pagina-overflow.

De testgebruiker moet databases met de naam `nnspotty_test_*` kunnen aanmaken/verwijderen. `proc_open` en lokale loopback-sockets moeten beschikbaar zijn voor de synthetische NNTP- en HTTP-servers. Op Windows is een niet-leeg testdatabasewachtwoord vereist om te voorkomen dat lege environmentvariabelen wegvallen. De tests controleren echte php-nntp commando's, XML, reactiekoppeling, binaire afbeeldingen, databaseopslag, limieten, cursors, transportfouten en de HTTP-routes voor login, browsing, afbeeldingen, admin, preferences en reacties.

## Interactief overzicht, filters en kolommen
Voer uit vanuit je bestaande deploymap op `nnspotty.hopseflop.nl`. Pauzeer webverkeer/retrieve en maak eerst een databasebackup; behoud je bestaande `.env`.

```bash
# Alleen gegenereerde Composer-bestanden veilig apart zetten wanneer lokaal gewijzigd.
git stash push -m "Server Composer-autoload voor update" -- \
  vendor/composer/autoload_classmap.php \
  vendor/composer/autoload_static.php
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php bin/console db migrate
php bin/console db status
php bin/console db health
php tests/run.php
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/spots.js -o /dev/null
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/spotty.css -o /dev/null
```

Migratie `20261018_0009_user_spot_columns` moet uitgevoerd zijn. Geen nieuwe retrieve nodig. Beide assets moeten HTTP 200 met hun JavaScript/CSS MIME-type geven. Hervat daarna webverkeer/retrieve en herlaad zonder oude assetcache. De gegenereerde Composer-bestanden worden nog door Git gevolgd: zet de bovenstaande stash **niet** terug met `git stash pop`, want Composer heeft ze opnieuw voor de actuele code gegenereerd. Andere serverwijzigingen blijven onaangeroerd.

Controleer ingelogd, met voldoende bestaande spots:
1. Open `/spots?category=Beeld`: geen zichtbaar detailpaneel en geen paginanummers. Klik ergens op een regel, ook buiten de titel. Het paneel schuift van rechts in; Sluiten/Escape schuift uit en herstelt focus op de titel. Nieuwe bijgeladen regels moeten hetzelfde doen.
2. Scroll een lange omschrijving helemaal uit in het paneel. De lijst mag daarbij niet bewegen. Test ook mobiel en een snelle wisseling tussen spots of sluiten terwijl de aanvraag nog loopt.
3. Nader het einde van de lijst: volgende resultaten worden zonder herladen toegevoegd. Controleer unieke rijen, einde-melding en behoud van zoeken/filters. Zet in browser DevTools het netwerk tijdelijk offline: een foutmelding en retryknop moeten verschijnen; herstel het netwerk en probeer opnieuw.
4. Kies bijvoorbeeld formaat plus genre en vervolgens nog een genre: de selectie gebruikt **EN**, ook binnen dezelfde dimensie. Nogmaals klikken en filterchips verwijderen alleen die code. Beide zoekvelden behouden filters; een andere hoofdcategorie reset ze.
5. Stel in admin verschillende kolommen in voor Beeld/Geluid/Spellen/Applicaties. Account A volgt dit zonder override. Sla voor A een eigen selectie op; account B moet de globale standaard blijven zien. Wijzig de adminstandaard: A blijft eigen keuzes gebruiken tot **Globale standaard gebruiken** is opgeslagen. Titel is niet uit te schakelen.
6. Test alle kolommen met zeer lange titels/spotternamen, open en gesloten preview, bij 320/390/768/1024/1440px. Geen horizontale tabel-/paginascroll; smalle regels worden kaarten. Controleer donker/licht, minder vervaagde hero en een ontbrekende afbeelding.
7. Herhaal de erotica-matrix hierboven: ook `/spots/preview?key=...` en `/spots?batch=1...` mogen geen geblokkeerde inhoud lekken. Zonder sessie geven deze asynchrone routes HTTP 401; verborgen previews geven 404; ongeldige filters/cursors 400.

De afzonderlijke integratiesuite test ook EN binnen/tussen dimensies, keysets met gelijke datums en gelijktijdige indexering, volledige HTTP-batchreeksen, kolomopslag/overrides/reset, CSRF en gebruikersisolatie. Draai deze uitsluitend met de testdatabase-instellingen uit de eerdere sectie, nooit met productiecredentials.

## Git opruimen, inhoudsinstellingen en afbeeldingsvergroting
**Eenmalig belangrijk:** deze update stopt met volgen van `.env` en `vendor/`. Een pull kan de oude gevolgde bestanden verwijderen. Maak eerst een databasebackup en pauzeer webverkeer en retrieve-jobs. Voer vanuit je bestaande deploymap uit:

```bash
set -e
umask 022

# Configuratie buiten de repository bewaren. Toon alleen het backuppad, nooit de inhoud.
env_backup="$(umask 077; mktemp /tmp/nnspotty-env.XXXXXX)"
cp -- .env "$env_backup"
printf 'Bewaar dit configuratiebackuppad tot de update geslaagd is: %s\n' "$env_backup"

# Alleen na geslaagde backup de lokale, gevolgde versie tijdelijk terugzetten.
# Hiermee worden geen nieuwe servercredentials in een Git-stash opgenomen.
git restore -- .env
git stash push -m "Oude gegenereerde dependencies voor Git-opruiming" -- vendor/
git pull --ff-only origin main

# De oorspronkelijke serverconfiguratie herstellen en dependencies opnieuw genereren.
install -m 600 "$env_backup" .env
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php bin/console db migrate
php bin/console db status
php bin/console db health
php tests/run.php

# Controle: geen uitvoer van ls-files; check-ignore toont .env en vendor/autoload.php.
git ls-files -- .env vendor/
git check-ignore .env vendor/autoload.php
git status --short
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/presentation.js -o /dev/null
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/spots.js -o /dev/null
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/spotty.css -o /dev/null

# Alleen na succesvolle update en herstel van .env:
rm -- "$env_backup"
```

Breekt de update af, dan blijft de beveiligde configuratiebackup op het getoonde pad staan. Herstel die met `install -m 600 /tmp/nnspotty-env.<jouw-suffix> .env` voordat je verkeer/jobs hervat. Zet de vendor-stash niet terug: Composer genereert de juiste bestanden. Andere lokale wijzigingen blijven ongemoeid; los eventuele andere mergeblokkades gericht op. Bij volgende updates volstaan pull en Composer install; gegenereerde autoloadwijzigingen veroorzaken dan geen mergeconflict meer.

Deze functionaliteit vereist geen nieuwe migratie na 0009 en geen nieuwe retrieval voor bestaande omschrijvingen. `.gitignore` verwijdert niets uit oude Git-commits: roteer eventuele echte credentials die eerder in `.env` stonden. Herschrijf de historie niet als onderdeel van deze deployment.

Controleer ingelogd:
1. Detail en preview: klik op de cover. De originele afbeelding verschijnt groter en proportioneel in een modaal venster. Sluitknop/Escape/buitenklik sluiten; focus keert terug. In een preview mag Escape voor de lightbox niet ook de preview sluiten.
2. Test 320/390/768/1440px en donker/licht. De lightbox mag niet horizontaal overlopen. Test een onbereikbare afbeelding: expliciete foutfeedback; geen browser-popup.
3. Open een spot met `[br]`, dubbele `[br][br]`, vet/cursief, citaten, lijsten en veilige links. Geen rauwe bracketcodes voor ondersteunde tags. Vergelijk preview/detail. Scripts, remote stijlen, onveilige URL-schema's en `[img]` worden niet uitgevoerd/geladen.
4. Admin **Op te halen inhoud**: zet Usenet-reacties indexeren uit, laat tonen aan. `php bin/console retrieve --since-last --verbose` meldt dat reacties worden overgeslagen; er mag geen `free.usenet batch` verschijnen. Eerder opgeslagen reacties blijven zichtbaar met een melding. Zet indexeren opnieuw aan om te hervatten; `--days=30` kan beschikbare historie aanvullen.
5. Zet reacties tonen uit: geen reacties/formulieren in detail/admin, geen reactieactie in preview. Beide lokale POST-routes weigeren met 403. Opgeslagen reacties worden niet verwijderd.
6. Zet afbeeldingen uit: geen thumbnails/coverkolom/hero/lightbox in lijst, bijgeladen rijen, preview of detail. Ook een bekende gecachte afbeeldingslink geeft 403 en private/no-store. Herinschakelen hergebruikt de cache.
7. Gewone gebruikers kunnen deze admininstellingen niet wijzigen. Test de erotica-blokkade ook na herinschakelen; deze blijft onafhankelijk gelden.

De integratiesuite met een aparte testdatabase controleert bovenstaande opslag-/HTTP-/NNTP-beleidsregels. Een schone Composer-installatie moet dezelfde suite kunnen draaien zonder dependencies uit Git. Gebruik nooit je productiedatabase voor `--integration`.

### Herstel bij ontbrekende layout na de eenmalige update
De oorspronkelijke updateprocedure liet `umask 077` actief tijdens Git/Composer. Daardoor kunnen gewijzigde publieke bestanden modus 600 en nieuwe directories modus 700 krijgen. Als Apache onder een andere gebruiker draait, geeft het voor CSS/JavaScript HTTP 403, ook als PHP-pagina's nog werken. Dit is geen verwijderde stylesheet en geen browsercacheprobleem.

Voer vanuit de bestaande deploymap uit:

```bash
set -e
umask 022
chmod 755 public public/assets
chmod 644 public/assets/spotty.css public/assets/spots.js public/assets/presentation.js public/assets/downloader.js

curl -fsS -D - https://nnspotty.hopseflop.nl/assets/spotty.css -o /dev/null
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/spots.js -o /dev/null
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/presentation.js -o /dev/null
```

Alle assets moeten HTTP 200 geven, met respectievelijk een CSS- en JavaScript-contenttype; controleer ook `/assets/downloader.js`. Herlaad daarna de browser met Ctrl+F5. Geeft een verzoek nog 403, controleer dan de Apache-errorlog, parent-directoryrechten en eventuele ACL's met de serverbeheerder; maak niet de hele repository wereldleesbaar/schrijfbaar. Laat `.env` op 600 staan. Gebruik voor volgende Git/Composer-updates `umask 022`; beperk 077 tot het aanmaken van geheime backups in een subshell.

## Ontbrekende reacties en extra spotdetails (migratie 0010)
Voer vanuit de bestaande deploymap op `nnspotty.hopseflop.nl` uit. Maak eerst een databasebackup en pauzeer retrieve-jobs en webverkeer tijdens de migratie.

```bash
set -e
umask 022
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php bin/console db migrate
php bin/console db status
php bin/console db health
php tests/run.php
```

Migratie `20261018_0010_spot_details_comments` herstelt bestaande foutieve reactiekoppelingen op basis van de Spotnet-reactie-ID. Dit downloadt niets opnieuw en verwijdert geen reacties. Controleer in admin de groep `comments:free.usenet` en schakel zowel **Usenet-reacties indexeren** als **Reacties weergeven** in.

```bash
# Reacties ophalen zonder eerst de spotgroep te verwerken.
php bin/console retrieve --comments-only --days=7 --verbose

# Eerder opgeslagen recente spots aanvullen met onder andere de clientnaam.
php bin/console retrieve --refresh-spots --days=7 --verbose

# Daarna periodiek verder vanaf de opgeslagen posities.
php bin/console retrieve --since-last --verbose
```

`--comments-only` moet uitsluitend `comments free.usenet` melden, niet `spots free.pt`. Ook een datumsessie scant alle beschikbare headers van de geselecteerde groep; recente batches komen eerst, maar volledige afronding kan lang duren. Gebruik een ruimere periode voor oudere reacties/spots. De incrementele limiet geldt alleen bij `--since-last`; deze begint bij historische achterstand niet automatisch bij de nieuwste reacties.

Controleer dezelfde spot als in Spotweb op basis van het exacte Message-ID, niet uitsluitend titel. Reeds verkeerd gekoppelde reacties moeten na migratie zichtbaar zijn; nog niet opgeslagen reacties pas na retrieval. Vergelijk de body, auteur en datum van reacties, en **Gepost met**, hoofdgroep, formaat, bron, bitrate en genre in detail en preview. De clientnaam verschijnt alleen als de provider/header/XML die levert; er wordt geen waarde verzonnen. Een identiek aantal reacties is niet gegarandeerd bij verschillende providerretentie, moderatie of retrievalperiodes. Bij nul reacties: controleer verbose output op `opgeslagen`, `te_oud`, `ongeldig`, `verdwenen` en transportfouten; deel die diagnostiek zonder serverwachtwoorden.

Spotwebs geverifieerde spotter-ID, whiteliststatus, ratings/spamrapporten en NZB-downloads hebben nog geen gelijkwaardige implementatie in NNSpotty. De al beschikbare website, omschrijving en categoriegegevens blijven behouden; deze update verzint geen ontbrekende velden of verificatiestatus.

## Moderne emoticons met oude Spotweb-codes
Voer vanuit de bestaande deploymap op `nnspotty.hopseflop.nl` uit:

```bash
set -e
umask 022
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php tests/run.php
curl -fsS -D - https://nnspotty.hopseflop.nl/assets/spotty.css -o /dev/null
```

Geen nieuwe migratie of retrieval nodig voor deze weergavewijziging. Als migratie 0010 van de vorige update nog niet is uitgevoerd, voer die eerst volgens de bovenstaande sectie uit. Herlaad met Ctrl+F5.

Controleer een bestaande reactie met `Dank [img=buigen]` en een lange reeks `[img=respekt]`: moderne symbolen/badges, geen rauwe ondersteunde codes en geen horizontale overflow. Plaats een lokale testreactie met `[img=respect] [img=klappen] [img=wink]`; HTML en overige BBCode blijven daar tekst. Test 320/390/768/1440px en donker/licht. Screenreaders moeten betekenisvolle labels krijgen, niet alleen onbenoemde afbeeldingen. Geen animatie en geen GIF-/afbeeldingsverzoeken in het netwerkpaneel voor deze codes; emojiweergave kan per besturingssysteem verschillen.

Ondersteunde namen (hoofdletterongevoelig): `biggrin`, `bloos`, `buigen`, `censored`, `clown`, `confused`, `cool`, `exactly`, `frown`, `grijns`, `heh`, `huh`, `klappen`, `knipoog`, `kwijl`, `lollig`, `maf`, `ogen`, `oops`, `pijl`, `redface`, `respekt`, `respect`, `schater`, `shiny`, `sleephappy`, `smile`, `uitroepteken`, `vlag`, `vraagteken`, `wink`. Onbekende namen en `[img=https://example.invalid/test.gif]` blijven tekst. In Usenet-codeblokken blijft `[img=buigen]` letterlijk. De adminschakelaar voor spotafbeeldingen blokkeert echte covers, niet deze tekstsymbolen.

## Web checks via subdomein
```bash
curl -i https://nnspotty.hopseflop.nl/health
curl -i https://nnspotty.hopseflop.nl/login
curl -I https://nnspotty.hopseflop.nl/admin
curl -I https://nnspotty.hopseflop.nl/preferences
```

Let op: zonder login geven `/admin` en `/preferences` een redirect naar `/login`.
POST-acties zijn CSRF-beveiligd; functionele POST-tests doe je het best via browser (of met cookie + CSRF token parsing).

## Login rate-limit check
- Na 5 mislukte logins binnen 15 minuten moet de loginpagina een lockout-melding tonen.
- Test dit het makkelijkst in de browser met opzettelijk fout wachtwoord.

## Als /health 404 of 403 geeft (Apache rewrite)
```bash
cd <pad-naar-project>
ls -la .htaccess index.php public/index.php

# permissies
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 600 .env

# her-test
curl -i https://nnspotty.hopseflop.nl/health
```

## Als je nog steeds 403 krijgt
Voer deze diagnose in volgorde uit:

```bash
cd <pad-naar-project>

# 1) Test directe front controller
curl -i https://nnspotty.hopseflop.nl/index.php

# 2) Test root
curl -i https://nnspotty.hopseflop.nl/

# 3) Test tijdelijke bypass van rewrite
mv .htaccess .htaccess.bak
curl -i https://nnspotty.hopseflop.nl/index.php
mv .htaccess.bak .htaccess
```

Interpretatie:
- Als `/index.php` ook 403 geeft zonder `.htaccess`: hosting/vhost policy blokkeert PHP in deze map.
- Als `/index.php` werkt maar `/health` 403 geeft: rewrite of `AllowOverride` staat uit op serverniveau.

## Cleanup
```bash
php bin/console db cleanup --days=90
```
