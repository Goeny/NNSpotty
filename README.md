# NNSpotty

NNSpotty is een nieuw project dat met **php-nntp** een moderne, onderhoudbare opvolger van Spotweb bouwt.

De focus ligt op:
- eenvoud in installatie en beheer;
- sterke gebruikerservaring;
- hoge performance;
- duidelijke, modulaire codebasis zonder legacy-complexiteit.

## Huidige status
- **Versie:** 0.0.1
- **Codenaam:** Delta Integrale
- **Fase:** Documentatie + eerste projectscaffold

## Documentatie
- [Projectrichtlijnen](./docs/project-guidelines.md)
- [Versioning en codenames](./docs/versioning.md)
- [Second brain](./docs/second-brain.md)
- [Architectuur](./docs/architecture.md)
- [Installatie](./docs/installation.md)
- [Linux server testen](./docs/linux-server-testing.md)
- [Changelog](./CHANGELOG.md)
- [UI-referentie screenshots](./docs/screenshots/)

## Quick start
1. Installeer dependencies:
   ```bash
   composer install
   ```
2. Draai migraties:
   ```bash
   php bin/console db migrate
   ```
3. Start lokaal:
   ```bash
   php -S localhost:8080 -t public
   ```
4. Controle:
   - `/` voor UI
   - `/health` voor health + versie-info
   - `/login` voor authenticatie
   - `/spots` voor gegroepeerde releases browsen (ingelogd)
   - `/admin` voor adminbeheer (alleen admin)
   - `/preferences` voor user preferences (ingelogde gebruiker)

## Console
- `php bin/console db status|migrate|health|cleanup --days=90`
- `php bin/console db reindex-spots` (bestaande headers herindexeren naar gegroepeerde releases)
- `php bin/console user list|create|delete|edit|password ...`
- `php bin/console retrieve --all|--since-last|--days=30`
- `php bin/console retrieve --days=30 --verbose` (diagnostische detailoutput)

## Retrieve configuratie
- Basisgedrag: `retrieve` ontdekt automatisch beschikbare groepen en haalt headers op.
- Usenet/retrieve settings beheer je via het admin panel:
  - server/port/login/encryptie
  - retentie
  - group pattern (optioneel)
  - expliciete groepen (optioneel)
  - max groepen / max artikelen per groep
- Standaard worden Spotnet-groepen voorgesteld (`spots:free.pt,comments:free.ptd`). Laat expliciete groepen leeg als je bewust auto-discovery wilt gebruiken.
- Headers worden opgeslagen in MySQL/MariaDB en direct gegroepeerd naar release-index voor Spotweb-achtige listing.
