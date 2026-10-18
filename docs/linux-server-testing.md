# Linux server testcommando's (nnspotty.hopseflop.nl)

Pas `<pad-naar-project>` aan naar jouw deploylocatie.

```bash
cd <pad-naar-project>
cp .env.example .env
# vul daarna .env met jouw basis app + DB gegevens
```

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

`--since-last` verwerkt vanaf de oudste nog niet verwerkte positie maximaal het ingestelde aantal artikelen per groep. Herhaal dit om achterstand in te lopen. `--days` en `--all` scannen alle beschikbare headers en kunnen lang duren; `--all` negeert retentie.

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

De testgebruiker moet databases met de naam `nnspotty_test_*` kunnen aanmaken/verwijderen. `proc_open` en lokale loopback-sockets moeten beschikbaar zijn voor de synthetische NNTP- en HTTP-servers. Op Windows is een niet-leeg testdatabasewachtwoord vereist om te voorkomen dat lege environmentvariabelen wegvallen. De tests controleren echte php-nntp commando's, XML, reactiekoppeling, binaire afbeeldingen, databaseopslag, limieten, cursors, transportfouten en de HTTP-routes voor login, browsing, afbeeldingen, admin, preferences en reacties.

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
