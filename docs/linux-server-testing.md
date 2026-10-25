# Linux server testen (generiek)

Deze handleiding bevat alleen generieke productiechecks.
Bewaar server-specifieke hostnames/paden in [docs/private/README.md](./private/README.md) en lokale private bestanden.

## Snelle deployment-check

```bash
cd /var/www/nnspotty
php bin/console system update --check
php bin/console system update
php bin/console db status
php bin/console db health
```

Alleen uitvoeren na een database-/bestandenback-up. Voor eenmalig ophalen van de nieuwe updater op een ontwikkelbranch: `git pull --ff-only`, Composer en `db migrate`; daarna volgen normale updates gepubliceerde releases.

## Historie en statistieken controleren

Na `php bin/console db migrate`:

- Open een volledige detailpagina tweemaal: `/history` toont onder Bekeken een regel met aantal twee; een preview telt niet.
- Download een NZB handmatig, via een persoonlijke Newznab-key en via SABnzbd. Gedownload bundelt per spot met de juiste bronnen; mislukte/onbekende SABnzbd-verzendingen en herhaalde bevestigingen tellen niet extra.
- Controleer met twee accounts dat ieder uitsluitend zijn eigen historie ziet, ook met een toegevoegde `username`-queryparameter.
- Open `/statistics` als admin en wissel tussen 7/30/90 dagen. Controleer categorieen, downloadbronnen, gebruikersaantallen en de uitklapbare exacte dagwaarden.
- Een gewone gebruiker ziet geen statistiekenlink en krijgt 403 op de directe URL. Ken via groepsbeheer **Statistieken bekijken** toe en trek het weer in; beide wijzigingen moeten meteen werken.
- Controleer donker/licht, mobiel, paginering en verborgen erotiek. Verwijderde spots blijven herkenbaar zonder dode detailkoppeling.

De automatische activiteitstests draaien mee met de bestaande database-integratietests; ze gebruiken synthetische accounts/NZB's en een lokale SABnzbd-fixture.

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

Deze run installeert de nieuwste gepubliceerde stabiele release van de clone-origin, Composer-dependencies en migraties met de nieuwe code, gevolgd door basischecks. Geen ontwikkelbranch-pull. Maak vooraf een back-up; zie [updatevoorwaarden en herstel](./cli-commands.md#system-update).

Controleer de sidebar met en zonder het recht **Systeem bijwerken (servercode uitvoeren)**. Alleen bevoegde accounts mogen `/system/update` en de releasecontrole openen. Test een handmatige hercontrole, back-upbevestiging en eventuele foutmelding; voer een echte update uitsluitend op een afzonderlijke testinstallatie met gepubliceerde testrelease uit. De geautomatiseerde regressies gebruiken tijdelijke Git-repositories, een lokale release-API en een echte nieuwe testdatabasemigratie, niet de productie-origin.

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
