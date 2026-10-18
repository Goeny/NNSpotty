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
- **Fase:** Werkend fundament met Spotnet-retrieval en individuele spots (nog geen complete Spotweb-vervanger)

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
   - `/spots` voor individuele Spotnet-spots met afbeeldingen, omschrijving en reacties (ingelogd)
   - `/admin` voor adminbeheer (alleen admin)
   - `/preferences` voor user preferences (ingelogde gebruiker)

## Console
- `php bin/console db status|migrate|health|cleanup --days=90`
- `php bin/console user list|create|delete|edit|password ...`
- `php bin/console retrieve --all|--since-last|--days=30`
- `php bin/console retrieve --days=30 --verbose` (diagnostische detailoutput)

## Retrieve configuratie
- `retrieve` verwerkt Spotnet-berichten, geen algemene Usenet-headerindex.
- Usenet/retrieve settings beheer je via het admin panel:
  - server/port/login/encryptie
  - retentie
  - groepen met de rollen `spots` en `comments`
  - max artikelen per groep voor incrementele runs
- Standaard: `spots:free.pt,comments:free.usenet`; leeg betekent deze defaults, niet auto-discovery.
- Een spot wordt geidentificeerd door zijn message-ID. De `X-XML`-headers leveren titel, omschrijving, categorie, grootte en afbeelding-/NZB-verwijzingen. Gelijke titels worden niet samengevoegd.
- Reacties worden uit `free.usenet` opgehaald en aan de originele spot gekoppeld. Lokale reacties kunnen op de detailpagina worden geplaatst; deze worden niet naar Usenet verstuurd.
- Usenet-afbeeldingen worden op aanvraag opgehaald en in de database gecachet. Oudere externe afbeelding-URLs worden rechtstreeks door de browser geladen, niet door de server.
- `--since-last` verwerkt maximaal het ingestelde aantal artikelen per groep, vanaf de oudste nog niet verwerkte positie. Herhaal dit commando om een achterstand weg te werken.
- `--days=30` scant vanaf de hoogste artikelnummers terug, zodat doorgaans recente spots als eerste worden opgeslagen. De scan controleert nog steeds de volledige beschikbare historie: datums hoeven niet op artikelnummer gesorteerd te zijn. `--all` haalt ook oudere inhoud op, ongeacht retentie. Deze volledige scans kunnen lang duren.
- `--verbose` toont per batch het datumbereik en aantallen `te_oud`, `bestaand`, `geen_spot`, `ongeldig`, `verdwenen` en `opgeslagen`. Ontbrekende optionele overview-velden zijn toegestaan; onbruikbare regels worden afzonderlijk gemeld en blokkeren de overige artikelen niet.

## Upgrade van de headerindex
1. Voer `php bin/console db migrate` uit.
2. Controleer in admin: `spots:free.pt,comments:free.usenet`.
3. Voer `php bin/console retrieve --days=30 --verbose` uit.

Oude headerdata en synthetische releases blijven bewaard, maar worden niet meer als spots getoond. De nieuwe Spotnet-index heeft eigen cursors. `db reindex-spots` is vervallen: generieke headers bevatten geen volledige spotinhoud.

## Categorieen, zichtbaarheid en layout
- De vier Spotnet-hoofdgroepen hebben nu hun volledige formaat-/platform-, bron-, taal-, bitrate- en genre-indeling. Het overzicht onderscheidt onder andere films, series, boeken, afbeeldingen, muziek, livesets, podcasts, luisterboeken, spellen en applicaties.
- De vaste header bevat alleen Alle en de hoofdcategorieen Beeld, Geluid, Spellen en Applicaties, plus zoeken. Na selectie staan alle bijbehorende subcategorieen per dimensie in de linker sidebar, ook zonder spots. Alleen zichtbare spots leveren aantallen; verborgen erotica-subcategorieen worden niet aangeboden. Onbekende codes blijven bewaard en worden in de technische details als code getoond.
- Erotica staat standaard globaal **uit**. De admin kan dit toestaan onder **Inhoud en zichtbaarheid**. Daarna kan iedere gebruiker onder **Voorkeuren** zelf opt-innen; standaard blijft erotica verborgen. Globaal uit blokkeert ook admins, zelfs met een opgeslagen persoonlijke opt-in.
- Deze controle geldt voor lijsten, aantallen, facetten, zoeken, previews, directe detail-/afbeeldingslinks en reacties. Indexing en opgeslagen inhoud worden niet verwijderd. Herkenning gebruikt Spotnet-codes, inclusief oudere adult-genres; dit is geen inhoudsanalyse of vervanging voor moderatie.
- Voer bij deze upgrade `php bin/console db migrate` uit terwijl retrieval en webverkeer zijn gepauzeerd. De bestaande spots worden automatisch in batches opnieuw ingedeeld op basis van hun opgeslagen subcategoriecodes; opnieuw ophalen is niet nodig. Bij een onderbroken backfill kan hetzelfde commando opnieuw worden uitgevoerd.
- De screenshots vormen de basis voor een responsive sidebar, compacte spotlijst met covers, geselecteerde preview en detailhero. `default` en `plex-dark` gebruiken de donkere layout; `plex-light` geeft een lichte variant. Niet-geimplementeerde ratings, downloadknoppen en verificatiestatussen worden niet nagebootst.
- Op brede schermen scrollen spotlijst, linker sidebar en rechter preview onafhankelijk; header en zoekfunctie blijven zichtbaar. Op kleinere schermen staat de volledige preview onder de lijst en blijft de header vast.
- De detailcover heeft een vast frame van 230 x 345 CSS-pixels op desktop (105 x 158 mobiel). Grote afbeeldingen worden proportioneel verkleind; kleine afbeeldingen blijven scherp op natuurlijke grootte, met een vervaagde vulling erachter. De hero gebruikt een uitgerekte, vervaagde kopie van dezelfde cover als tijdelijke achtergrond tot fanart-integratie.
- Omschrijvingen gebruiken in preview en detail dezelfde veilige HTML-renderer: alineas, nadruk, lijsten, tabellen, codeblokken en HTTP(S)-links. Scripts, embeds, afbeeldingen in omschrijvingen, remote CSS en eventhandlers worden verwijderd. Reacties blijven geescapete tekst. Hiervoor is PHP-extensie `dom` vereist.

## Tests en beperkingen
- `composer test` of `php tests/run.php`: protocol- en renderercontroles zonder database of echte Usenet-provider.
- Voor integratietests met een aparte MariaDB-testserver: zie [Linux server testen](./docs/linux-server-testing.md).
- Nog niet aanwezig: spotterhandtekeningverificatie, BBCode-rendering, NZB-download/forwarding, metadata en Newznab. Geimporteerde inhoud blijft onbevestigd; HTML-omschrijvingen worden uitsluitend via een strikte opmaak-allowlist weergegeven.
- Een succesvolle lokale fixturetest bewijst protocolverwerking, niet beschikbaarheid van groepen of artikelen bij jouw Usenet-provider.
