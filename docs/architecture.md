# Architectuur (v0.0.1)

## Principes
- Nieuwe, modulaire implementatie; geen overname van Spotweb-code.
- `rvdv/nntp` verzorgt NNTP-transport. Spotnet-protocollogica staat apart van transport, opslag en weergave.
- MySQL/MariaDB is de primaire datastore.
- `.env` bevat app-/databaseconfiguratie; operationele Usenet-instellingen worden in admin beheerd.

## Spotnet is geen algemene headerindex
Een binary-release groeperen op onderwerp levert geen Spotnet-spot op. Een spot is een afzonderlijk bericht met een eigen message-ID en XML-metadata.

De huidige pipeline:
1. `RetrieveService` selecteert expliciete groepen: standaard `free.pt` voor spots en `free.usenet` voor reacties. Er is geen algemene groepsdiscovery.
2. `SpotnetOverviewCommand` leest XOVER, normaliseert de veldnamen van php-nntp en gebruikt het daadwerkelijke artikelnummer, niet de array-index. Ontbrekende optionele staartvelden (zoals References, bytes, lines of Xref) zijn toegestaan. Regels zonder bruikbaar artikelnummer, datum of message-ID krijgen een expliciete fout met range, regelnummer en veldaantallen en worden afzonderlijk overgeslagen. Verlopen lege ranges zijn toegestaan; transport-/commandofouten falen expliciet.
3. Per spot wordt `HEAD <message-id>` opgehaald. `SpotnetParser` voegt herhaalde `X-XML`-headers samen en leest `Spotnet/Posting`.
4. Titel, omschrijving, poster, categorie/subcategorieen, grootte, tag, website en afbeelding-/NZB-segmenten worden opgeslagen.
5. Reacties worden via `ARTICLE` opgehaald. Koppeling gebeurt via `References`, of de Spotnet comment-ID-structuur als die header ontbreekt. Reacties mogen eerder binnenkomen dan de bijbehorende spot.
6. `SpotImageService` haalt afbeeldingsegmenten via `BODY` op, verwijdert transportregelafbrekingen en decodeert de Spotnet-escapes `=A` tot en met `=D`. Alleen herkende rasterafbeeldingen worden gecachet. Afbeeldingen worden niet geinflate; NZB-segmenten gebruiken wel compressie, maar downloaden daarvan is nog niet geimplementeerd.

## Opslag
- `spotnet_spots`: een rij per message-ID, met volledige XML en uitgelezen metadata.
- `spotnet_comments`: geimporteerde reacties, gekoppeld aan de hash van de originele spot message-ID.
- `spotnet_images`: afbeeldingcache als database-BLOB.
- `spotnet_spot_categories`: genormaliseerde categoriecodes met hoofdgroep (`0:a9`), geindexeerd voor SQL-filters.
- `post_comments`: lokale reacties; op de spotdetailpagina samen met Usenet-reacties getoond, met bronvermelding.
- `retrieval_cursors`: aparte `spotnet:<rol>:<groep>` posities, gescheiden van oude generieke cursors.
- `retrieval_runs`: operationele status en expliciete foutmelding bij mislukte runs.
- Historische `usenet_posts` en `spot_releases` blijven behouden, maar worden niet meer gebruikt voor retrieval of browsing.

Migratie `20261018_0006_spotnet` voegt de nieuwe tabellen toe en corrigeert uitsluitend de oude exacte standaardinstelling `spots:free.pt,comments:free.ptd`.

### Categorisatie en inhoudsbeleid
`SpotCategories` beschrijft de vier Spotnet-hoofdgroepen en dimensies a/b/c/d/z. Codes met hoofdgroepprefix, hoofdletters en nul-padding worden genormaliseerd zonder de numerieke subcategorie te verschuiven. Typecodes onderscheiden films, series, boeken, erotica, afbeeldingen en de muziektypen. Oudere spots zonder type krijgen een type uit formaat/bron/genre; oude adult-genres en boektaalcodes worden gemapt. Adult-genres overrulen een onjuiste expliciete niet-adult typecode.

Bij nieuwe spots worden `browse_type`, `is_erotica` en facetcodes atomair opgeslagen. Duplicaten veranderen de bestaande metadata/classificatie niet. Migratie `20261018_0007_categories_visibility` voegt schema en persoonlijke opt-in toe; `20261018_0008_category_backfill` classificeert bestaande JSON-subcategorieen in transactiebatches van 250 spots. Deze tweede stap kan veilig opnieuw worden uitgevoerd na een onderbreking. Nog niet geclassificeerde rijen zijn standaard afgeschermd. Pauzeer retrieval en webverkeer tijdens de upgrade.

De globale boolean staat in `app_settings` onder `admin.content_settings`; de persoonlijke boolean staat in `user_preferences.show_erotica`. Beide zijn standaard false. `AppStateRepository::canViewErotica` vereist beide, ook voor admins. HTTP-routes geven dit beleid expliciet aan de repository door, zowel voor zichtbare data als alle tellingen. Directe toegang tot een verborgen spot geeft 404. Ook het admin-commentoverzicht en het oude admin-reactieformulier respecteren dit beleid. Bij globale blokkade blijft een bestaande persoonlijke opt-in bewaard, maar inactief.

Overzicht, detail, admin en voorkeuren gebruiken `SpotLayout`; `SpotDetailPageRenderer::summary` wordt gedeeld met het previewpaneel. De locale stylesheet ondersteunt donker/licht en mobiele layouts. De root-Apache rewrite publiceert dezelfde assets als een `public/` documentroot.

## Retrievalsemantiek
- `--since-last`: vanaf de laatst voltooide batch, met de ingestelde artikellimiet per groep. Bij de eerste run begint dit bij het oudste beschikbare artikel. Cursors worden pas na een succesvolle batch opgeslagen.
- `--days=N`: vanaf de hoogste artikelnummers terug scannen en alleen inhoud binnen het tijdvenster ophalen. Dit levert doorgaans eerder zichtbare recente spots op. Een artikelnummer is geen betrouwbare datum: alle ranges blijven gescand worden, ook na oude of lege batches. De artikellimiet mag geen recente spots verbergen. De incrementele cursor wordt pas na een volledig geslaagde groepsscan naar het groepseinde verplaatst; een afgebroken omgekeerde scan mag oudere ongelezen ranges niet overslaan.
- `--all`: volledige beschikbare historie, zonder retentiefilter.
- Opnieuw uitvoeren is idempotent. Gelijke titels met verschillende message-IDs blijven verschillende spots.
- Corrupte XML en verdwenen artikelen worden geteld en gemeld. Transport- of databasefouten breken de run af; er wordt geen lege succesvolle fallback gemaakt.
- Verbose batchoutput toont het geldige UTC-datumbereik en afzonderlijke aantallen te oud, bestaand, geen spot, ongeldig, verdwenen en opgeslagen. Het totale aantal opgeslagen berichten blijft zichtbaar. Een corrupt optioneel volledig veld geeft een waarschuwing, geen afwijzing van verder bruikbare spotmetadata.

## Web en beveiligingsgrenzen
- `/spots`: individuele spots, type-/subcategoriefilters, titelzoekfunctie, paginering, thumbnails en geselecteerde preview.
- `/spots/view?key=...`: afbeelding, metadata, omschrijving, reacties en een CSRF-beveiligd formulier voor lokale reacties.
- `/spots/image?key=...`: alleen voor ingelogde gebruikers met zichtbaarheidstoegang; rasterafbeeldingen met `nosniff` en databasecache. Lijst, detail en afbeeldingen gebruiken `private, no-store`, zodat ingetrokken zichtbaarheid niet via een normale browsercache wordt omzeild.
- Usenet XML wordt zonder DTD/entiteiten verwerkt. Segment-IDs worden gevalideerd voor NNTP-gebruik.
- Omschrijvingen/reacties worden als tekst geescapet, niet als onbetrouwbare HTML uitgevoerd.
- Spotterhandtekeningen worden nog niet geverifieerd. De UI vermeldt daarom expliciet dat identiteit/inhoud onbevestigd is. Dit is geen complete Spotweb trust-/moderatie-implementatie.
- Oudere externe afbeelding-URLs worden door de browser geladen en kunnen ontbreken; de server gebruikt ze niet als fetch-URL.

## Bronnen voor protocolonderzoek
Onderzocht op 2026-10-18 in [spotweb/spotweb](https://github.com/spotweb/spotweb), branch `develop`, commit `b669d9386da691849ece3cbc7293c8f057bf7b7b`:
- [Standaardgroepen](https://github.com/spotweb/spotweb/blob/b669d9386da691849ece3cbc7293c8f057bf7b7b/lib/services/Upgrade/Services_Upgrade_Settings.php): spots `free.pt`, reacties `free.usenet`, binaries `alt.binaries.ftd`, rapportages `free.willey`.
- [Volledige spots en binaries ophalen](https://github.com/spotweb/spotweb/blob/b669d9386da691849ece3cbc7293c8f057bf7b7b/lib/services/Nntp/Services_Nntp_SpotReader.php).
- [Spot XML-structuur](https://github.com/spotweb/spotweb/blob/b669d9386da691849ece3cbc7293c8f057bf7b7b/lib/services/Format/Services_Format_Parsing.php).
- [Binaire escaping](https://github.com/spotweb/spotweb/blob/b669d9386da691849ece3cbc7293c8f057bf7b7b/lib/services/Format/Services_Format_Util.php).
- [Reactiekoppeling](https://github.com/spotweb/spotweb/blob/b669d9386da691849ece3cbc7293c8f057bf7b7b/lib/services/Retriever/Services_Retriever_Comments.php).
- [Spotnet-categoriecodes en legacy type-afleiding](https://github.com/spotweb/spotweb/blob/b669d9386da691849ece3cbc7293c8f057bf7b7b/lib/SpotCategories.php). De categorie-indeling dient als protocolreferentie; labels en classificatie zijn zelfstandig geimplementeerd, zonder de legacy categorie-engine over te nemen.

Deze bronnen dienen als protocolreferentie. NNSpotty heeft een eigen parser, services, datamodel en synthetische testfixtures.

## Volgende uitbreidingen
- Handtekeningverificatie en toepassen van moderatie op geverifieerde identiteiten.
- Veilige Spotnet-opmaak en expliciete compatibiliteit voor afwijkende legacy-XML.
- NZB-download/forwarding, Newznab en metadata.
- Indexing-jobs, scheduling en performance-optimalisatie van grote backfills.
