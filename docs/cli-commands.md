# NNSpotty CLI commando's

Referentie voor `php bin/console`.

## Algemene hulp

- `php bin/console --help`
- `php bin/console help`

---

## Database

### Status en migraties

- `php bin/console db status`  
  Toont welke migraties al uitgevoerd zijn.

- `php bin/console db migrate`  
  Voert alle nog openstaande migraties uit.

  Migratie `20261104_0031_search_performance` berekent NZB-beschikbaarheid voor bestaande spots en bouwt zoekindexen. Plan dit bij grote databases in een onderhoudsvenster met cron en webverkeer gepauzeerd; tabel-/indexopbouw kan tijd, extra schijfruimte en DDL-locks vereisen. Een aparte `db reindex-spots` is hiervoor niet nodig.

- `php bin/console db health`  
  Controleert databaseconnectie en server-tijd.

### Onderhoud

- `php bin/console db cleanup`  
  Cleanup met standaardretentie (90 dagen).

- `php bin/console db cleanup --days=30`  
  Cleanup met eigen retentie.

- `php bin/console db reindex-spots`  
  Herbouwt de spot-overzichtsindex.

---

## Zoekperformance testen

Alleen op een afzonderlijke MySQL/MariaDB-testserver:

```bash
NNSPOTTY_TEST_DSN='mysql:host=127.0.0.1;port=3306;charset=utf8mb4' \
NNSPOTTY_TEST_USER=tester NNSPOTTY_TEST_PASSWORD='...' \
NNSPOTTY_PERF_SPOTS=650000 php tests/search-performance.php
```

Maakt een tijdelijk `nnspotty_perf_*`-schema en verwijdert dat na afloop. Controleert migratie/herhaalbaarheid, zoekresultaten, toegangsfilters, queryplannen en lezen tijdens open importtransacties. Vergelijkt de oude JSON-telling met de geindexeerde telling; gemeten zoekopdrachten hebben een testbudget van twee seconden per aanroep op de gekozen testmachine. Dit is geen responstijdgarantie voor productie. Standaard 10.000 spots; maximaal 5 miljoen. Geen productiegegevens nodig.

Voor herhaalde metingen kan `NNSPOTTY_PERF_KEEP=1` het afgedrukte testschema bewaren; `NNSPOTTY_PERF_REUSE=<schemanaam>` hergebruikt het zonder opnieuw te vullen en bewaart het eveneens. Verwijder zo'n testschema zelf na afloop.

---

## Users en groepen

- `php bin/console user list`  
  Toont alle gebruikers.

- `php bin/console user groups`  
  Toont beschikbare groepen/rollen met ID.

- `php bin/console user create <username> <password> [--admin=1]`  
  Maakt gebruiker aan (`--admin=1` optioneel).

- `php bin/console user edit <username> [--admin=1|0] [--active=1|0] [--groups=<id,id>]`  
  Past adminstatus, activestatus en groepslidmaatschappen aan.

- `php bin/console user password <username> <newPassword>`  
  Wijzigt wachtwoord.

- `php bin/console user delete <username>`  
  Verwijdert gebruiker.

---

## Retrieve

- `php bin/console retrieve --since-last`  
  Incrementeel ophalen vanaf de laatst bekende cursor.

- `php bin/console retrieve --days=30`  
  Historische retrieve voor afgelopen dagen.

- `php bin/console retrieve --all`  
  Volledige retrieve (alias van `--full`).

- `php bin/console retrieve --full`  
  Volledige retrieve.

- `php bin/console retrieve --comments-only`  
  Alleen comments ophalen.

- `php bin/console retrieve --refresh-spots`  
  Bestaande spots vernieuwen.

- `php bin/console retrieve --verbose`  
  Uitgebreide runtime logging.

### Combinatievoorbeeld

```bash
php bin/console retrieve --days=7 --comments-only --verbose
```

---

## Spotterlijsten

- `php bin/console spotters status`  
  Toont huidige moderatie-instellingen en aantallen.

- `php bin/console spotters sync`  
  Synchroniseert whitelist/blacklist.

- `php bin/console spotters enable`  
  Schakelt sleutelmoderatie in.

- `php bin/console spotters disable`  
  Schakelt sleutelmoderatie uit.

---

## Metadata-cron

- `php bin/console metadata status`  
  Toont metadataqueue-instellingen en queue-aantallen.

- `php bin/console metadata enable --batch=25 --retries=5`  
  Schakelt automatische metadataqueue in en zet het startpunt op **nu** (alleen toekomstige spots).

- `php bin/console metadata disable`  
  Schakelt automatische metadataqueue uit.

- `php bin/console metadata run --limit=25`  
  Verwerkt een queuebatch van nieuw naar oud (bedoeld voor cron). Een installatiebrede lock voorkomt overlappende metadata-runs.

Nieuwe spots worden vanaf het ingestelde startmoment ingepland; oudere spots alleen met expliciete backfill of een admin-bulkactie voor eerdere negatieve resultaten. Onder Admin > Metadata > Providers staan herbeoordeling, regels, bulkruns en de live runstatus. Bulkruns vullen de queue met maximaal 500 kandidaten per cron-run; de normale batchlimiet blijft gelden.

Errors wachten minimaal vijf minuten tussen pogingen, oplopend tot een uur. Na de ingestelde retrylimiet volgt standaard een dag pauze, waarna een nieuwe reeks begint. Unmatched wordt standaard na zeven dagen opnieuw geprobeerd, ambiguous na dertig dagen. Deze lange pauzes zijn instelbaar in admin. Bij gewijzigde ingebouwde/extra matchregels worden negatieve resultaten eerder opnieuw beoordeeld. Geslaagde matches en handmatige keuzes/blokkeringen worden niet door bulkacties gereset.

Na deze uitbreiding is `php bin/console db migrate` nodig. Bestaande negatieve queue-resultaten worden meegenomen; oude foutresultaten hoeven niet handmatig uit de database verwijderd te worden.

### Los cronvoorbeeld (metadata-only)

```bash
* * * * * php /path/to/NNSpotty/bin/console metadata run --limit=25
```

---

## Globale cron runner

- `php bin/console cron run`  
  Draait de globale cron-runner: `retrieve --since-last` en/of metadataqueue op basis van de admininstellingen. Als de runner uit staat, stopt het commando direct zonder werk.

### Aanbevolen cronvoorbeeld (enkele cronjob)

```bash
* * * * * php /path/to/NNSpotty/bin/console cron run
```

---

## System update

- `php bin/console system update --check`  
  Controleert gepubliceerde stabiele releases van de GitHub-/GitLab-`origin` van deze clone. Geen branchcommits, drafts, prereleases of toekomstige releases. Geen databaseverbinding nodig.

- `php bin/console system update`  
  Voert een complete update-run uit:
  1. Controleert release, schone werkmap en Composer.
  2. Haalt uitsluitend de nieuwste gepubliceerde releasetag op en controleert versie en Git-afstamming.
  3. Schakelt onderhoudsmodus in en checkt de exacte releasecommit uit (detached HEAD; geen ontwikkelbranch-pull).
  4. Installeert dependencies uit de lockfile: Composer `--no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --no-plugins`.
  5. Start **nieuwe PHP-processen met de nieuwe code** voor `db migrate`, `db health`, `db status` en versiecontrole.
  6. Schakelt onderhoudsmodus uitsluitend na succes uit.

### Let op

- `system update` stopt als de lokale werkmap niet schoon is.
- Gebruik eerst `--check` als je alleen wilt zien of er updates klaarstaan.
- Maak vooraf een database- en bestandenback-up. Een mislukte migratie wordt niet automatisch teruggedraaid.
- Git, Composer, `proc_open`, netwerktoegang en schrijfrechten op de installatie/`var` zijn vereist. Gebruik PHP 8.2+ met de benodigde extensies.
- Composer draait altijd met dezelfde PHP als de updater (lokale `composer.phar` of Composer uit PATH), niet met de systeemstandaard-PHP. Start de CLI-update dus met de juiste versie, bijvoorbeeld `php8.3 bin/console system update`. Is die PHP te oud voor de release, dan stopt de update vooraf zonder iets te wijzigen.
- Bij een fout na codewisseling blijft onderhoudsmodus actief. Bekijk `var/update.log`, verhelp de fout en voer hetzelfde commando opnieuw uit. Verwijder de onderhoudsmarker niet om een half bijgewerkte installatie online te zetten.
- Twee updates kunnen niet tegelijk draaien. Bij timeout of een verbroken verbinding eerst controleren of het proces nog actief is; niet blind opnieuw starten.
- Een actieve retrieve blokkeert de update; nieuwe retrieve-/CLI-wijzigingen worden tijdens onderhoud geweigerd. Plan updates buiten cron-/onderhoudsruns.
- Herstel blijft gepind op de oorspronkelijke gepubliceerde releasetag/commit, ook als inmiddels een nieuwere release beschikbaar is; verplaatste tags worden geweigerd.
- Een ontwikkelclone met eigen/nieuwere commits wordt niet gereset naar een oudere release. Er is geen force-optie.
- De release-API moet publiek leesbaar zijn; Git gebruikt zijn bestaande SSH-/credential-helperconfiguratie. API-authenticatie voor private releases wordt niet ondersteund. Geen tokens in origin-URL's.
- GitLab-projecten worden op volledig namespacepad gematcht en releases worden via het numerieke project-ID opgehaald; geencodeerde slashes in API-paden zijn niet nodig.
- De GUI gebruikt exact dit CLI-commando via de PHP CLI-binary naast de web-PHP-installatie (versiespecifiek, zoals `php8.3`, heeft voorrang op `php`); bij afwijkende PHP-paden of webservertimeouts gebruik je de CLI.

### Webinterface

Gebruikers met het groepsrecht **Systeem bijwerken (servercode uitvoeren)** zien onder de versie in de sidebar een releasecontrole. De melding wordt asynchroon geladen; succesvolle controles worden zes uur, fouten vijf minuten gecachet. `/system/update` toont de release, een handmatige controle en een installatieknop met expliciete back-upbevestiging. Alle POST-acties vereisen CSRF. Het recht is standaard alleen via de adminrol beschikbaar en is zeer krachtig: alleen aan vertrouwde beheerders geven.
