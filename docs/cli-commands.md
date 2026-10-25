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
- Bij een fout na codewisseling blijft onderhoudsmodus actief. Bekijk `var/update.log`, verhelp de fout en voer hetzelfde commando opnieuw uit. Verwijder de onderhoudsmarker niet om een half bijgewerkte installatie online te zetten.
- Twee updates kunnen niet tegelijk draaien. Bij timeout of een verbroken verbinding eerst controleren of het proces nog actief is; niet blind opnieuw starten.
- Een actieve retrieve blokkeert de update; nieuwe retrieve-/CLI-wijzigingen worden tijdens onderhoud geweigerd. Plan updates buiten cron-/onderhoudsruns.
- Herstel blijft gepind op de oorspronkelijke gepubliceerde releasetag/commit, ook als inmiddels een nieuwere release beschikbaar is; verplaatste tags worden geweigerd.
- Een ontwikkelclone met eigen/nieuwere commits wordt niet gereset naar een oudere release. Er is geen force-optie.
- De release-API moet publiek leesbaar zijn; Git gebruikt zijn bestaande SSH-/credential-helperconfiguratie. API-authenticatie voor private releases wordt niet ondersteund. Geen tokens in origin-URL's.
- De GUI gebruikt exact dit CLI-commando via de PHP CLI-binary naast de web-PHP-installatie; bij afwijkende PHP-paden of webservertimeouts gebruik je de CLI.

### Webinterface

Gebruikers met het groepsrecht **Systeem bijwerken (servercode uitvoeren)** zien onder de versie in de sidebar een releasecontrole. De melding wordt asynchroon geladen; succesvolle controles worden zes uur, fouten vijf minuten gecachet. `/system/update` toont de release, een handmatige controle en een installatieknop met expliciete back-upbevestiging. Alle POST-acties vereisen CSRF. Het recht is standaard alleen via de adminrol beschikbaar en is zeer krachtig: alleen aan vertrouwde beheerders geven.
