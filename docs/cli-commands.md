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
  Controleert of `origin/<huidige-branch>` nieuwe commits heeft en toont pending commits.

- `php bin/console system update`  
  Voert een complete update-run uit:
  1. `git fetch --prune origin`
  2. `git pull --ff-only origin <huidige-branch>` (alleen als updates beschikbaar zijn)
  3. `db migrate`
  4. database checks (`db health` + migratiestatus)

### Let op

- `system update` stopt als de lokale werkmap niet schoon is.
- Gebruik eerst `--check` als je alleen wilt zien of er updates klaarstaan.
