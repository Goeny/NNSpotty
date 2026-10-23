# Installatie (webserver)

Deze handleiding is voor een normale serverinstallatie (Apache/Nginx + PHP + MySQL/MariaDB).

## Vereisten

- PHP 8.2 of hoger
- Composer 2.x
- MySQL of MariaDB
- PHP-extensies: `curl`, `dom`, `iconv`, `mbstring`, `openssl`, `pdo_mysql`, `simplexml`, `zlib`

## Snelle productie-installatie

1. Clone de repository op je server.
2. Installeer dependencies:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Maak `.env` op basis van `.env.example` en vul minimaal je app- en databasegegevens in.
4. Voer migraties uit:
   ```bash
   php bin/console db migrate
   ```
   Dit maakt **alle noodzakelijke tabellen/kolommen** aan voor de huidige versie.
   Je hoeft geen losse migraties te draaien.
5. Zet je webserver document root op de map `public/`.
6. Maak een eerste admin-gebruiker:
   ```bash
   php bin/console user create admin <sterk-wachtwoord> --admin=1
   ```
7. Log in op `/login`, open `/admin` en configureer Usenet/retrieve-instellingen.
8. Start eerste retrieve:
   ```bash
   php bin/console retrieve --days=30 --verbose
   ```

## Belangrijk over migraties

- **Nieuwe installatie:** `php bin/console db migrate` is voldoende.
- **Update naar nieuwere versie:** opnieuw `php bin/console db migrate` uitvoeren.
- Migraties zijn versioned; alleen nog niet uitgevoerde wijzigingen worden toegepast.

## Webserver-configuratie

### Apache

- Gebruik `public/` als document root.
- Zorg dat `mod_rewrite` en `.htaccess` worden toegestaan (`AllowOverride All`).

### Nginx

- Gebruik `public/` als root.
- Laat alle niet-bestaande paden doorsturen naar `public/index.php`.

## Na installatie

- Health-check: `/health`
- Login: `/login`
- Spots-overzicht: `/spots`
- Admin: `/admin`

## Updateprocedure (bestaande installatie)

```bash
git pull
composer install --no-dev --optimize-autoloader
php bin/console db migrate
```

Daarna eventueel PHP-FPM/opcache herladen volgens je serverconfiguratie.

## Lokale ontwikkelmodus (optioneel)

Lokaal draaien kan voor development/test:

```bash
php -S localhost:8080 -t public
```

Voor productie blijft een echte webserver-installatie de aanbevolen route.
