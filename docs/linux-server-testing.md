# Linux server testcommando's (nnspotty.hopseflop.nl)

Pas `<pad-naar-project>` aan naar jouw deploylocatie.

Alleen bij een **nieuwe installatie**, niet bij een bestaande deployment:

```bash
cd <pad-naar-project>
cp .env.example .env
# vul daarna .env met jouw basis app + DB gegevens
```

## Retrieverperformance: lazy reacties accepteren

Migratie `20261021_0022_lazy_comments` wijzigt de tabel `spotnet_comments` (circa 1,5 miljoen rijen): reken op enkele minuten en tijdelijk extra schijfruimte. Stop eerst cron en lopende retrieves en maak een backup. Als deploygebruiker `hopseflop`:

```bash
cd /home/hopseflop/domains/nnspotty.hopseflop.nl/public_html
pgrep -af 'bin/console retrieve' || echo "geen retrieve actief"
git pull --ff-only origin main
/opt/remi/php84/root/usr/bin/php bin/console db status
time /opt/remi/php84/root/usr/bin/php bin/console db migrate
/opt/remi/php84/root/usr/bin/php bin/console db status
/opt/remi/php84/root/usr/bin/php bin/console db health
```

Als root daarna `systemctl reload nnspotty-php84-fpm.service`. Controleer in **Beheerpanel > Inhoud en weergave > Op te halen inhoud** dat reacties op **Op aanvraag** staan en stel eventueel een reactie-retentie in. Daarna als `hopseflop`:

```bash
cd /home/hopseflop/domains/nnspotty.hopseflop.nl/public_html
time OPENSSL_CONF=/home/hopseflop/.config/nnspotty/openssl.cnf \
    /opt/remi/php84/root/usr/bin/php bin/console retrieve --since-last --verbose 2>&1 | tail -n 40
mysql -e "SELECT body_status, COUNT(*) FROM spotnet_comments GROUP BY body_status" <database>
mysql -e "SELECT id, mode, status, started_at, completed_at, message FROM retrieval_runs ORDER BY id DESC LIMIT 5" <database>
```

De verbose regel `Reacties: alleen headers indexeren...` en de teller `tekst_later` bevestigen de lazy-modus. Open daarna een spot met reacties: de spinner laadt de teksten automatisch; het aantal reacties loopt op en `pending` daalt. Een account zonder recht **Reacties van Usenet laden** ziet alleen een melding. Fouten bij laden staan in `/home/hopseflop/domains/nnspotty.hopseflop.nl/logs/php_log` (`Usenet-reacties laden`).

## Gebruikers, groepen en profielen accepteren

Plan een onderhoudsmoment: laat web-/cron-retrieves afronden, pauzeer cron, maak een databasebackup en werk niet tijdens actieve publicaties bij. Nieuwe code vereist migratie 0021 voordat webverkeer hervat wordt. Als deploygebruiker `hopseflop`:

```bash
cd /home/hopseflop/domains/nnspotty.hopseflop.nl/public_html
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
/opt/remi/php84/root/usr/bin/php bin/console db migrate
/opt/remi/php84/root/usr/bin/php bin/console db status
/opt/remi/php84/root/usr/bin/php bin/console user groups
OPENSSL_CONF=/home/hopseflop/.config/nnspotty/openssl.cnf \
    /opt/remi/php84/root/usr/bin/php -d memory_limit=256M tests/run.php
/opt/remi/php84/root/usr/bin/php -l public/index.php
/opt/remi/php84/root/usr/bin/php -r 'foreach (["gd","mbstring","curl"] as $ext) echo $ext, ": ", extension_loaded($ext) ? "OK" : "ONTBREEKT", PHP_EOL;'
```

Voor avatarverwerking moet GD ook in PHP 8.4-FPM geladen zijn. De CLI-check bewijst niet welke modules FPM heeft. Ontbrekende GD moet een zichtbare fout bij avatarbijwerken opleveren, niet een onveilige onbewerkte upload. Geen nieuwe Composer-package. Na configuratiecontrole als root alleen de aparte NNSpotty-service herladen:

```bash
systemctl reload nnspotty-php84-fpm.service
systemctl status nnspotty-php84-fpm.service --no-pager -l
curl -fsS https://nnspotty.hopseflop.nl/health
```

Ververs assets met Ctrl+F5. Bestaand adminaccount blijft actief zonder nieuwe wachtwoordplicht. Controleer:
1. Gebruikers en groepen: testaccount aanmaken met tijdelijk wachtwoord; in tweede browser verplichte wijziging met controle van huidig wachtwoord. Geen spots/API voor de wijziging; daarna normale toegang.
2. Profiel: publieke naam, 3-regelige handtekening, optionele prive-e-mail, avatarupload. Headerfoto/naam na redirect; donker/licht en mobiel. Foute naam/emoji, te lange signature, SVG, ongeldige upload en interne HTTPS-URL geven feedback zonder onveilige opslag. Remote URL moet direct HTTPS 200 leveren, geen redirect.
3. Gravatar alleen met bewuste toestemming en eigen adres; dit doet een echt extern verzoek. Verwijder avatar en controleer dat de afbeelding niet meer wordt geserveerd.
4. Meerdere groepen: moderator krijgt metadata-/spotterbeheer, retrieve en audit maar geen providercredentials/gebruikers/groepen. Extra groep met alleen NZB-download: publicatie en forwarding ook via directe routes geblokkeerd. Rechten wijzigen zonder opnieuw inloggen; beperkte audit bevat geen configuratiepayloads.
5. Account deactiveren trekt bestaande browsersessie/API-toegang in; reactiveren vereist nieuwe login. Tijdelijk wachtwoord resetten trekt andere sessies in. Laatste actieve beheerder kan niet gedeactiveerd/verwijderd/gedegradeerd; test dit voorzichtig, behoud CLI-toegang.
6. Eigen lege groep verwijderen na bevestiging; groep met leden en systeemgroepen niet verwijderbaar. Gebruiker verwijderen pas na bewuste bevestiging, bestaande Usenet-publicaties blijven bestaan.
7. Reactiepreview toont publieke naam en volledige handtekening; een echte publicatietest alleen op een passende spot met toegestane tekst. Totale tekstlengte inclusief signature telt mee. Geen onbevestigde poging blind opnieuw versturen.

CLI-statuswijziging (gebruik de groeps-ID's uit `user groups`, geen wachtwoorden in gedeelde uitvoer):

```bash
/opt/remi/php84/root/usr/bin/php bin/console user edit <testusername> --active=0
/opt/remi/php84/root/usr/bin/php bin/console user edit <testusername> --active=1
```

Hervat cron na acceptatie. Stop/bewerk geen publieke Usenet-berichten als testcleanup; NNSpotty kan die niet intrekken.

## Usenet-reacties publiceren en emoticons accepteren

### Aparte postingserver

Voor een bestaande installatie met werkende RSA/SHA1-configuratie, als deploygebruiker vanuit de projectmap:

```bash
cd /home/hopseflop/domains/nnspotty.hopseflop.nl/public_html
git pull --ff-only origin main
OPENSSL_CONF=/home/hopseflop/.config/nnspotty/openssl.cnf \
    /opt/remi/php84/root/usr/bin/php tests/run.php
/opt/remi/php84/root/usr/bin/php -l public/index.php
curl -fsS https://nnspotty.hopseflop.nl/health
```

Geen nieuwe migratie of dependency nodig voor deze instellingen. Een bestaande OPcache-deployprocedure blijft gelden. De OPENSSL_CONF hierboven geldt alleen voor dit CLI-proces; de web-runtime moet afzonderlijk correct geconfigureerd blijven.

Onder Beheerpanel > Usenet: laat de standaardserver voor ophalen staan. Vul bij Eweka postingserver `post.eweka.nl`, poort `563`, encryptie `SSL` in; laat andere inloggegevens uit om de standaardlogin te gebruiken. Controleer na opslaan/herladen dat alles behouden blijft. Test ook terugschakelen met een lege postingserver en de optionele eigen login (beide velden verplicht bij aparte host). Ongeldige poort/login moet feedback geven zonder instellingen te wijzigen. Zonder JavaScript blijft het hele formulier bruikbaar. Wachtwoorden niet in audit of gedeelde logs opnemen.

Een echte publicatietest verspreidt een reactie op Usenet: plaats alleen bewust toegestane tekst op een passende spot. Open na een eerder definitief geweigerde poging de spot opnieuw voor een nieuw formulier; hetzelfde formulier toont zijn opgeslagen uitkomst zonder opnieuw te posten. Bij onbekende uitkomst eerst controleren of de reactie al is gepubliceerd. Retrieve blijft de standaardserver gebruiken, ook met aparte postingserver.

### Eerste installatie van publicatie

Pauzeer retrieves/cron tijdens de update, maak een databasebackup volgens je normale beheerprocedure en voer vanuit de bestaande projectmap op nnspotty.hopseflop.nl uit:

```bash
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php bin/console db migrate
php bin/console db status
php -l src/Comments/Article.php
php -l src/Comments/Publisher.php
php -l public/index.php
curl -fsS https://nnspotty.hopseflop.nl/health
```

Controleer migratie `20261020_0020_comment_publication`. RSA-privatesleutels worden bij eerste publicatiepoging server-side in de database aangemaakt, nooit als projectbestand. Bescherm database/backups. Wis bestaande spots/cursors of sleutels niet.

```bash
# Alleen synthetische providers, geen echte publicatie:
php tests/run.php
```

De suite vereist werkende RSA/SHA1-ondertekening en verificatie. Bij de bekende geblokkeerde serverconfiguratie mag deze expliciet falen; dat is geen reden een crypto-omweg of lokale reactie te gebruiken. De UI hoort dan bij publicatie een nette melding over serverconfiguratie te tonen met behoud van je tekst, zonder providerrequest of zichtbare nieuwe reactie. Eerst migreren, zodat een ontbrekende tabel deze melding niet maskeert. De web-runtime kan van CLI verschillen.

Ververs assets met Ctrl+F5. Test mobiel/desktop en donker/licht: 28 emoticonknoppen bij het veld, toegankelijke namen, invoegen op cursorpositie, selectie vervangen, focus terug naar textarea, maxlength zonder stil afkappen. Bevestiging van openbare publicatie is verplicht; geen lokale keuze. Controleer ook handmatig getypte codes en native formulier zonder JavaScript.

Na herstel van de serverconfiguratie: stel een provider met POST-rechten en `comments:free.usenet` in. Plaats alleen een bewuste, toegestane publieke testreactie op een passende spot. Verwacht providerbevestiging, bron Usenet en geen dubbele reactie bij herhaalde submit of latere retrieve. Controleer daarna dezelfde spot in Spotnet/Spotweb; verspreiding is niet onmiddellijk gegarandeerd. Bij een onbekende verzenduitkomst niet blind opnieuw plaatsen. Publicatie is niet via NNSpotty intrekbaar. Het oude adminformulier mag niet meer lokaal publiceren; historische reacties blijven zichtbaar. Globaal reacties uit blokkeert ook publicatie; alleen ophalen uit niet.

Optioneel reacties ophalen binnen het ingestelde retentie-/datumbeleid (dit publiceert niets, maar kan netwerk-/databasewerk doen):

```bash
php bin/console retrieve --comments-only --days=1 --verbose
```

## Kwaliteitsbadges accepteren

Vanuit de bestaande installatiemap op `nnspotty.hopseflop.nl`, zonder actieve retrieve tijdens de code-update:

```bash
git pull --ff-only origin main
php tests/run.php
curl -fsS https://nnspotty.hopseflop.nl/health
```

Geen migratie/dependency/installatie van logo's nodig. Ververs CSS met Ctrl+F5. Open film-/seriedetail en preview, eerst origineel en daarna met TVDB-metadata, in donker/licht en op mobiel. Video/audio/taal/ondertiteling staan in wrappende badges. Vergelijk expliciete tags x265 versus H.265/HEVC, DTS:X versus DTS-HD MA/DTS en HDR10+ versus HDR10. Alleen daadwerkelijke claims uit de titel horen te verschijnen; geen claims uit de filmnaam en geen metadata-afgeleide codecs. De bronwaarschuwing blijft staan. Network toont geen requests voor kwaliteitsafbeeldingen: huidige badges zijn eigen tekstontwerpen, geen officiele logo's; externe artwork wordt pas toegevoegd met verifieerbare passende toestemming.

## NNTP-pipelining accepteren

Pauzeer cron. Laat de lopende retrieve afronden of stop uitsluitend je eigen consoleproces gecontroleerd (bijvoorbeeld Ctrl+C in die terminal). Update geen PHP-code midden in een run en start geen tweede retrieve. Bestaande spots/cursors niet wissen.

Voer in de bestaande installatiemap op `nnspotty.hopseflop.nl` uit:

```bash
git status --short
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php bin/console db status
php bin/console db health
php tests/run.php
curl -fsS https://nnspotty.hopseflop.nl/health
```

Deze wijziging heeft geen nieuwe migratie. Zijn oudere migraties nog niet uitgevoerd, werk die dan volgens hun eigen uitrolinstructies bij. Open **Beheerpanel > Usenet**, stel **NNTP pipeline-diepte = 32** in en sla op. Test dat 1 en 128 toegestaan zijn, 0/129 worden geweigerd en de opgeslagen waarde na herladen blijft staan. Begin voor productie met 32; bij providerproblemen expliciet 1 gebruiken, geen automatische stille downgrade.

Voor de onderbroken eerste historische import gebruik je dezelfde historische modus/retentie/begindatum als voorheen:

```bash
php bin/console retrieve --full --verbose
# Na volledig geslaagde historische import: normale dagelijkse cronmodus
php bin/console retrieve --since-last --verbose
```

Kies een van beide passend bij de importfase; start ze niet tegelijkertijd. Een onderbroken `--days=N` herstart je met dezelfde N, niet blind met `--since-last`. Verbose startoutput moet `NNTP pipeline-diepte: 32` tonen. Opgeslagen spots tellen als `bestaand` en krijgen geen nieuwe HEAD-aanvraag. Reacties gebruiken dezelfde diepte voor ARTICLE; `--refresh-spots` haalt bestaande spots juist wel opnieuw op. Wijziging tijdens een actieve run geldt pas bij de volgende run.

Vergelijk doorvoer alleen op vergelijkbare **nieuwe** artikelen met dezelfde instellingen/provider, niet een eerste import met een tweede run die alles als bestaand overslaat. Noteer tijd, opgeslagen/overgeslagen aantallen en fouten; pure XOVER-/databasebelasting wordt niet door pipelining opgelost. Voor niet-destructieve, reproduceerbare transport-/doorvoertests gebruik je een aparte testserver volgens de `NNSPOTTY_TEST_*`-instructies:

```bash
php tests/run.php --integration
```

De suite start een lokale TCP-NNTP-fixture die pas antwoordt nadat het volledige pipelinevenster is ontvangen, test HEAD en ARTICLE bij 1/32/128, meet met synthetische 20 ms vensterlatentie en vereist minstens tweemaal snellere transportafhandeling bij 32/128 dan bij 1. Inclusief verdwenen artikelen midden in vensters, fout/verbroken verbinding na 34 opgeslagen spots, ongewijzigde cursor en volledig hervatten zonder gaten. Dit gebruikt geen productiecredentials/provider en voorspelt geen exacte importduur op productie.

## Spotlist whitelist en blacklist

Wacht eerst tot de huidige retrieve klaar is of stop hem gecontroleerd. Niet upgraden/migreren midden in een run. Maak een beveiligde databasebackup en pauzeer webverkeer/cron tijdens de schemawijziging. Deze update verwijdert geen spots en reset geen retrieve-cursors; een eventuele schone start is een aparte, door jou beheerde handeling.

Voer in de bestaande projectmap op `nnspotty.hopseflop.nl` uit:

```bash
git status --short
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php bin/console db migrate
php bin/console db status
php bin/console db health
php tests/run.php
php bin/console spotters sync
php bin/console spotters enable
php bin/console spotters status
curl -fsS https://nnspotty.hopseflop.nl/health
```

Controleer migratie `20261019_0016_spotter_keys` en de OpenSSL-platformvereiste voor zowel CLI als PHP-FPM. Synchroniseren moet geldige aantallen en een laatste update tonen; ongeldige bronvermeldingen worden expliciet als waarschuwing getoond, niet als lege geslaagde import. Na een echte synchronisatiefout niet blind opnieuw de schone retrieve starten. Herstel HTTPS/CA/netwerk; schakel TLS-verificatie niet uit.

### OpenSSL-diagnostiek

Bij `invalid digest` tijdens RSA/SHA1-testondertekening of Spotnet-verificatie: ondertekenen en verifieren afzonderlijk controleren. Een bekende geldige handtekening moet verificatieresultaat `1` geven; `0` betekent ongeldig en `-1`/`false` een uitvoeringsfout. NNSpotty stopt een retrieve bij die uitvoeringsfout, zonder de onvoltooide batchcursor te verhogen. Een eerder onterecht als invalid opgeslagen identiteit wordt hierdoor niet automatisch hersteld.

Veilige alleen-lezen diagnostiek vanuit de projectmap:

```bash
php -v
php --ini
php --ri openssl
php -r 'foreach (["OPENSSL_CONF", "OPENSSL_MODULES"] as $name) { $value = getenv($name); echo $name, "=", $value === false ? "(niet ingesteld)" : $value, PHP_EOL; }'
if command -v openssl >/dev/null 2>&1; then
    openssl version -a
    openssl list -providers
fi
if command -v update-crypto-policies >/dev/null 2>&1; then
    update-crypto-policies --show
fi
```

De `openssl`-CLI kan een andere bibliotheek/configuratie gebruiken dan PHP. Laat de hoster de actieve providers, distributie-/FIPS-policy en RSA/SHA1-ondersteuning beoordelen voor zowel de cron/CLI-runtime als PHP-FPM. Geen publieke phpinfo-pagina plaatsen, systeembreed beleid verlagen of verificatie omzeilen. De diagnostiek toont versies, configuratiepaden en provider-/beleidsnamen, geen applicatiecredentials.

Na herstel door de beheerder:

```bash
git pull --ff-only origin main
php tests/run.php
php -l src/Moderation/SpotterIdentity.php
```

De suite blijft op een blokkerende runtime expliciet falen; geen overgeslagen cryptografietests of geslaagde fallback. Een onderbroken historische retrieve met dezelfde eerder gebruikte full/days/all-modus hervatten, niet blind naar since-last wisselen. Eventuele hercontrole van al opgeslagen spots is een afzonderlijke refresh-beslissing; geen databasegegevens/cursors wissen.

Op `nnspotty.hopseflop.nl` is eerder een aparte OpenSSL-configuratie voor PHP ingericht. Een losse `php tests/run.php` gebruikt die niet automatisch. Gebruik vanuit de deploymap de bedoelde PHP-runtime en de bestaande configuratie:

```bash
test -r /home/hopseflop/.config/nnspotty/openssl.cnf
OPENSSL_CONF=/home/hopseflop/.config/nnspotty/openssl.cnf \
    /opt/remi/php84/root/usr/bin/php tests/run.php
```

Dit maakt of wijzigt geen OpenSSL-configuratie en verlaagt geen systeembeleid. Ontbreekt het bestand of blijft RSA/SHA1 geblokkeerd, laat de beheerder de configuratie en de bovenstaande diagnostiek controleren; de omgevingsvariabele alleen garandeert geen herstel. Een CLI-instelling configureert PHP-FPM niet. Een HTTP 302 naar `/spots` bevestigt alleen dat de webroute reageert, niet dat RSA/SHA1 werkt.

### Schone Spotlist-installatie en retrieve

Voor de afgesproken schone spotinstallatie moeten spotdata **en bijbehorende spot-/commentcursors** coherent leeg zijn voordat je opnieuw begint. Er wordt hier bewust geen destructief leegmaakcommando gegeven. Stel in admin eerst retentie/begindatum/groepen goed in. Start pas na geslaagde sync/activatie:

```bash
php bin/console retrieve --all --verbose
# Dagelijks croncommando blijft:
php bin/console retrieve --since-last --verbose
```

Acceptatie:

1. Admin > Moderatie toont laatste succesvolle update, sleutelcounts, fouten/waarschuwingen, aan/uit en **Lijsten nu bijwerken**. Een gewone gebruiker mag niet opslaan/synchroniseren; POST vereist CSRF. Oude naamlijsten zijn herkenbaar niet-actief.
2. Verbose retrieve toont per batch `blacklist` en `onbevestigd`; voltooiingsmelding bevat blacklist-skipcount. Alleen geldige message-ID-handtekeningen met bekende blacklistsleutel worden geweigerd. Onbekende/ongeldige identiteiten blijven bestaan, ook als hun naam gelijk is aan een naam op de blacklist. Blacklist wint van whitelist.
3. Controleer een spot van een bekende whitelistsleutel in lijst (spotterkolom aan), preview en detail, ook na TVDB-refresh en in donker/licht/mobiel. Badge verklaart sleutel/message-ID-lijststatus, niet de inhoud. Zonder bevestigde identiteit nooit whitelistbadge.
4. Een volgende retrieve binnen 24 uur haalt niet opnieuw lijsten op; na 24 uur wel. `--comments-only` doet geen lijstsynchronisatie. Handmatig sync blijft altijd mogelijk. Een lopende retrieve gebruikt zijn startsnapshot.
5. Bij een externe updatefout blijven beide vorige snapshots intact en wordt de fout in admin/console en runlog gemeld. Zonder ooit geslaagde snapshot faalt een actieve spot-retrieve. Een bron met enkele ongeldige sleutels geeft een zichtbare waarschuwing; een volledig onbruikbare/lege feed wordt geweigerd.
6. `php bin/console spotters disable` stopt filtering/badges zonder spots of lijsten te wissen; `enable` herstelt ze. Een later geblackliste, al opgeslagen spot wordt niet verwijderd/verborgen maar krijgt een blacklistbadge. Bestaande onbevestigde spots worden niet automatisch via NNTP herbeoordeeld.

De database-/NNTP-/HTTP-regressies draaien met `php tests/run.php --integration` op uitsluitend een aparte testdatabase met de `NNSPOTTY_TEST_*`-variabelen uit de README. De test genereert tijdelijke RSA-sleutels en synthetische feeds; geen echte Usenetcredentials of productiegegevens nodig.

## Browseperformance en sessielocks

Voer uit in de bestaande deploymap op `nnspotty.hopseflop.nl`. Bewaar `.env`, maak eerst een beveiligde databasebackup volgens de procedure hieronder en pauzeer webverkeer/retrieve-jobs tijdens de migratie. Nieuwe indexen op een grote spottabel kosten tijd en extra schijfruimte; laat de migratie uitlopen.

```bash
cd <pad-naar-project>
git status --short
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php bin/console db migrate
php bin/console db status
php bin/console db health
php tests/run.php
curl -fsS https://nnspotty.hopseflop.nl/health
curl -fsS -o /dev/null -w 'Nieuwtellers-JS: %{http_code}\n' https://nnspotty.hopseflop.nl/assets/facets.js
```

Controleer migratie `20261019_0015_browse_performance`. Geen nieuwe/full retrieve nodig. Hervat verkeer/jobs en herlaad met Ctrl+F5.

1. Log in als admin. Open **Alle**, **Beeld**, **Films**, een EN-filtercombinatie en een detail. De HTML bevat direct de eerste beperkte batch; nieuwtellers komen via `/spots/facets` apart binnen. Het overzicht/detail hoort niet te wachten op de telrequest. De rootpagina doet geen telrequest. Aantallen blijven exact en volgen dezelfde persoonlijke grens/zichtbaarheid/zoektekst/EN-filters.
2. Blokkeer alleen `/spots/facets` in DevTools: filterlinks blijven werken, tellers tonen geen valse nullen en er verschijnt een zichtbare fout met **Opnieuw proberen**. Hef de blokkade op en probeer opnieuw. Controleer ook zonder JavaScript: filters blijven bruikbaar, de uitleg meldt dat tellers JavaScript nodig hebben.
3. Zet tijdelijk `APP_TIMING=true` in de bestaande `.env` (of de gelijknamige PHP-FPM-omgeving). Herlaad PHP-FPM alleen indien jouw deployment dat voor gewijzigde omgevingsvariabelen vereist. Bekijk ingelogd als admin in DevTools > Network de `Server-Timing`-header: `session`, `database`, `application`. Meet apart de documentrequest, telrequest, afbeelding en vervolgbatch. Een gewone gebruiker krijgt deze diagnoseheader niet. Zet de optie na diagnose weer uit. Deel geen sessiecookies of productiecredentials.
4. Test een niet-gecachete cover terwijl je met **dezelfde ingelogde browsersessie** in een tweede tab het overzicht opent of verder bladert. Het tweede verzoek mag niet op de NNTP-cover wachten. Gebruik PHP-FPM met meerdere workers; de PHP developmentserver met maar een worker kan zelf geen parallelle requests afhandelen.
5. Vergelijk een veel voorkomende titelterm, een schaarse deeltekst en een niet-bestaande titel. Zoekresultaten blijven datum/ID-gesorteerd, `%`, `_` en `!` zijn letterlijk, vervolgpagina's hebben geen dubbelen. Schaarse/ontbrekende termen kunnen nog steeds een volledige databasescan vereisen; noteer echte servertijden en database-/FPM-belasting, niet alleen de duur van het laden van alle covers.
6. Test donker/licht en mobiel/desktop, detail/preview, browser-terug naar een diepe lijst, verborgen erotica en nieuwe imports tijdens dezelfde sessie. De voorkeuren voor reacties/afbeeldingen blijven leidend. Geen externe whitelist-/blacklistimport of nieuwe retrieve-incrementinstelling in deze update.

Voor de database-/HTTP-regressies en benchmark gebruik je uitsluitend een **aparte** MySQL/MariaDB-testserver met testcredentials en rechten om testschema's te maken/verwijderen. Zie de README voor `NNSPOTTY_TEST_*`; de benchmark kan minuten duren en meerdere GiB opslag gebruiken:

```bash
php tests/run.php --integration
NNSPOTTY_PERF_SPOTS=300000 php tests/browse-performance.php
NNSPOTTY_PERF_SPOTS=4300000 php tests/browse-performance.php
```

De suite test echte gelijktijdigheid met twee lokale PHP-workers en een NNTP-afbeelding die drie seconden wacht: een batch plus retrieve-status van dezelfde sessie moeten samen binnen 1,5 seconde gereed zijn, terwijl de afbeelding nog wacht. Benchmarktijden zijn lokale synthetische metingen, geen productietijden of garantie voor jouw hardware.

Referentie op MariaDB 11.4.9 met 256 MiB InnoDB-buffer: 1,3 miljoen synthetische spots gaf gewone batches van circa 7-34 ms; de exacte nieuwtelling duurde 12,6 s, maar zit niet meer in de eerste HTML-request. De definitieve 300k-zoekrun gaf 3,2 ms voor een dichte term en 1,22 s voor een ontbrekende term, tegenover respectievelijk 1,1 ms en 4,16 s voor de oude queryvorm. Een schaarse eerste batch was juist langzamer (1,42 s tegenover 0,27 s voor de later gemeten oude vorm). Cachevolgorde/hardware spelen mee; vergelijk daarom ook je eigen zoektermen en niet alleen de gunstigste meting. Geen belofte dat iedere zoekactie sneller is. De gewone eerste batches blijven klein (gemeten 50-74 indexstappen, 2 MiB PHP-piek in de 300k-benchmark).

## Browser-terug naar de plek in de spotlijst
Deze update vereist geen nieuwe migratie of retrieve. Voer vanuit de bestaande deploymap op `nnspotty.hopseflop.nl` uit:

```bash
git status --short
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php tests/run.php
curl -fsS https://nnspotty.hopseflop.nl/health
curl -fsS -o /dev/null -w 'Spotlijst-JS: %{http_code}\n' https://nnspotty.hopseflop.nl/assets/spots.js
```

Herlaad eerst met Ctrl+F5 om de nieuwe JavaScriptversie te laden:

1. Kies een hoofdgroep en zoekterm/filters met voldoende resultaten. Laad minimaal 500 en daarna 1000 spots, scroll naar een herkenbare regel en open de volledige detailpagina via dubbelklik. Browser-terug moet dezelfde regel op dezelfde plek tonen met minstens de eerder geladen lijstlengte, niet alleen de eerste batch.
2. Herhaal via enkele klik, preview en de knop voor de volledige spot. Test terug/vooruit/terug en meerdere afzonderlijke zoek-/filteroverzichten: iedere geschiedenisvermelding houdt zijn eigen plek; een nieuw overzicht start normaal bovenaan.
3. Test zowel desktop (interne lijstscroll) als mobiel (paginascroll). Dit moet ook werken als de browser de pagina niet in de back-forward-cache bewaart; dan worden benodigde batches opnieuw geladen met zichtbare voortgang.
4. Simuleer een mislukte batchrequest tijdens herstel: een zichtbare waarschuwing verschijnt. Na herstel van de verbinding gaat **Meer resultaten laden** verder met het herstel van de oorspronkelijke plek. Een verlopen login wordt expliciet gemeld.
5. Er wordt geen spotinhoud in browseropslag bewaard. De server bepaalt opnieuw de actuele resultaten en zichtbaarheid. Een veranderde index kan regels verschuiven; een bestaand zichtbaar spotanker corrigeert de positie. Een verwijderde spot kan niet worden hersteld.

## Persoonlijke nieuwtellers en Plex-accenten
Maak een beveiligde databasebackup volgens de bestaande procedure hieronder. Pauzeer webverkeer en retrieve-jobs tijdens de migratie: de ALTER van de spotstabel kan bij een grote index tijd kosten. Voer vanuit de bestaande deploymap op `nnspotty.hopseflop.nl` uit, zonder `.env` te overschrijven:

```bash
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
curl -fsS -o /dev/null -w 'CSS: %{http_code}\n' https://nnspotty.hopseflop.nl/assets/spotty.css
```

Migratie `20261018_0014_browse_visits` moet toegepast zijn. Hervat verkeer/jobs en herlaad met Ctrl+F5:

1. Eerste bezoek na upgrade: bestaande historie telt niet als nieuw. De filterbadges hebben uitleg en tonen alleen nieuwe imports; filters blijven de volledige resultaten filteren, niet alleen nieuwe spots. Het overzicht berekent geen exacte totaaltelling.
2. Verlaat de sessie nadat je het overzicht hebt bezocht. Haal daarna nieuwe spots op met `php bin/console retrieve --since-last --verbose` (gebruik bij een schone installatie eerst de historische import uit de README). Log opnieuw in: de badge Films toont uitsluitend de nieuw geimporteerde films, niet het eerdere totaal plus die films.
3. Herlaad, wissel hoofdgroep/filter/zoektekst en open een detail: de persoonlijke grens blijft gelijk. Nieuwe imports tijdens dit bezoek mogen de teller verhogen. Een andere gebruiker heeft een eigen bezoekgeschiedenis. Een nieuwe sessie voor dezelfde gebruiker gebruikt de laatst bewaarde browseactiviteit; reeds geopende sessies behouden hun eigen grens.
4. Test terug-/inloggen of een echt nieuwe browsersessie: de vorige grens wordt vervangen. Een tab delen is geen nieuw bezoek; browsers die sessiecookies herstellen kunnen dezelfde sessie behouden. Achtergrondbijladen, metadata-/downloadpolling en Newznab markeren geen bezoek.
5. Controleer dat verborgen erotica ook niet via aantallen lekt; zoektekst en alle EN-filters moeten tellers beperken. Een refresh van bestaande spotmetadata mag de importtijd/nieuwstatus niet vernieuwen.
6. Controleer login, overzicht, detail, admin en voorkeuren in Default/Plex Dark/Plex Light, op desktop en mobiel. Primaire knoppen, badges, filterchips en schakelaars zijn oranje/goud; hover/focus/disabled blijven leesbaar. Danger/success/info houden hun betekenis, overige layout/achtergronden veranderen niet.

MusicBrainz is inmiddels beschikbaar als optionele muziekprovider; zie de muziekchecks hieronder. IGDB/RAWG, AppStream en Open Library blijven roadmap-opties, vastgelegd in projectinstructies/second brain.

## Muziekmetadata: MusicBrainz
Wacht met upgraden tot je actieve retrieve klaar is of pauzeer je eigen job. Maak een databasebackup en voer op `nnspotty.hopseflop.nl` vanuit de bestaande deploymap uit:

```bash
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php bin/console db migrate
php bin/console db status
php tests/run.php
curl -I https://nnspotty.hopseflop.nl/assets/metadata.js
```

Controleer APP_URL in je bestaande `.env`: publieke HTTP(S)-contact/project-URL zonder credentials/query/fragment. Geen API-key nodig. Werk CLI/FPM CA-certificaten bij indien HTTPS faalt; schakel TLS-controle niet uit. Test:
1. Schakel in onder `/admin#music-metadata`, daarna voor jezelf onder `/preferences#music-metadata`. Beide staan standaard uit en werken onafhankelijk van TVDB.
2. Open een herkenbare muziekspot, bijvoorbeeld artiest-album-jaar-FLAC. Controleer async metadata, MusicBrainz link en bronattributie; alleen overtuigende unieke naam/jaarcombinaties matchen automatisch. Geen match is geen foutieve albumkeuze. Test specifiek:
   - `Paul McCartney - The Boys of Dungeon Lane DeLuxe in DTS-HD-*HRA*. ( OV )`: artiest Paul McCartney, albumgroep **The Boys of Dungeon Lane**, geen claim over de Deluxe/DTS-uitgave.
   - `Spandau Ballet-True-LP-32BIT-WAVPACK-1983-REETKEVER`: artiest Spandau Ballet, albumgroep **True**, geen codec/uploader in de zoekterm.
   - `1973 - Alice Cooper - Killer [USA Vinyl LP 24-192 kHz]`: artiest Alice Cooper, albumgroep **Killer**; bronjaar 1973 blokkeert het oorspronkelijke album uit 1971 niet.
   - `Simply Red - Holding Back The Years (Live In Santiago 2026)`: bruikbare titel-/artiestsuggesties via adminzoeken, maar geen automatische koppeling aan studioversie of verkeerde liveopname.
   - `Taylor Swift - 1989 FLAC` en `Prince - 1999 FLAC`: numerieke albumtitel blijft onderdeel van de zoekterm.
   - `Hilary Duff-Holiday Things-EP-24BIT-WEB-FLAC-2026-ENRiCH`: EP **Holiday Things** (2026).
   - `Amy Winehouse-Back To Black-(20th Anniversary Deluxe Edition)-3CD-2026-C4`: album **Back to Black** uit 2006, niet de gelijknamige single.
   - `Diverse Artiesten - 538 Dance Smash Hits 1997-vol.2 in DTS-HD-*HRA* ( OV )`: Various Artists, **538 Dance Smash Hits 1997, Volume 2** (niet volume 1).
   - `Miranda Lambert - Crisco (2026) [Hi-Res]`: album **Crisco** (2026), niet de single.
   - Omgedraaide volgorde zoals `Back To Black - Amy Winehouse (2006) FLAC` koppelt ook aan het album.
3. Zoek als admin via MusicBrainz link. Kies een specifieke uitgave voor tracklist/label/speelduur; vergelijk MBID, artiest, jaar en editie met MusicBrainz. Reset en blokkering gelden voor iedereen; een gewone gebruiker ziet geen matchbeheer.
4. Controleer een artiest/discografie: artiestweergave met decoratief symbool, geen willekeurig album of verzonnen artiestfoto/biografie.
5. Heropen dezelfde spot: databasecache; open kleine preview: uitsluitend cache, geen `/music-metadata` of server-side providerrequest. Zonder match/verlopen cache blijft de oorspronkelijke preview.
6. Persoonlijke opt-out/globaal uit moeten ook cachehits blokkeren. Afbeeldingen uit laat tekst zien zonder Cover Art Archive-URL's. Ontbrekende hoes valt terug op placeholder.
7. Controleer donker/licht en mobiel/desktop, vierkante hoezen, tracklist zonder horizontale paginaoverflow, attributie en bestaande NZB/SAB/reactieacties. Test TVDB opnieuw: eigen voorkeuren, film-/seriehero en matchpaneel blijven werken. Hard refresh om de gedeelde metadata.js bij te werken.
8. Een 503/timeout/bezette limiet moet zichtbaar zijn in het paneel, met oorspronkelijke spot bruikbaar; geen snelle automatische retry. Na foutcache van vijf minuten opnieuw controleren. MusicBrainz-requeststarts zijn installatiebreed begrensd tot minimaal 1,1 seconde; beheer de gezamenlijke limiet bij meerdere installaties achter hetzelfde publieke IP.

Uitgebreide tests gebruiken uitsluitend een afzonderlijke testserver via `NNSPOTTY_TEST_DSN`/`NNSPOTTY_TEST_USER`/`NNSPOTTY_TEST_PASSWORD` en `php tests/run.php --integration`; geen productiecredentials.

Voor alleen deze discoveryverbetering is geen extra migratie/retrieve/cacheflush nodig. Voer vanuit de bestaande deploymap op `nnspotty.hopseflop.nl` uit:

```bash
git pull --ff-only origin main
php tests/run.php
```

Open eerder niet gevonden spots opnieuw in de volledige detailweergave: oude automatische/negatieve caches worden opnieuw beoordeeld. Controleer dat bestaande handmatige koppelingen en blokkeringen juist behouden blijven, ook als het oude matchresultaat nog vers was. Kleine previews lezen uitsluitend de nieuwe verse resultaatcache; ze starten geen discovery.

## Artiest-fanart via fanart.tv

Voer op `nnspotty.hopseflop.nl` vanuit de bestaande deploymap uit, na een beveiligde databasebackup en tijdens je onderhoudsmoment:

```bash
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php bin/console db migrate
php bin/console db status
php tests/run.php
curl -I https://nnspotty.hopseflop.nl/assets/spotty.css
```

Migratie `20261019_0018_music_fanart` voegt globale en persoonlijke fanartschakelaars (default-off) en server-side keyopslag toe. Geen retrieve, backfill of cacheflush nodig; bestaande handmatige muziekkeuzes/blokkeringen blijven geldig. Herlaad CSS zonder oude cache.

1. Zet eerst muziekmetadata globaal/persoonlijk aan zoals hierboven. Onder `/admin#music-metadata` vul je bij Artiest-fanart de fanart.tv-API-key in en schakel je globaal in. Zet onder `/preferences#music-metadata` ook je persoonlijke fanartschakelaar aan. Plak de key niet in commando's, screenshots, logs of tickets.
2. Open een gematcht album van een artiest met fanart. Controleer vierkante albumhoes, brede artiestachtergrond, leesbare tekst in donker/licht en op mobiel/desktop, plus **Artiest-fanart: fanart.tv**-bronlink. Controleer dezelfde artiest via een discografiespot en een handmatig gekozen specifieke uitgave.
3. Open de kleine preview na detail: achtergrond hergebruikt bestaande cache. Een verse preview zonder fanartcache haalt niets op. Geen nieuwe fanart-/MusicBrainz-serveraanvraag bij scrollen of preview; browser mag wel de gecachte externe artwork-URL laden.
4. Test een artiest zonder fanart: gewone achtergrond zonder foutieve andere artiest. Various Artists en credits met meerdere artiesten krijgen geen willekeurige artiestfanart.
5. Zet persoonlijke fanart uit: alleen achtergrond/bronlink weg, MusicBrainz en albumhoes blijven. Andere gebruiker met toestemming behoudt fanart. Test ook globale fanart uit, persoonlijke/globale muziek uit en **Afbeeldingen uit**; opt-outs blokkeren ook cachehits en starten geen fanartrequest.
6. Leeg API-keyveld opslaan behoudt de bestaande key en toont hem nooit terug. Test key wissen met globale fanart uit. Beveilig database en backups zoals bestaande TVDB-/downloadergeheimen.
7. Een onjuiste key/providerfout geeft bij de eerstvolgende niet-gecachete artiest een zichtbare waarschuwing, met bruikbare muziekmetadata. Foutcache duurt vijf minuten, geen automatische retries. Herstel de key; andere key gebruikt niet dezelfde foutcache. TLS nooit uitschakelen. Server moet HTTPS naar `webservice.fanart.tv` kunnen; browser laadt alleen `assets.fanart.tv` voor deze achtergronden.
8. Check actuele [API-voorwaarden](https://fanart.tv/api-info/) en artworkrechten voor jouw gebruik. De ontwikkeltests gebruiken synthetische providerdata; geauthenticeerde acceptatie met je eigen key voer je hier uit.

## Fanart zonder uitsnijden (muziek, films en series)

Deze aanpassing is uitsluitend CSS. Voer vanuit de bestaande deploymap op `nnspotty.hopseflop.nl` uit:

```bash
git pull --ff-only origin main
php tests/run.php
curl -I https://nnspotty.hopseflop.nl/assets/spotty.css
```

Geen migratie, retrieve, API-keywijziging of providercacheflush nodig. Herlaad met Ctrl+F5 zodat de nieuwe CSS geladen wordt.

- Vergelijk gematchte Simply Red/Spandau Ballet/Alice Cooper met de providerafbeelding: de fanart wordt volledig rechts ingepast, zonder zoom/uitrekken of uitsnede boven/onder. Links vloeit de afbeelding geleidelijk naar de tekstachtergrond.
- Controleer hetzelfde bij film, serie en aflevering, zowel detail als kleine preview, donker/licht en op 390/1440/1920px. Lange titels en synopsissen blijven bruikbaar; de bestaande inhoud bepaalt de bannerhoogte. Op smalle kaders mag vrije ruimte boven/onder de afbeelding zichtbaar zijn.
- Geen horizontale overflow, albumhoes blijft vierkant en posters/afleveringstills houden hun bestaande vorm. Bronlinks en NZB/SAB/reactieacties blijven werken.
- Afbeeldingen uit toont geen achtergrond; zonder fanart blijven bestaande fallbacks gelden. Alleen de metadatahero verandert, niet de originele spotcover/lijstweergave. Geen extra providerrequests.

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
3. Klik als admin op **TVDB link** linksboven in de hero. Alle matchinformatie staat in het rechts inschuivende paneel, niet meer boven de hero. Zoek een bekende titel, kies en sla op; controleer de gedeelde correctie als gewone gebruiker. Test automatisch herstellen en **Geen metadata voor deze spot**. De hero-link moet na iedere vervanging blijven werken. Onvindbare handmatige afleveringen mogen een geldige bestaande keuze niet overschrijven. Gewone gebruikers zien alleen status/opnieuw controleren, niet de correctieacties en mogen die ook niet via POST uitvoeren. Sluiten/Escape moet uitschuiven en focus op de actuele hero-link terugzetten, ook na een correctie; smalle panelen scrollen zelfstandig.
4. Controleer cast/crew, studio/netwerk, originele releasetitel/omschrijving/technische informatie en reacties. Test de coverlightbox en bestaande NZB-/SABnzbd-acties na asynchroon laden. Ratings, reviews, playback en klikbare cast-/bedrijfsfilters zijn geen onderdeel van deze stap; populariteit is geen beoordeling.
5. Heropen dezelfde spot en een andere release van dezelfde titel: databasecache moet herhaalde providerrequests beperken. Entiteiten/matches zeven dagen, zoeken/negatieve matches een dag, fouten vijf minuten. **Metadata opnieuw controleren** negeert deze TTL niet. Er is een centrale limiet van 90 uitgaande requests/minuut, geen onbegrensde achtergrondbackfill.
6. Test een onduidelijke/gelijknamige titel: geen gegokte metadata. Test met tijdelijk ongeldige TVDB-toegang: expliciete fout, oorspronkelijke spot bruikbaar, geen retrylus. Na key/PIN/taalwijziging worden matchstatussen opnieuw controleerbaar; reeds gecachte providerrecords behouden hun eigen TTL.
7. Schakel metadata persoonlijk uit: nieuwe details/requests mogen geen TVDB-data laden. Globaal uit blokkeert ook eerder toegestane admins/gebruikers, maar bewaart hun voorkeur. Test een verborgen spot/erotica: geen metadata-toegang. Schakel postafbeeldingen uit: ook TVDB-posters/fanart/castfoto's verdwijnen, tekst en oorspronkelijke spot blijven beschikbaar.
8. Overzicht/preview en gewone retrieval mogen geen TVDB-requests starten. Test volledige film- en afleveringsdetails op 320/390/768/1440px in donker/licht, zonder horizontale pagina-overflow of overlappende acties.
9. Controleer **Metadata van TheTVDB** met het passende kleine logo bij de synopsis; tekst/logo linken rechtstreeks naar het juiste TVDB-record. Ook met postafbeeldingen uit blijft deze lokale attributie zichtbaar. Zonder getoonde TVDB-data mag geen attributie staan.

### Attributie-/paneelupdate op bestaande TVDB-installaties
Geen nieuwe migratie of retrieval nodig bovenop 0013. Vanuit de bestaande projectmap op `nnspotty.hopseflop.nl`:

```bash
umask 022
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php tests/run.php
for asset in metadata.js spotty.css images/tvdb-dark.png images/tvdb-light.png; do
  curl -fsS -o /dev/null -w "$asset: %{http_code}\n" "https://nnspotty.hopseflop.nl/assets/$asset"
done
curl -sS -o /dev/null -D - https://nnspotty.hopseflop.nl/
```

Alle vier assets moeten HTTP 200 geven; root moet naar `/spots` verwijzen. Herlaad met Ctrl+F5 en controleer na uit-/inloggen dat direct het overzicht verschijnt, zonder de oude homepage. Test vervolgens de paneel-/attributiechecks hierboven. De bestaande servercredentials en persoonlijke TVDB-toestemming blijven behouden.

### Titelherkenning, releasekwaliteit en cast-placeholders
Geen nieuwe migratie bovenop 0013; voer vanuit de bestaande projectmap uit:

```bash
git status --short
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
php tests/run.php
php bin/console db health
curl -fsS https://nnspotty.hopseflop.nl/health
curl -fsS -o /dev/null -w 'CSS: %{http_code}\n' https://nnspotty.hopseflop.nl/assets/spotty.css
curl -fsS -o /dev/null -w 'Placeholder: %{http_code}\n' https://nnspotty.hopseflop.nl/assets/images/placeholder-small.png
```

Herlaad met Ctrl+F5 en controleer:
- `03. The Dark Knight Rises (2012) IMAX 2160p AI-Enhanced, Rife 60fps Dolby True-HD`: zoekterm **The Dark Knight Rises**, jaar 2012; unieke exacte providerhit wordt gekoppeld. Kwaliteit toont 4K, IMAX, AI-enhanced, RIFE, 60 fps en TrueHD, niet een verzonnen audiotaal/HDR/codec.
- Het opgegeven LOTR-pakket met Theatrical Version / 4K / `3 x BD100`: opgeschoonde zoekterm **The Lord of the Rings**, geen automatische keuze voor een enkele film. Video/audio/NL-retailondertiteling blijven zichtbaar, ook zonder match.
- `De.Bondgenoten.S04E01.DUTCH.1080p.WEB.h264-KIJKDOOS`: exact seizoen 4/aflevering 1 wordt gevraagd; geen omrekening naar een ander seizoen. Ontbreekt dit paar bij TVDB, dan alleen seriedata en een expliciete melding met het aangevraagde nummer. Bij onderzoek bood de publieke pagina alleen officiele seizoenen 1-3. Controleer ook een werkelijk beschikbare aflevering van een andere serie.
- Releasekwaliteit blijft aanwezig zonder persoonlijke TVDB-toestemming, na asynchrone verrijking en in de preview. Onbekende waarden ontbreken; de bronmelding vermeldt onbevestigde claims uit de releasetitel.
- Cast/crew zonder foto en een gecontroleerd geblokkeerde fotorequest krijgen beide een ronde 120px-placeholder, niet een lege ruimte. Schakel afbeeldingen uit: geen castfoto-/placeholderverzoeken. Test donker/licht op 320/390/768/1440px, inclusief sluitbare TVDB-drawer en coverlightbox.
- Oude matchresultaten worden bij detailbezoek opnieuw beoordeeld; handmatige keuzes/blokkeringen blijven staan. Providerrecords en afleveringlijsten behouden hun TTL van zeven dagen: deze update is geen geforceerde cacheflush of nieuwe retrieval.

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

Alle zes afbeeldingen moeten HTTP 200 geven. Herlaad met Ctrl+F5 en controleer het tabbladicoon, het logo op de loginpagina en het headericoon. Open een spot zonder afbeelding: overzicht, preview en detail moeten de eigen coverplaceholder tonen, zonder waarschuwingstekst. Ook de hero gebruikt deze als blurred achtergrond, net als bij een mislukte cover. Uitgeschakelde postafbeeldingen blijven tekst zonder placeholder of achtergrond. Test mobiel en beide thema's. De presentatie en ongewijzigde bronbestanden staan onder `docs/branding/`; alleen verkleinde afgeleiden worden publiek geladen. Houd de nieuwe directories voor de webserver toegankelijk en PNG's leesbaar, zonder `.env`-rechten te verruimen.

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
  - optionele begindatum **Spots ophalen vanaf** (de latere van deze datum en retentie geldt)

## Gecachte TVDB-metadata in spotpreview
Voer op `nnspotty.hopseflop.nl` vanuit de bestaande deploymap uit:

```bash
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php tests/run.php
```

Geen migratie of retrieve nodig. Test met globaal en persoonlijk ingeschakelde TVDB-metadata:
1. Open de preview van een nog niet gekoppelde film/serie: oorspronkelijke spot, geen `/metadata`-aanvraag in DevTools.
2. Open de volledige detailview en wacht op een geslaagde TVDB-match. Keer terug naar het overzicht en open de preview opnieuw: TVDB-titel, synopsis, artwork/cast en bronvermelding verschijnen, met behoud van oorspronkelijke titel/omschrijving en spotacties.
3. De preview doet uitsluitend `/spots/preview`; geen nieuwe `/metadata`-aanvraag of server-side TVDB-lookup. Afbeeldingen kunnen wel rechtstreeks vanaf de TVDB-artworkserver laden.
4. Persoonlijke opt-out, globale uitschakeling of een door admin geblokkeerde match geeft de oorspronkelijke preview. Globaal afbeeldingen uit houdt de tekstmetadata zichtbaar zonder externe artwork-URL's.
5. Verlopen matches of gewijzigde taal/spotinvoer vallen terug op de oorspronkelijke preview, zonder automatisch te verversen. Open de volledige detailview voor nieuwe metadata.
6. Controleer donkere/lichte thema's op mobiel/desktop, afleveringstill en de acties naar volledige spot, NZB en SABnzbd. Overzichtsregels en filters veranderen niet.

## Retrieve checks
```bash
# na opslaan van instellingen in admin:

php bin/console retrieve --since-last
php bin/console retrieve --days=30
php bin/console retrieve --full
php bin/console retrieve --days=30 --verbose
```

`--since-last` verwerkt vanaf de oudste nog niet verwerkte positie maximaal het ingestelde aantal artikelen per groep. Herhaal dit om achterstand in te lopen. `--days`, `--all` en `--full` beginnen bij de hoogste artikelranges, zodat recente spots eerder zichtbaar zijn. Met een datumgrens stoppen ze per groep na drie opeenvolgende niet-lege batches met uitsluitend geldige datums strikt voor de grens. Een recente/ongeldige regel of lege batch onderbreekt de reeks. Dit geldt ook voor comments-only en refresh-spots; zonder datumgrens wordt de hele historie gescand. Deze snelheidsafweging kan recente berichten achter de stop missen, omdat artikelnummers niet betrouwbaar op datum gesorteerd zijn. Retentie en de optionele begindatum begrenzen wat wordt opgeslagen. Iedere geslaagde retrieve verwijdert alle spots ouder dan de retentie, plus gekoppelde reacties, in transactiebatches van maximaal 1000 zonder maximum per run. Retentie `0` schakelt deze verwijdering uit.

Maak voor de eerste opruimrun of een retentieverlaging een databasebackup. Test op `nnspotty.hopseflop.nl` vanuit de deploymentmap:

```bash
php tests/run.php
php bin/console retrieve --since-last --verbose
```

Controleer het totale aantal verwijderde spots in de eindmelding; dat mag groter zijn dan 1000. Een grote bestaande achterstand kan de run verlengen. De status blijft actief tijdens het opruimen; bij een fout wordt de run failed en hervat de volgende run de resterende opruiming.

Bij verbose output staat nu per batch het UTC-datumbereik en `te_oud`, `bestaand`, `geen_spot`, `ongeldig`, `verdwenen`, `opgeslagen` plus het totale aantal opgeslagen berichten. `te_oud=250` verklaart bijvoorbeeld waarom een batch niets opslaat. Ontbrekende optionele overview-velden zijn toegestaan. Een echt afgebroken regel wordt met range, regelnummer en veldaantallen gemeld en overgeslagen; de overige regels gaan door. Een transportfout blijft fataal.

Voor de overview-fix is geen extra databasemigratie nodig. Na deployment:

```bash
php tests/run.php
php bin/console retrieve --days=30 --verbose
```

Controleer dat de eerste `free.pt batch` nu bij het hoogste artikelnummer eindigt. Herhaal de run na een fout gerust: opgeslagen spots/reacties worden niet verdubbeld en een onvolledige omgekeerde scan verplaatst de incrementele cursor niet voorbij ongelezen historie.

### Stoppen bij de datumgrens
Wacht tot een lopende retrieve klaar is of stop je eigen foreground-run met Ctrl+C voordat je bijwerkt. Voer op `nnspotty.hopseflop.nl` vanuit de bestaande deploymap uit:

```bash
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php tests/run.php
php bin/console retrieve --full --verbose
php bin/console retrieve --since-last --verbose
```

Er is hiervoor geen migratie of cursorreset nodig. Stel voor een korte acceptatierun in het beheerpanel **Spots ophalen vanaf** op de eerste dag van de huidige maand in (retentie blijft een eventuele strengere grens). Controleer per groep de melding `gestopt na 3 opeenvolgende volledig oude batches` en dat daarna geen lagere XOVER-batches voor die groep verschijnen. Lege/ongeldige batches kunnen de stop uitstellen; dit wordt niet als een oude batch beschouwd. De run gaat normaal door met de volgende ingeschakelde groep, retentieopruiming en status completed. Een volgende `--since-last` gaat verder na het eerder gemelde groepseinde, niet opnieuw vanaf het begin van de historie.

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
- Alleen ondersteunde message-ID-handtekeningen worden gecontroleerd voor sleutelmoderatie. XML, overige headers, reacties en inhoud blijven onbevestigd; een whitelistbadge is geen inhoudsgarantie.

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

`--comments-only` moet uitsluitend `comments free.usenet` melden, niet `spots free.pt`. Ook deze datumsessie begint bij de hoogste artikelnummers en stopt na drie opeenvolgende volledig oude batches; recente berichten achter die stop kunnen worden gemist. Gebruik een ruimere periode voor oudere reacties/spots. De incrementele limiet geldt alleen bij `--since-last`; deze begint bij historische achterstand niet automatisch bij de nieuwste reacties.

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

De loginpagina hoort in de HTML `<html lang="nl" data-bs-theme="dark">` te bevatten en toont ook zonder gebruikersvoorkeur een donkere Bootstrap-weergave. Ingelogde pagina's blijven de persoonlijke Default/Plex Dark/Plex Light-keuze volgen.

Controleer na inloggen dat de header precies een zoekveld heeft, de hoofdcategorieen bovenaan de linker sidebar staan en de subcategorieen daaronder volgen. Filters moeten naast **Kolommen instellen** zichtbaar zijn; Voorkeuren en (alleen admin) Beheerpanel staan onder de username. Het overzicht hoort alleen de ingestelde eerste laadbatch te tonen; scrollen laadt verdere batches op aanvraag. De header toont de laatste uitgevoerde retrieve (handmatig of cron). Voor admins start **Retrieve** een incrementele run; de knop krijgt een spinner totdat de run is voltooid. De statusendpoint is alleen voor ingelogde gebruikers:

```bash
curl -i https://nnspotty.hopseflop.nl/retrieve/status
```

Zonder login hoort dit `401` te geven. Test een daadwerkelijke retrieve alleen nadat de Usenet-instellingen zijn gecontroleerd; de knop verwerkt nieuwe artikelen.

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

## Persoonlijke favorieten accepteren

Op `nnspotty.hopseflop.nl`, vanuit de bestaande installatie:

```bash
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php bin/console db migrate
php bin/console db status
php tests/run.php
```

Ververs de browsercache. Markeer een spot via de bladwijzer met ster: deze wordt oranje zonder een preview te openen. Controleer dezelfde status in preview/detail, ook na laden van muziek-/TVDB-metadata. Klik opnieuw om te verwijderen. Open **Mijn spots > Favorieten**, test categorie/zoeken/bijladen en verwijder een favoriet; de row verdwijnt direct. Controleer dark/light en smal scherm. Herhaal met een tweede account: favorieten mogen niet gedeeld worden. Verborgen erotica blijft verborgen; spot-/gebruikersverwijdering ruimt verwijzingen op. Bij een netwerkfout blijft de knopstatus ongewijzigd en verschijnt een waarschuwing. Een opnieuw toegevoegde spot vanuit een nog open preview verschijnt na herladen terug in de favorietenlijst.

## Metadata-tab, TMDB en muziekmatching accepteren

Als deploygebruiker in de projectmap (servicebeheer als root):

```bash
cd /home/hopseflop/domains/nnspotty.hopseflop.nl/public_html
sudo -u hopseflop git pull --ff-only origin main
sudo -u hopseflop env OPENSSL_CONF=/home/hopseflop/.config/nnspotty/openssl.cnf /opt/remi/php84/root/usr/bin/php bin/console db migrate
sudo -u hopseflop env OPENSSL_CONF=/home/hopseflop/.config/nnspotty/openssl.cnf /opt/remi/php84/root/usr/bin/php bin/console db status
systemctl reload nnspotty-php84-fpm.service
```

Ververs de browsercache. Open `/admin#metadata`: subtabs Providers, Films, Series en Muziek. Vul onder Providers een TMDB API-key of Read Access Token in, schakel in en klik **Toegang testen**. Controleer onder Films (standaard TMDB) en Series (standaard TheTVDB) de primaire provider. Open een filmdetail: TMDB-score, classificatie, filmrol-icoon naast de favorietknop (opent de trailer in een venster; sluiten stopt de speler), gelijk uitgelijnde knoppen Reacties/NZB/SABnzbd en bovenaan naast Metadata link de bronvermeldingen met even grote TVDB- en TMDB-logo's. Open een aflevering: TVDB-data met aanvulling door TMDB (score/trailer). Test **Metadata link** > zoeken per provider en kiezen. Open de Beck-muziekspot en controleer de albumkoppeling. Ga naar Favorieten en klik een hoofdcategorie: het reguliere overzicht opent. Controleer fouten:

```bash
tail -n 50 /home/hopseflop/domains/nnspotty.hopseflop.nl/logs/php_log
```

### Automatische terugval tussen TheTVDB en TMDB

Voor deze wijziging is geen nieuwe migratie, retrieve of cacheflush nodig. Oude automatische/negatieve resultaten worden bij het volgende detailbezoek opnieuw beoordeeld; handmatige keuzes en blokkeringen blijven behouden. Als deploygebruiker:

```bash
cd /home/hopseflop/domains/nnspotty.hopseflop.nl/public_html
git pull --ff-only origin main
test -r /home/hopseflop/.config/nnspotty/openssl.cnf
OPENSSL_CONF=/home/hopseflop/.config/nnspotty/openssl.cnf \
    /opt/remi/php84/root/usr/bin/php tests/run.php
curl -fsS https://nnspotty.hopseflop.nl/health
```

Controleer met beide providers ingeschakeld en een persoonlijke opt-in:

1. Een film/serie die de voorkeursprovider uniek vindt blijft bij die provider. Ontbrekende gegevens worden zo mogelijk via externe IDs aangevuld, met attributie voor daadwerkelijk gebruikte bronnen.
2. Een titel zonder unieke match bij de voorkeursprovider, maar met een unieke match bij de andere, krijgt automatisch die andere metadata. **Metadata link** benoemt de terugval. Test beide voorkeursvolgordes voor films en series.
3. Een serie waarvan alleen de andere provider het gevraagde seizoen/aflevering kent krijgt die exacte aflevering. Ontbreekt die bij beide, dan alleen de eerst gevonden serie met de bestaande onbevestigde-afleveringsmelding.
4. Handmatig gekoppelde records blijven gekoppeld; een providerstoring vervangt die keuze niet. Een blokkering doet geen providerrequests. Schakel de tweede provider uit: geen tweede poging.
5. Providerstoring: bruikbare metadata van de andere provider blijft zichtbaar, met een waarschuwing in het paneel en een gemarkeerde Metadata link. Zonder bruikbaar resultaat blijft het een fout. Resultaten met een waarschuwing vernieuwen na vijf minuten; geen snelle retrylus. Een volgende geslaagde controle wist de waarschuwing.
6. Heropen de spot: matchcache; open de kleine preview: geen externe zoekactie. Controleer de oorspronkelijke omschrijving, NZB/SAB-acties en bronvermelding.

De database- en HTTP-regressies voor deze gevallen zitten in `tests/metadata-fallback-integration.php`, `tests/tmdb-integration.php` en `tests/tvdb-http.php`; voer `php tests/run.php --integration` uitsluitend met de afzonderlijke `NNSPOTTY_TEST_*`-testdatabase uit (en dezelfde `OPENSSL_CONF` als die nodig is).

## Overzichtsindex, drill-down en woordzoeken accepteren

Migratie `20261023_0024_spot_browse_index` bouwt een leestabel voor alle spots. Bij ruim 4 miljoen spots duurt dat tientallen minuten en kost het extra schijfruimte. Pauzeer cron-retrieves, controleer de vrije ruimte en voer de migratie uit in `tmux`/`screen`:

```bash
cd /home/hopseflop/domains/nnspotty.hopseflop.nl/public_html
df -h /var/lib/mysql
mysql -e "SELECT table_name, ROUND((data_length+index_length)/1024/1024) AS mb FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('spotnet_spots','spotnet_spot_categories')" <database>
sudo -u hopseflop git pull --ff-only origin main
tmux new -s nnspotty-migrate
sudo -u hopseflop env OPENSSL_CONF=/home/hopseflop/.config/nnspotty/openssl.cnf /opt/remi/php84/root/usr/bin/php -d memory_limit=512M bin/console db migrate
# Na afloop (Ctrl+B D om los te koppelen, `tmux attach -t nnspotty-migrate` om terug te keren):
sudo -u hopseflop env OPENSSL_CONF=/home/hopseflop/.config/nnspotty/openssl.cnf /opt/remi/php84/root/usr/bin/php bin/console db status
systemctl reload nnspotty-php84-fpm.service
```

Wordt de opbouw onderbroken, dan hervat `bin/console db reindex-spots` veilig. Controles: `SELECT COUNT(*) FROM spot_browse` gelijk aan `spotnet_spots`; `SHOW INDEX FROM spot_browse WHERE Key_name = 'ft_browse_title'` bestaat. Open daarna met Ctrl+F5: **Beeld**, **Films** (Series verdwijnt uit de sidebar), een filtercombinatie met nauwelijks resultaten, zoeken op `The` (geen `Thesaurus`-treffers) en op `The Juggler`. Meet in DevTools of met `APP_TIMING=true` de documentrequest en vervolgbatches. Controleer het MariaDB slow log op `spot_browse`-queries.

```bash
tail -n 50 /home/hopseflop/domains/nnspotty.hopseflop.nl/logs/php_log
```

## Cleanup
```bash
php bin/console db cleanup --days=90
```
