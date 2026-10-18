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
4. Titel, omschrijving, poster, categorie/subcategorieen, grootte, tag, website en afbeelding-/NZB-segmenten worden opgeslagen. `X-Newsreader` (fallback XML `Extra/Newsreader`) wordt als onbevestigde clientnaam apart bewaard en in detail/preview getoond.
5. Reacties worden via `ARTICLE` opgehaald. De Spotnet comment-ID-structuur heeft voorrang: het lokale deel voor de eerste punt plus het oorspronkelijke domein vormt de spot-ID, met behoud van hoofdletters. Providers kunnen `References` verkeerd doorgeven; die header wordt alleen gebruikt bij IDs zonder gecodeerde koppeling. Reacties mogen eerder binnenkomen dan de bijbehorende spot. Een eventueel `Message-ID` in de opgehaalde reactie moet overeenkomen met het aangevraagde ID.
6. `SpotImageService` haalt afbeeldingsegmenten via `BODY` op, verwijdert transportregelafbrekingen en decodeert de Spotnet-escapes `=A` tot en met `=D`. Alleen herkende rasterafbeeldingen worden gecachet. Afbeeldingen worden niet geinflate; NZB-segmenten gebruiken wel compressie, maar downloaden daarvan is nog niet geimplementeerd.

## Opslag
- `spotnet_spots`: een rij per message-ID, met volledige XML en uitgelezen metadata.
- `spotnet_comments`: geimporteerde reacties, gekoppeld aan de hash van de originele spot message-ID.
- `spotnet_images`: afbeeldingcache als database-BLOB.
- `spotnet_spot_categories`: genormaliseerde categoriecodes met hoofdgroep (`0:a9`), geindexeerd voor SQL-filters.
- `user_spot_columns`: optionele kolomoverride per username en protocol-hoofdgroep; foreign key ruimt overrides op bij userverwijdering. Globale standaarden staan als JSON onder `app_settings.admin.spot_columns`.
- `post_comments`: lokale reacties; op de spotdetailpagina samen met Usenet-reacties getoond, met bronvermelding.
- `retrieval_cursors`: aparte `spotnet:<rol>:<groep>` posities, gescheiden van oude generieke cursors.
- `retrieval_runs`: operationele status en expliciete foutmelding bij mislukte runs.
- Historische `usenet_posts` en `spot_releases` blijven behouden, maar worden niet meer gebruikt voor retrieval of browsing.

Migratie `20261018_0006_spotnet` voegt de nieuwe tabellen toe en corrigeert uitsluitend de oude exacte standaardinstelling `spots:free.pt,comments:free.ptd`.

Migratie `20261018_0010_spot_details_comments` voegt `newsreader` toe en herberekent de koppeling van opgeslagen reacties met gecodeerde Spotnet-IDs. Reacties zonder zo'n code blijven ongemoeid. Geen bodydownload of cursorreset nodig voor deze reparatie. Protocolreferenties: [Spotweb-reactieverwerking](https://github.com/spotweb/spotweb/blob/develop/lib/services/Retriever/Services_Retriever_Comments.php) en [headerextractie](https://github.com/spotweb/spotweb/blob/develop/lib/services/Nntp/Services_Nntp_SpotReader.php); de implementatie blijft onafhankelijk.

`retrieve --comments-only` selecteert uitsluitend reactiegroepen voordat een NNTP-groep wordt geopend; uitgeschakelde of ontbrekende reactieretrieval wordt expliciet geweigerd. Normale runs behouden spots-eerst-volgorde. `--refresh-spots` selecteert alleen spots en leest bestaande HEADs opnieuw binnen een expliciete `--days`/`--all` backfill. Alleen die optie staat metadata-upserts toe; XML/classificatie worden samen vernieuwd en een gewijzigde afbeeldingsbron invalideert de cache. Nieuwe en vernieuwde spots worden afzonderlijk geteld. Beide gerichte opties laten cursors van niet-geselecteerde groepen ongemoeid.

### Categorisatie en inhoudsbeleid
`SpotCategories` beschrijft de vier Spotnet-hoofdgroepen en dimensies a/b/c/d/z. Codes met hoofdgroepprefix, hoofdletters en nul-padding worden genormaliseerd zonder de numerieke subcategorie te verschuiven. Typecodes onderscheiden films, series, boeken, erotica, afbeeldingen en de muziektypen. Oudere spots zonder type krijgen een type uit formaat/bron/genre; oude adult-genres en boektaalcodes worden gemapt. Adult-genres overrulen een onjuiste expliciete niet-adult typecode.

Bij nieuwe spots worden `browse_type`, `is_erotica` en facetcodes atomair opgeslagen. Gewone duplicaten veranderen de bestaande metadata/classificatie niet; alleen expliciete `--refresh-spots` vernieuwt die samen. Migratie `20261018_0007_categories_visibility` voegt schema en persoonlijke opt-in toe; `20261018_0008_category_backfill` classificeert bestaande JSON-subcategorieen in transactiebatches van 250 spots. Deze tweede stap kan veilig opnieuw worden uitgevoerd na een onderbreking. Nog niet geclassificeerde rijen zijn standaard afgeschermd. Pauzeer retrieval en webverkeer tijdens de upgrade.

De globale boolean staat in `app_settings` onder `admin.content_settings`; de persoonlijke boolean staat in `user_preferences.show_erotica`. Beide zijn standaard false. `AppStateRepository::canViewErotica` vereist beide, ook voor admins. HTTP-routes geven dit beleid expliciet aan de repository door, zowel voor zichtbare data als alle tellingen. Directe toegang tot een verborgen spot geeft 404. Ook het admin-commentoverzicht en het oude admin-reactieformulier respecteren dit beleid. Bij globale blokkade blijft een bestaande persoonlijke opt-in bewaard, maar inactief.

Overzicht, detail, admin en voorkeuren gebruiken `SpotLayout`; `SpotDetailPageRenderer::summary` wordt gedeeld met het previewpaneel. De locale stylesheet ondersteunt donker/licht en mobiele layouts. De root-Apache rewrite publiceert dezelfde assets als een `public/` documentroot.

### Navigatie, scrollgebieden en presentatie
De vaste header bevat Alle plus uitsluitend de vier protocol-hoofdcategorieen en de zoekfunctie. De linker sidebar toont na selectie alle dimensies en subcategorieen van die hoofdgroep, inclusief lege keuzes; adult-codes zijn alleen beschikbaar bij effectieve opt-in. Bestaande links naar afgeleide typen blijven werken en activeren de juiste hoofdgroep. Subcategorielinks toggelen een genormaliseerde, kommagescheiden selectie (`subcategory=0:a9,0:d0`). Elke code krijgt een eigen SQL-EXISTS: EN geldt zowel tussen als binnen dimensies. Facetaantallen gebruiken de gehele actieve selectie. Beide zoekformulieren bewaren de filters; hoofdgroeplinks resetten de selectie. Array-/ongeldige query-invoer geeft 400.

De lijst gebruikt `posted_at DESC, id DESC` met een gevalideerde base64url-keyset in plaats van OFFSET-paginering. De eerste en volgende batches delen dezelfde query, filters, kolommen en zichtbaarheid; limit+1 bepaalt of er meer resultaten zijn. Nieuwe spots boven de laatst geladen positie verschuiven bestaande batches niet. `GET /spots?batch=1&cursor=...` retourneert private/no-store JSON met `html`, `count` en `next` (URL of null). De browser observeert het lijsteinde met 400px vooruitloop, slechts een aanvraag tegelijk. Fouten stoppen automatisch laden totdat de gebruiker opnieuw probeert. Zonder JavaScript is een gewone cursor-vervolglink beschikbaar.

De preview is initieel verborgen en inert. Een klik op een regel of toetsenbordactivatie van de titel vraagt `GET /spots/preview?key=...` aan. Deze route controleert authenticatie/zichtbaarheid en retourneert private/no-store JSON met server-gerenderde, opgeschoonde HTML. Het paneel schuift als overlay rechts in/uit, verkleint de tabel niet en heeft een eigen scrollgebied. Sluiten/Escape annuleert lopende aanvragen en herstelt focus; snel wisselen kan geen verouderde preview laten winnen. Reduced-motion wordt gerespecteerd. Mobiel blijft de preview viewportgebonden, terwijl de lijst met de pagina scrollt. Zonder JavaScript blijft de titel een detailpaginalink.

`SpotColumns` deelt toegestane kolommen, vaste volgorde, standaardsets en formuliervalidatie. Titel is verplicht; andere kolommen zijn optioneel. Adminstandaarden beperken persoonlijke keuzes niet. Alleen hoofdcategorieen met een override worden opgeslagen; reset verwijdert deze en volgt toekomstige globale wijzigingen. De gemengde Alle-lijst heeft een algemene vaste set. Beide POST-routes vereisen CSRF en loggen wijzigingen in de auditlog. Migratie 0009 voegt de username-gebonden tabel toe. Tabelcellen breken lange tekst af; onder 951px worden rijen kaarten in plaats van horizontaal te scrollen.

`SpotPresentation` deelt dezelfde bron tussen cover, vervaagde poster-vulling en hero-achtergrond. Het detailframe is 230 x 345 CSS-pixels op desktop, 105 x 158 op mobiel; previewframes zijn 110 x 165, op mobiel 90 x 135. De voorgrond behoudt verhouding en vergroot kleine beelden niet; de decoratieve laag vult de resterende ruimte. De hero-achtergrond wordt afzonderlijk uitgerekt en licht vervaagd (6px), met een thema-afhankelijke contrastoverlay. Zonder afbeelding blijft een vaste placeholder; laadfouten tonen expliciete feedback.

`SpotDescription` gebruikt ext-dom om onbetrouwbare HTML te parsen en bouwt uitsluitend toegestane opmaaktags opnieuw op. Alle remote attributen verdwijnen, behalve gevalideerde HTTP(S)-links met vaste veilige linkattributen. Actieve subtrees, stylesheets, embeds en remote afbeeldingen in omschrijvingen worden niet gerenderd. Tekst wordt opnieuw geescapet; gewone regeleinden blijven behouden. Preview en detail delen exact deze renderer. Dit is geen algemene HTML-/BBCode-browser.

`SpotMarkup` verwerkt uitsluitend de tekstnodes van deze opgeschoonde DOM, nooit remote HTML-attributen of actieve subtrees. `[br]` wordt een regeleinde; een begrensde parser ondersteunt nadruk, citaten, code, lijsten en HTTP(S)-links. HTML en bracketcodes kunnen samen voorkomen. Kleur/font/grootte/uitlijning behouden tekst zonder externe styling; onbekende tags blijven leesbare tekst. HTML- en BBCode-links delen dezelfde URL-validatie, er ontstaan geen geneste links. Codeblokken blijven letterlijk; diepte boven 100 faalt expliciet. Geimporteerde Usenet-reacties gebruiken dezelfde renderer; lokale reacties blijven platte geescapete tekst, met uitsluitend de expliciete emoticonuitzondering hieronder.

`SpotEmoticons` deelt een vaste allowlist voor de 30 aangeleverde Spotweb-namen plus `respect` als alias van `respekt`. `[img=naam]` wordt alleen voor deze namen omgezet naar statische Unicode-emoji's in toegankelijke spans (`role=img`, Nederlands label, verborgen decoratieve inhoud). Respect, exactly en censored krijgen een compacte tekstbadge. Geen GIF's, remote URLs, assetbestanden, animatie of extra library. `[img=URL]`, onbekende namen en algemene afbeeldingsmarkup blijven tekst. De HTML-sanitizer laat nog steeds geen remote afbeeldingen/attributen toe. Lokale reacties krijgen alleen emoticonvervanging na escaping, geen algemene HTML/BBCode-parser. De oorspronkelijke inhoud in de database verandert niet; bestaande reacties worden bij renderen compatibel. De spotafbeeldingsschakelaar blijft onafhankelijk van deze tekstsymbolen.

`SpotPresentation` maakt voor niet-thumbnailcovers een gewone afbeeldingslink met een lightbox-hook. `presentation.js` verzorgt gedelegeerde activatie, ook voor later geladen previews, in een native modale `dialog` met Bootstrap-knoppen en alerts. Browserfocus/inertness blokkeren de achtergrond; Escape sluit alleen de lightbox, niet ook de preview. Afbeeldingen passen proportioneel binnen de viewport. Zonder script of dialogondersteuning blijft de gewone afbeeldingslink bruikbaar. Er worden geen nieuwe afbeeldinghosts of server-fetch-URLs geintroduceerd.

### Op te halen inhoud
`app_settings.admin.spot_content` bewaart drie gevalideerde booleans: `retrieve_comments`, `show_comments` en `images_enabled`, standaard allemaal true voor compatibiliteit. De adminroute vereist CSRF, valideert scalaire checkboxinvoer en logt wijzigingen zonder credentials. Retentie/artikellimiet blijven in de bestaande Usenet-instellingen.

Reacties indexeren uit filtert reactiegroepen voor de NNTP-groepsselectie, zonder hun cursors bij te werken. Tonen staat hiervan los: uitschakelen onderdrukt Usenet/lokale reacties en formulieren in detail/admin en blokkeert beide lokale POST-routes. Alleen indexeren uit laat eerder opgeslagen reacties zichtbaar met uitleg. Opnieuw aan hervat vanaf de cursor; beschikbare historie kan met --days/--all worden aangevuld.

Afbeeldingen uit onderdrukt thumbnails/coverkolom, posters, lightboxlinks en hero-achtergrond in lijst/batches/preview/detail. De image-route en afbeeldingservice controleren de instelling voor de databasecache of NNTP. Externe cover-URLs worden evenmin in de pagina gepubliceerd. Cache en originele segmentverwijzingen blijven opgeslagen. De instelling geldt bij de volgende aanvraag; reeds gedownloade inhoud kan niet uit een bestaande browsertab worden teruggeroepen.

## Retrievalsemantiek
- `--since-last`: vanaf de laatst voltooide batch, met de ingestelde artikellimiet per groep. Bij de eerste run begint dit bij het oudste beschikbare artikel. Cursors worden pas na een succesvolle batch opgeslagen.
- `--days=N`: vanaf de hoogste artikelnummers terug scannen en alleen inhoud binnen het tijdvenster ophalen. Dit levert doorgaans eerder zichtbare recente spots op. Een artikelnummer is geen betrouwbare datum: alle ranges blijven gescand worden, ook na oude of lege batches. De artikellimiet mag geen recente spots verbergen. De incrementele cursor wordt pas na een volledig geslaagde groepsscan naar het groepseinde verplaatst; een afgebroken omgekeerde scan mag oudere ongelezen ranges niet overslaan.
- `--all`: volledige beschikbare historie, zonder retentiefilter.
- Opnieuw uitvoeren is idempotent. Gelijke titels met verschillende message-IDs blijven verschillende spots.
- Corrupte XML en verdwenen artikelen worden geteld en gemeld. Transport- of databasefouten breken de run af; er wordt geen lege succesvolle fallback gemaakt.
- Verbose batchoutput toont het geldige UTC-datumbereik en afzonderlijke aantallen te oud, bestaand, geen spot, ongeldig, verdwenen en opgeslagen. Het totale aantal opgeslagen berichten blijft zichtbaar. Een corrupt optioneel volledig veld geeft een waarschuwing, geen afwijzing van verder bruikbare spotmetadata.

## Web en beveiligingsgrenzen
- `/spots`: individuele spots, stapelbare type-/subcategoriefilters, titelzoekfunctie, keyset-batches, optionele thumbnails en een initieel gesloten preview.
- `/spots/view?key=...`: afbeelding, metadata, omschrijving, reacties en een CSRF-beveiligd formulier voor lokale reacties.
- `/spots/image?key=...`: alleen voor ingelogde gebruikers met zichtbaarheidstoegang; rasterafbeeldingen met `nosniff` en databasecache. Lijst, detail en afbeeldingen gebruiken `private, no-store`, zodat ingetrokken zichtbaarheid niet via een normale browsercache wordt omzeild.
- Usenet XML wordt zonder DTD/entiteiten verwerkt. Segment-IDs worden gevalideerd voor NNTP-gebruik.
- Omschrijvingen en Usenet-reacties worden via een DOM-/Spotnet-opmaak-allowlist opgebouwd; lokale reacties worden als tekst geescapet. Onbetrouwbare HTML of attributen worden nooit rechtstreeks uitgevoerd.
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
- Verdere Spotnet-tagcompatibiliteit en expliciete compatibiliteit voor afwijkende legacy-XML.
- NZB-download/forwarding, Newznab en metadata.
- Indexing-jobs, scheduling en performance-optimalisatie van grote backfills.
