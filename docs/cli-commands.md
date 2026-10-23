# NNSpotty CLI commando's

Korte referentie voor `bin/console`.

## Database

- `php bin/console db status`  
  Toont migratiestatus.
- `php bin/console db migrate`  
  Voert migraties uit.
- `php bin/console db health`  
  Controleert database-connectie.
- `php bin/console db cleanup --days=90`  
  Ruimt oude data op.
- `php bin/console db reindex-spots`  
  Herbouwt de spot-overzichtsindex.

## Users en groepen

- `php bin/console user list`  
  Toont alle gebruikers.
- `php bin/console user groups`  
  Toont beschikbare groepen/rollen.
- `php bin/console user create <username> <password> [--admin=1]`  
  Maakt gebruiker aan.
- `php bin/console user edit <username> [--admin=1|0] [--active=1|0] [--groups=<id,id>]`  
  Past gebruiker aan.
- `php bin/console user password <username> <newPassword>`  
  Wijzigt wachtwoord.
- `php bin/console user delete <username>`  
  Verwijdert gebruiker.

## Retrieve

- `php bin/console retrieve --since-last`  
  Haalt op vanaf laatste cursor.
- `php bin/console retrieve --days=30`  
  Haalt spots op van afgelopen dagen.
- `php bin/console retrieve --all` of `--full`  
  Volledige retrieve (binnen retentie).
- `php bin/console retrieve --comments-only`  
  Haalt alleen comments op.
- `php bin/console retrieve --refresh-spots`  
  Ververst bestaande spotgegevens.
- `php bin/console retrieve --verbose`  
  Geeft uitgebreide logging.

## Spotterlijsten

- `php bin/console spotters status`  
  Toont huidige spotterinstellingen.
- `php bin/console spotters sync`  
  Synchroniseert whitelist/blacklist.
- `php bin/console spotters enable`  
  Schakelt sleutelmoderatie in.
- `php bin/console spotters disable`  
  Schakelt sleutelmoderatie uit.
