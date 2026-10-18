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

## Git en lokale bestanden
- `composer.json` en `composer.lock` blijven in Git voor reproduceerbare installaties. `vendor/` en de gegenereerde autoload worden voortaan lokaal met `composer install --no-dev --optimize-autoloader` opgebouwd.
- `.env`, lokale environmentvarianten, Composer-authenticatie, runtimebestanden onder `var/`, logs, databasedumps en testcoverage worden genegeerd. `.env.example`, `var/.gitkeep`, gedeelde VS Code-configuratie, broncode, tests en documentatie blijven gevolgd.
- **Eenmalige upgrade:** omdat `.env` en `vendor/` eerder gevolgd werden, kan Git ze bij deze update verwijderen. Maak eerst een veilige back-up van de serverconfiguratie, zet lokale wijzigingen gericht apart en herstel de configuratie na pull. Zie de [veilige serverupdate](./docs/linux-server-testing.md#git-opruimen-inhoudsinstellingen-en-afbeeldingsvergroting).
- Negeren verwijdert niets uit de bestaande Git-historie. Roteer echte wachtwoorden/tokens die ooit in de gevolgde configuratie stonden.

## Console
- `php bin/console db status|migrate|health|cleanup --days=90`
- `php bin/console user list|create|delete|edit|password ...`
- `php bin/console retrieve --all|--since-last|--days=30`
- `php bin/console retrieve --days=30 --verbose` (diagnostische detailoutput)
- `php bin/console retrieve --comments-only --days=7 --verbose` (alleen reacties, zonder eerst spots te scannen)
- `php bin/console retrieve --refresh-spots --days=7 --verbose` (bestaande spotmetadata opnieuw ophalen en aanvullen)

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
- De Spotnet-reactie-ID bepaalt de koppeling, ook als een provider een verkeerd `References`-veld levert. Alleen IDs zonder gecodeerde verwijzing gebruiken `References`. Migratie `20261018_0010_spot_details_comments` herstelt reeds opgeslagen koppelingen zonder opnieuw downloaden.
- Detail en preview tonen ook de hoofdgroep en **Gepost met** uit `X-Newsreader` (of XML `Extra/Newsreader`). Voor eerder opgeslagen spots kun je dit aanvullen met `--refresh-spots --days=7`; gewone runs slaan duplicaten nog steeds over. Expliciete refresh vernieuwt XML, metadata en categorie-index atomair, behoudt reacties en wist alleen een covercache waarvan de bron verandert. Het voegt geen verificatie, ratings, spamrapporten of NZB-downloadfunctie toe.
- Usenet-afbeeldingen worden op aanvraag opgehaald en in de database gecachet. Oudere externe afbeelding-URLs worden rechtstreeks door de browser geladen, niet door de server.
- `--since-last` verwerkt maximaal het ingestelde aantal artikelen per groep, vanaf de oudste nog niet verwerkte positie. Herhaal dit commando om een achterstand weg te werken.
- `--days=30` scant vanaf de hoogste artikelnummers terug, zodat doorgaans recente spots als eerste worden opgeslagen. De scan controleert nog steeds de volledige beschikbare historie: datums hoeven niet op artikelnummer gesorteerd te zijn. `--all` haalt ook oudere inhoud op, ongeacht retentie. Deze volledige scans kunnen lang duren.
- Een normale run verwerkt eerst de spotgroep en daarna de reacties. `--comments-only` slaat de spotgroep volledig over en laat de spotcursor ongemoeid, maar blijft de admininstelling voor reacties respecteren. `--refresh-spots` verwerkt alleen spots en vereist `--days` of `--all`. De twee opties zijn niet combineerbaar; ook gerichte datumsessies blijven volledige headerscans van de geselecteerde groep.
- `--verbose` toont per batch het datumbereik en aantallen `te_oud`, `bestaand`, `geen_spot`, `ongeldig`, `verdwenen` en `opgeslagen`. Ontbrekende optionele overview-velden zijn toegestaan; onbruikbare regels worden afzonderlijk gemeld en blokkeren de overige artikelen niet.
- Onder **Op te halen inhoud** kan de admin Usenet-reacties indexeren, reacties weergeven/lokaal plaatsen en afbeeldingen ophalen/tonen afzonderlijk instellen. Alles staat standaard aan, zodat bestaande werking behouden blijft. Uitgeschakelde reactiegroepen worden niet benaderd en hun cursor blijft staan; bestaande inhoud wordt niet verwijderd.
- Reacties weergeven kan aanblijven terwijl ophalen uit staat: de detailpagina toont dan eerder opgeslagen/lokale reacties met uitleg. Afbeeldingen worden alleen op aanvraag geladen en gecachet; uit blokkeert ook gecachte afbeeldingen en verwijdert alle afbeeldingsverwijzingen uit overzicht/preview/detail. Retentie en de incrementele artikellimiet blijven onder Usenet instellingen.

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
- De rechter preview is aanvankelijk gesloten. Klik op een spotregel (of activeer de titellink met het toetsenbord): het paneel schuift rechts in. Sluiten of Escape schuift het weer uit en geeft de focus terug aan de spot. Het paneel scrollt zelfstandig, ook op mobiel; header en zoeken blijven vast.
- Resultaten laden automatisch bij wanneer je de onderkant nadert, zonder paginanummers. De knop **Meer resultaten laden** blijft beschikbaar, ook om een mislukte aanvraag opnieuw te proberen. **Resultaten per laadbatch** in voorkeuren bepaalt de batchgrootte (10-200). Zonder JavaScript openen titels de volledige spot en is er een vervolglink.
- Subcategorieen zijn stapelbare **EN-filters**, ook binnen dezelfde dimensie: een spot moet alle geselecteerde codes bevatten. Nogmaals klikken verwijdert die filter; actieve chips zijn afzonderlijk verwijderbaar. Zoektekst en filters blijven behouden bij bijladen; wisselen van hoofdcategorie begint met een lege subcategoriekeuze.
- Onder **Kolommen per hoofdcategorie** stelt de admin globale standaarden in. Gebruikers kunnen per hoofdgroep een eigen selectie opslaan of terugzetten naar de actuele globale standaard. Titel blijft verplicht. De gemengde weergave **Alle** heeft een vaste algemene kolomset. Lange waarden breken af; op kleine schermen worden regels compacte kaarten, zonder horizontale tabelscroll.
- De detailcover heeft een vast frame van 230 x 345 CSS-pixels op desktop (105 x 158 mobiel). Grote afbeeldingen worden proportioneel verkleind; kleine afbeeldingen blijven scherp op natuurlijke grootte, met een vervaagde vulling erachter. De hero gebruikt een uitgerekte kopie van dezelfde cover, met minder blur (6px) en een contrastoverlay, als tijdelijke achtergrond tot fanart-integratie.
- Klik op de cover in detail of preview voor een groter, proportioneel geschaald beeld in een toegankelijk lightboxvenster. Sluiten, Escape of klikken buiten het venster sluit het; de focus keert terug. Zonder JavaScript opent de originele afbeelding in een nieuw tabblad.
- Omschrijvingen gebruiken in preview en detail dezelfde veilige HTML-/Spotnet-renderer: `[br]`, `[b]`, `[i]`, `[u]`, `[s]`, `[quote]`, `[code]`, `[url]`, `[list]`/`[*]` en `[li]` worden ondersteund naast HTML. Kleur-, font-, grootte- en uitlijningstags behouden de tekst maar voeren geen remote stijl uit. Codeblokken blijven letterlijk. Scripts, embeds, afbeeldingen in omschrijvingen, remote CSS en eventhandlers worden niet uitgevoerd. Onbekende bracketcodes blijven tekst. Hiervoor is PHP-extensie `dom` vereist.
- Geimporteerde Usenet-reacties gebruiken dezelfde veilige opmaak. Zelf geplaatste lokale reacties blijven geescapete tekst, met uitsluitend ondersteuning voor de onderstaande emoticoncodes.
- Spotweb-emoticons zoals `[img=buigen]`, `[img=klappen]` en `[img=respekt]` krijgen moderne statische emoji's; respect, exactly en censored krijgen compacte badges met tekst. `[img=respect]` is een alias van `[img=respekt]`, `[img=wink]` van `[img=knipoog]`. Alle 30 codes uit de Spotweb-reactieknoppen worden ondersteund, hoofdletterongevoelig. Geen GIF's, externe afbeeldingen, nieuwe dependencies of animaties. De precieze emojiweergave volgt het besturingssysteem.
- De emoticons werken in bestaande Usenet-reacties, lokale reacties en omschrijvingen/preview zonder migratie of opnieuw ophalen. Codeblokken in Usenet-opmaak blijven letterlijk. Onbekende namen, `[img=URL]`, `[img]URL[/img]` en HTML-afbeeldingen worden niet als afbeeldingen geladen. De adminschakelaar voor spotafbeeldingen verandert deze tekstsymbolen niet.

## Tests en beperkingen
- `composer test` of `php tests/run.php`: protocol- en renderercontroles zonder database of echte Usenet-provider.
- Voor integratietests met een aparte MariaDB-testserver: zie [Linux server testen](./docs/linux-server-testing.md).
- Nog niet aanwezig: spotterhandtekeningverificatie, volledige ondersteuning voor alle exotische Spotnet-tags, NZB-download/forwarding, metadata en Newznab. Geimporteerde inhoud blijft onbevestigd; omschrijvingen en Usenet-reacties gebruiken een strikte opmaak-allowlist.
- Een succesvolle lokale fixturetest bewijst protocolverwerking, niet beschikbaarheid van groepen of artikelen bij jouw Usenet-provider.
