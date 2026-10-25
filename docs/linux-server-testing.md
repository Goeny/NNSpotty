# Linux server testen (generiek)

Deze handleiding bevat alleen generieke productiechecks.
Bewaar server-specifieke hostnames/paden in [docs/private/README.md](./private/README.md) en lokale private bestanden.

## Snelle deployment-check

```bash
cd /var/www/nnspotty
git pull --ff-only
composer install --no-dev --optimize-autoloader
php bin/console db migrate
php bin/console db status
php bin/console db health
```

## Retrieve-check

```bash
php bin/console retrieve --days=7 --verbose
```

Controleer daarna in de browser:

- `/health`
- `/login`
- `/spots`
- `/admin`

## Geautomatiseerde update-check

```bash
php bin/console system update --check
```

## Geautomatiseerde update-run

```bash
php bin/console system update
```

Deze run doet fetch/pull, migraties en basischecks.

## Veiligheidsnotities

- Commit nooit `.env` of secrets.
- Gebruik altijd least-privilege database- en API-accounts.
- Sla interne hostnames, users en paden niet op in publieke docs.

## Poster- en fanartgalerijen

Na een update: harde browserverversing en een gematchte film, serie en aflevering openen. Geen nieuwe migratie of cacheflush nodig voor de galerijen; oude matches worden bij detail vernieuwd. Gebruik voor de tests dezelfde PHP/OpenSSL-configuratie als voor de bestaande installatie.

```bash
php tests/run.php
```

- Cover aanklikken: huidige cover eerst, daarna overige posters. Bij een aflevering staat de huidige still eerst.
- Media-icoon naast de trailer: aparte galerij met beschikbare fanart; ook zichtbaar als er fanart maar geen trailer is.
- Test vorige/volgende, teller, pijltjestoetsen, swipen op mobiel, Escape en focusherstel. Werkt na asynchrone metadata-vervanging.
- Extra afbeeldingen laden pas wanneer ze worden bekeken. Bij een laadfout blijven navigatie en opnieuw proberen bruikbaar. Geen artwork: geen lege mediaknop.
- Afbeeldingen uit: geen mediaknop, galerij-URLs of externe afbeeldingsrequests. De preview krijgt geen galerijlijsten.
- Heropenen gebruikt databasecache. Beide gekoppelde providers kunnen afbeeldingen leveren met bronvermelding; een fout bij extra artwork laat de gevonden metadata intact met een waarschuwing.

Databasechecks voor verzamelen, samenvoegen, dedupliceren, URL-validatie en artworkfouten staan in `tests/metadata-gallery-integration.php`. Draai `php tests/run.php --integration` alleen op een aparte testdatabase met de bestaande `NNSPOTTY_TEST_*`-instellingen.
