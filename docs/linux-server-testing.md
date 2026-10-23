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
