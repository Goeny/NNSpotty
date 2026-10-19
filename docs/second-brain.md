# NNSpotty Second Brain

Doel: centrale contextbron voor keuzes, aannames, besluiten en volgende stappen.

## Snapshot
- **Datum:** 2026-10-18
- **Versie:** 0.0.1
- **Codenaam:** Delta Integrale
- **Fase:** Spotnet/Newznab, SABnzbd en optionele TheTVDB-verrijking met gedeeld matchbeheer

## Context
- Spotweb is verouderd en lastig onderhoudbaar door gegroeide complexiteit en gemengde oude/nieuwe codepatronen.
- NNSpotty wordt een nieuwe, schone implementatie met php-nntp als basis voor Usenet-functionaliteit.

## Productprincipes
- Installatie laagdrempelig maken.
- Gebruikservaring eenvoudig houden.
- Theming als first-class capability.
- Prestaties en moderne architectuur als baseline.

## Gemaakte beslissingen
1. **Greenfield aanpak**  
   Geen incrementele legacy-fix strategie; NNSpotty is een nieuwe codebase.

2. **Versioning gestart op 0.0.1**  
   Conform afgesproken developmentflow richting 1.0.0.

3. **Codenaamstrategie geactiveerd**  
   Releasenamen gebruiken automodel-varianten.
   - 0.0.1 codenaam: Delta Integrale.

4. **Documentatie eerst**  
   We leggen fundamentele richtlijnen vast vóór implementatie.

5. **Initiële runnable scaffold toegevoegd**  
   Er is nu een minimale HTTP-laag, een health endpoint, env-config en php-nntp client-factory.

6. **Teamproductiviteit in VS Code voorbereid**  
   Basis extensie-aanbevelingen, tasks en launch-config toegevoegd.

7. **Scaffold gevalideerd en gelanceerd**  
   Syntax-check uitgevoerd op alle PHP-bestanden en health endpoint lokaal succesvol getest.

8. **Nieuwe kernrequirements toegevoegd**  
   Admin panel, username-based preferences, spotter whitelist/blacklist en post comments zijn toegevoegd als verplichte capabilities.

9. **Eerste feature-scaffold voor nieuwe requirements live**  
   Admin routes/UI, preference routes/UI en persistente JSON-opslag voor usenet settings, spotterlijsten en comments zijn toegevoegd.

10. **Datastore-richting aangescherpt**  
    Alle data moet naar database-opslag (voorkeur MySQL/MariaDB); file-based state is alleen tijdelijk prototype.

11. **Console-first beheer vereist**  
    Kernoperaties moeten beschikbaar zijn via `bin/console` voor retrieve/user/db workflows.

12. **Database-first geïmplementeerd**  
    Prototype JSON-state is vervangen door MySQL/MariaDB opslag via PDO en migraties.

13. **Console basis live**  
    `bin/console` ondersteunt nu db/user/retrieve basisacties voor operationeel beheer.

14. **Authenticatie-baseline toegevoegd**  
    Sessiegebaseerde login/logout is toegevoegd, adminroutes zijn afgeschermd en user preferences zijn nu gekoppeld aan de ingelogde gebruiker.

15. **CSRF-bescherming actief**  
    Alle POST-routes vereisen nu een geldige CSRF token uit de sessie.

16. **Login rate limiting actief**  
    Mislukte loginpogingen worden in de database gelogd en tijdelijk geblokkeerd bij herhaalde failures.

17. **Admin audit logging toegevoegd**  
    Belangrijke beheeracties (settings, spotters, comments, preferences) worden nu met actor, IP en details gelogd.

18. **Echte retrieve-flow gestart**  
    `bin/console retrieve` haalt nu NNTP overviews op en slaat posts op met categorie/newsgroup, inclusief cursor per groep.

19. **Retrieve-default aangepast naar auto-discovery**  
    Individuele groupconfiguratie is nu optioneel; standaard worden groepen automatisch ontdekt.

20. **Moderatiebeleid aangescherpt**  
    Whitelist/blacklist is optionele moderatie, niet de standaard-allowlist voor normale werking.

21. **Admin-first configuratie bevestigd**  
    `.env` blijft voor basis app/DB config; Usenet/retrieve operationele settings worden via admin beheerd.

22. **Generieke headergroepering vervangen door Spotnet**
    Onderzoek van `spotweb/spotweb` bevestigt dat spots uit `free.pt` komen, met volledige inhoud in de herhaalde `X-XML`-headers. Reacties komen uit `free.usenet`, niet `free.ptd`. Binaire afbeeldingen/NZB-verwijzingen komen uit de XML, niet uit onderwerpheuristieken.

23. **Eigen protocolimplementatie, geen legacy-code overgenomen**
    `SpotnetParser`, `SpotnetOverviewCommand`, `SpotRepository` en `SpotImageService` scheiden parsing, NNTP, opslag en rendering. Een message-ID is de identiteit van een spot; gelijke titels worden niet samengevoegd.

24. **Niet-destructieve overgang**
    Nieuwe `spotnet_*` tabellen en cursors worden naast oude headers/releases aangelegd. Historische generieke data blijft bewaard maar is niet zichtbaar als spot. Opnieuw ophalen is nodig; `db reindex-spots` kan ontbrekende XML niet reconstrueren.

25. **Volledige inhoud en reacties in de UI**
    De detailpagina toont omschrijving, metadata, afbeelding en Usenet/lokale reacties. Afbeeldingen worden op aanvraag uit Usenet gehaald en in de database gecachet. Lokale reacties worden niet naar Usenet gepubliceerd.

26. **Expliciete grenzen van de eerste Spotnet-implementatie**
    Inhoud wordt veilig als tekst weergegeven en als onbevestigd aangeduid. Handtekeningverificatie, uitgebreide Spotnet-opmaak, afwijkende legacy-XML, NZB-download/forwarding en Newznab zijn nog geen gerealiseerde capabilities.

27. **Retrievalsemantiek gecorrigeerd en getest**
    `--all` verwerkt de volledige beschikbare historie zonder retentie. `--days` scant headers op datum zonder een arbitraire artikellimiet. `--since-last` werkt in begrensde hervatbare batches. Protocoltests en integratietests met een lokale NNTP-fixture en aparte MariaDB valideren deze paden.

28. **Provider-overviews en eerste zichtbare resultaten verbeterd**
    Een serverrun brak af rond artikel 1343002 door een te strikte overview-veldcontrole, na uitsluitend nul-opslagmeldingen. Ontbrekende optionele staartvelden zijn nu toegestaan; werkelijk onbruikbare regels worden per regel gemeld, zonder andere artikelen te blokkeren. `--days` verwerkt de hoogste artikelranges eerst, maar scant wel de hele groep voor volledigheid bij niet-monotone datums. De cursor schuift pas na een volledig geslaagde groepsscan vooruit. Batchlogging toont nu datums en redenen voor niet opslaan.

29. **Afronden omvat committen en pushen**
    Zodra taakgerelateerde aanpassingen gereed en gevalideerd zijn, worden ze zonder apart verzoek gecommit en naar de huidige Git-branch gepusht. Ongerelateerde wijzigingen en secrets blijven buiten de commit; force-push is niet toegestaan en fouten worden expliciet gemeld.

30. **Volledige Spotnet-taxonomie zonder legacy engine**
    De vier hoofdgroepen krijgen alle protocol-dimensies, leesbare labels en afgeleide inhoudstypen. Bestaande ruwe codes blijven behouden. SQL-facetten en classificatie worden bij nieuwe spots atomair bijgewerkt; bestaande spots worden automatisch in batches gemigreerd, zonder hernieuwde retrieval. Een aparte backfillmigratie maakt hervatten na een onderbreking mogelijk.

31. **Globale blokkade plus persoonlijke opt-in voor erotica**
    Door de gebruiker gekozen: globaal uit blokkeert iedereen, ook admins; globaal aan geeft individuele keuze, standaard verborgen. Beide defaults zijn uit. Dezelfde controle geldt voor lijsten, aantallen, zoeken/facetten, preview, detail, gecachte afbeeldingen en beide reactieroutes. Opgeslagen inhoud en persoonlijke opt-in blijven bij een globale blokkade behouden. Classificatie is gebaseerd op metadata, inclusief legacy adult-genres, niet op inhoudsanalyse.

32. **Screenshotgestuurde presentatie zonder fictieve functies**
    Gedeelde donkere sidebar/topbar, compacte lijst, geselecteerde preview en detailhero volgen de aangeleverde screenshots. Lichte themapreferences zijn daadwerkelijk aangesloten. Niet-bestaande downloads, ratings, watchlists en geverifieerde spotters worden niet gefingeerd. Desktop gebruikt meerdere panelen; mobiel stapelt de inhoud zonder horizontale pagina-overflow.

33. **Vaste navigatie en onafhankelijke scrollgebieden**
    De hoofdgroepen Beeld/Geluid/Spellen/Applicaties staan in de vaste header naast Alle; de zoekfunctie blijft ook vast. De geselecteerde hoofdgroep bepaalt alle subcategorieen links, inclusief lege keuzes maar zonder geblokkeerde adult-codes. De aanvankelijk gestapelde mobiele preview is vervangen door het viewportgebonden schuifpaneel in besluit 36.

34. **Coverframe en tijdelijke fanart**
    De detailcover krijgt een vast frame van 230 x 345 CSS-pixels, mobiel 105 x 158. Grote covers schalen proportioneel omlaag; kleine covers krijgen vervaagde vulling in plaats van scherpe vergroting. Een aparte uitgerekte, vervaagde kopie van de hoofdcover vormt de hero-achtergrond totdat echte fanart beschikbaar is.

35. **Veilige HTML-opmaak in omschrijvingen**
    De melding dat alles als tekst wordt weergegeven vervalt voor omschrijvingen: preview en detail delen nu een ext-dom-renderer met een strikte opmaak-allowlist. Remote scripts, CSS, attributen, embeds en omschrijvingsafbeeldingen worden niet uitgevoerd of geladen. Reacties blijven geescapete tekst; BBCode is nog niet ondersteund.

36. **Preview alleen op selectie, als schuifpaneel**
    Initieel geen geselecteerde spot of geladen detailinhoud. Klik op de regel/toetsenbordactivatie opent rechts een zelfstandig scrollbare overlay met slide-in/slide-out. Sluitknop en Escape annuleren aanvragen en herstellen focus; reduced-motion wordt ondersteund. Auth, erotica-beleid en veilige HTML blijven ook op de asynchrone previewroute gelden.

37. **Oneindige lijst met stabiele keyset**
    Geen paginanummers meer: nabij het einde wordt de volgende batch geladen op datum plus ID. Dit voorkomt verschoven OFFSET-resultaten bij nieuwe indexering. Een zichtbare laadfout stopt automatisch laden en biedt retry. De bestaande persoonlijke batchgrootte blijft 10-200; zonder JavaScript is er een cursor-vervolglink.

38. **Alle subcategorieen zijn EN-filters**
    Gebruiker heeft expliciet EN gekozen, ook voor meerdere codes binnen dezelfde dimensie. Herhaald klikken toggelt alleen die code; chips kunnen afzonderlijk worden verwijderd. Zoeken en vervolgbatches bewaren de selectie. Aantallen reflecteren alle actieve filters.

39. **Globale kolomstandaarden met persoonlijke overrides**
    Gebruiker heeft gekozen voor adminstandaarden per protocol-hoofdgroep, niet voor globale beperkingen. Username-gebonden overrides staan apart in de database; reset verwijdert de override en volgt de actuele globale keuze. Titel blijft verplicht; Alle gebruikt een vaste algemene set. Lange tekst breekt af en mobiel worden regels kaarten, zonder horizontale tabelscroll.

40. **Duidelijkere tijdelijke fanart**
    Hero-blur verminderd van 24px naar 6px met aangepaste donker/licht-overlay. De cover blijft de tijdelijke uitgerekte achtergrond; echte fanart/metadata is nog niet geintegreerd. Posterframe en eigen vervaagde vulling blijven behouden.

41. **Gegenereerde en lokale bestanden niet langer in Git**
    Inspectie toonde gevolgde `.env` en volledige `vendor/`. Beide worden alleen uit de index verwijderd; lokale bestanden blijven bestaan. `.gitignore` sluit ook Composer-auth, logs/dumps/runtime/coverage uit. `composer.json`, `composer.lock`, `.env.example` en gedeelde VS Code-configuratie blijven gevolgd. De eenmalige serverpull vereist een configuratiebackup/restore en opnieuw Composer install; Git-historie wordt niet herschreven. Eventuele eerder vastgelegde echte credentials moeten worden geroteerd.

42. **Admin bepaalt inhoud, ophalen en tonen blijven gescheiden**
    Gebruiker koos schakelaars voor onderdelen, niet voor nieuwe metadata-integratie of een reactielimiet per spot. `retrieve_comments`, `show_comments`, `images_enabled` staan standaard aan. Uitgeschakelde commentretrieval benadert geen reactiegroepen en schuift geen cursors op. Weergave uit blokkeert bestaande/lokale reacties en beide schrijfinterfaces. Afbeeldingen uit blokkeert ook cache, externe covers, thumbnails en hero; er wordt niets verwijderd. Retentie en incrementele artikellimiet blijven de bestaande kwantiteitsinstellingen.

43. **Afbeeldingvergroting zonder extra UI-afhankelijkheid**
    Coverlink in detail en preview opent een native modale dialog, met Bootstrap-knoppen/alerts, focusherstel, Escape, buitenklik en viewportpassende afbeelding. De bestaande vaste posterframes blijven behouden. Gedelegeerde events werken ook na preview-fetch; Escape sluit niet tegelijk het onderliggende schuifpaneel. Zonder script blijft een gewone afbeeldingslink beschikbaar.

44. **Spotnet-bracketcodes zijn opmaak, geen HTML**
    De aangeleverde `[br]`-omschrijving bevestigt Spotnet/BBCode-inhoud. Een eigen begrensde tekstparser ondersteunt regeleinden, nadruk, citaten, code, lijsten en veilige links naast de bestaande DOM-allowlist. Kleur/font/grootte/uitlijning voeren geen remote stijlen uit. Onbekende tags blijven tekst, afbeeldingen/actieve inhoud worden niet toegestaan. Usenet-reacties delen de renderer; lokale reacties blijven tekst. Geen nieuwe retrieval nodig voor bestaande omschrijvingen.

45. **Publieke assets moeten leesbaar blijven voor Apache**
    Na de Git-opruiming gaf de productieserver voor alle drie publieke CSS/JS-assets HTTP 403. De gegeven deploymentprocedure hield `umask 077` actief tijdens Git/Composer; dat kan publieke bestanden onleesbaar maken voor een afzonderlijke Apache-gebruiker. Deployment gebruikt voortaan 022, uitsluitend de geheime backupcreatie gebruikt 077 in een subshell. Gericht herstel: publieke directories 755, de drie assets 644; `.env` blijft 600. Geen applicatie-CSS-wijziging nodig; herstel op productie moet met HTTP 200 worden bevestigd.

46. **Reactiekoppeling volgt Spotnet-ID, niet provider-References**
    De vergelijking met dezelfde spot in Spotweb toont meer reacties en de clientnaam. Onderzoek van Spotweb bevestigt dat providers References kunnen verminken: de gecodeerde spotverwijzing in het reactie-ID is leidend. NNSpotty gaf foutief References voorrang. Parser en migratie 0010 corrigeren dit, zonder bestaande bodies opnieuw op te halen of lokale reacties te wijzigen. De screenshots alleen bewijzen niet of de productieretrieval de reactiegroep al bereikte; dat blijft een operationele controle.

47. **Gerichte reactieretrieval en expliciete metadatarefresh**
    Normale retrieval blijft spots eerst verwerken. `--comments-only` voorkomt dat een historische spotscan het binnenhalen van reacties ophoudt, respecteert adminbeleid en raakt de spotcursor niet aan. `--refresh-spots` met een expliciete backfillperiode leest opgeslagen spots opnieuw, vult onder andere X-Newsreader aan en vernieuwt metadata/XML/facetten atomair. Gewone duplicaten blijven ongemoeid; alleen gewijzigde afbeeldingsbronnen maken de cache ongeldig. Geen nagebootste ratings, spotterverificatie, spamrapportages of werkende NZB-knoppen. Volledige geselecteerde headerscans kunnen nog steeds lang duren.

48. **Oude emoticoncodes behouden, GIF-presentatie vervangen**
    Gebruiker kiest statische moderne emoji's en compacte badges, zonder animatie of externe afbeeldingen. Vaste allowlist dekt alle 30 aangeleverde Spotweb-codes plus respect/respekt; wink/knipoog delen een symbool. Code, opslag en bron blijven intact; vervanging gebeurt uitsluitend bij renderen met toegankelijke vaste labels. Usenet-omschrijvingen/reacties delen de parser; lokale reacties krijgen alleen de emoticonuitzondering en blijven verder escaped tekst. Geen afbeeldings-URL-ondersteuning, migratie, retrieval of GIF-hergebruik nodig. Herhaalde badges moeten op smalle schermen zonder horizontale overflow afbreken. Dit verfijnt het eerdere beleid dat alle img-bracketcodes tekst bleven; externe afbeeldingen blijven geblokkeerd.

49. **Prioriteit: gedeelde NZB-service, dan Newznab, dan forwarding**
    Gebruiker wil NNSpotty via een key in Prowlarr gebruiken, dat zelf Sonarr synchroniseert. Gedeelde NZB-downloads vormen de basis: echte segments ophalen, combineren, Spotnet-decoderen, raw-inflate, begrensd valideren en in de database cachen. Geen nepknop, externe URL-proxy of downloaderimplementatie in deze milestone.

50. **Globale API-toestemming, persoonlijke keys**
    Gebruiker kiest expliciet: admin schakelt Newznab globaal in, daarna beheert iedere gebruiker zijn eigen key zonder extra individuele toestemming. Default uit; 32 willekeurige bytes, alleen SHA256 opgeslagen, eenmaal tonen, roteren/intrekken met bevestiging en CSRF, audit zonder geheim. API is sessieloos en volgt bestaande globale/persoonlijke erotica-instellingen. Keydragende links vragen om HTTPS en queryvrije accesslogs.

51. **Echte standaardcategorieen en eerlijke capabilities**
    1000-8000 zijn standaard Newznab-hoofdcodes; de 100000+-duplicaten uit Spotweb zijn provider-specifiek en worden niet gekopieerd. Eigen Spotnet-mapping en SQL-index ondersteunen hoofd-/subgroep-OF in API; browserfilters blijven EN. Huidige Prowlarr-standaard bevat UHD 2045/5045, PS4 1180 en Xbox One 1140. Caps/search/tvsearch/movie/get leveren XML, titelzoeken en numerieke seizoen/aflevering/jaarfilters; IMDb/TVDB-ID-zoeken wordt niet geclaimd zonder betrouwbare metadata. Dagelijkse/absolute episodes en complete multi-episodeherkenning zijn niet gerealiseerd; pakketten blijven pakketten.

52. **Migratie en toetsbare acceptatie**
    Migratie 0011 voegt NZB-cache, standaardcategorie-index en keytabel toe en backfillt bestaande spots in batches. Refresh wist gewijzigde NZB-bronnen; normale duplicaten blijven intact. Synthetische NNTP-, echte MariaDB- en HTTP-regressies valideren het protocol en de grenzen, niet de externe productieprovider of Sonarr-releaseherkenning. Praktijkacceptatie moet caps, zoeken en een werkelijke download vanuit Prowlarr omvatten; alleen de verbindingstest is onvoldoende. Versie blijft 0.0.1 Delta Integrale.
    Validatie: 806 controles geslaagd met PHP 8.4 en MariaDB 11.4; alle 46 PHP-bestanden gelint. Browsercontrole van adminschakelaar, eenmalige sleutelweergave, vernieuwen/intrekken, veilige annuleerfocus, Escape/focusherstel en donkere/lichte layouts op 320/390/1440 pixels. Native bevestigingen sluiten expliciet bij Escape en passen binnen het viewport; geen browserpopups.

53. **Centrale SABnzbd alleen admins; persoonlijke verbinding per gebruiker**
    Gebruiker verduidelijkt expliciet dat iedere gebruiker zijn eigen verbinding mag instellen en eigen externe wachtrij mag inzien. Gewone gebruikers mogen uitsluitend publieke hosts benaderen; lokaal betekent ook LAN/private/link-local/gereserveerde IPv4/IPv6. Centrale en interne verbindingen blijven voor admins. Scopes komen uit de sessie, nooit uit een aangeleverde username. Huidige rol en alle DNS-adressen worden bij iedere uitgaande aanvraag beoordeeld en het gecontroleerde adres wordt in cURL vastgepind; geen redirects/proxyfallback/TLS-uitzondering. Persoonlijke hosts worden niet aan andere gebruikers getoond.

54. **Admin beheert centrale categorietoewijzing per spottype**
    Films, Series, Boeken, Erotica, Afbeeldingen, Muziek, Livesets, Podcasts, Luisterboeken, Spellen, Applicaties en Overig krijgen een exacte SABnzbd-categorienaam. Dit geldt ook voor persoonlijke verbindingen; namen moeten op de doelhost bestaan. Leeg betekent Default. Geen stille fallback bij ontbrekende categorie. Vooraf bevestigen toont de effectieve keuze; een host/key/categorie-revisie voorkomt verouderde bevestigingen. Volledige SAB-key is nodig voor queue/get_cats; geen beperkte NZB-key. Keys zijn herstelbaar in de database voor uitgaande authenticatie, worden niet teruggerenderd/geauditeerd; backups blijven geheim.

55. **Inhoud uploaden, geen NZB-URL; onzekerheid expliciet bewaren**
    SABnzbd gebruikt dezelfde gevalideerde `SpotNzbService` als browser/Newznab. Multipart `addfile` POST bevat NZB-bytes, releasenaam en categorie. Uniek scope/bevestigingstoken voorkomt herhaalde upload. Databasepogingen onderscheiden gestart, geaccepteerd, geweigerd en onbekend. Externe acceptatie en DB-commit kunnen niet atomair: na timeout/crash eerst SAB controleren, niet automatisch opnieuw versturen. Geaccepteerd is niet voltooid. Lokale verzendhistorie volgt spotzichtbaarheid en blijft zonder actieve verbinding opvraagbaar. Geen completed-history-sync, NZBGet of queue-mutaties in deze scope.

56. **Beheeronderdelen en eigen modern downloadpaneel**
    Admin is verdeeld in Usenet, Inhoud en weergave, Newznab, SABnzbd, Moderatie en reacties, Auditlog met deelbare fragmentlinks en zonder-JavaScript-fallback. De headerknop Downloads en de spotknop Naar SABnzbd gebruiken een native modaal slide-in/out-paneel: echte wachtrij/voortgang/snelheid, SVG-grafiek van de laatste vijf minuten tijdens de panelsessie, lokale verzendhistorie en bevestiging. Polling elke vijf seconden uitsluitend open/zichtbaar, geen automatische retries na fouten. Queuepagina's zijn 30 items; historie laatste 30. Andere SAB-velden zoals wachtwoorden/paden worden niet doorgestuurd. Externe jobnamen blijven tekst. De volledige eigen externe wachtrij kan inhoud van buiten NNSpotty bevatten en is niet betrouwbaar op erotica te classificeren.
    Nieuwe platformvereiste ext-curl; migratie 0012 voegt verbindingen/mapping/historie toe. Versie blijft 0.0.1 Delta Integrale. Validatie: 928 controles geslaagd met PHP 8.4 en echte MariaDB 11.4, alle PHP-syntaxchecks en Composer-platformvereisten akkoord. Synthetische SAB-server verifieert daadwerkelijke multipart-NZB-inhoud/categorie en acceptatie/weigering/onzekere uitkomst. Browser: upload/historie, veilige jobtekst, zes beheeronderdelen, donker/licht, 320/390/768/1440px zonder horizontale overflow, Escape/focusherstel en gewone gebruiker zonder centrale toegang met expliciete interne-hostweigering. Praktijkacceptatie met de echte externe SAB-host blijft nodig.

57. **Huisstijlbronnen behouden, kleine lokale webafbeeldingen gebruiken**
    Vier aangeleverde PNG's uit de tijdelijke rootmap NNSpotty zijn ongewijzigd verplaatst naar docs/branding (logo, favicon, placeholder en presentation). De lege aanlevermap is daarna verwijderd. Afgeleide PNG's in public/assets/images beperken overdracht: favicon 32px, headericoon 64px, touch-icon 180px, logo 768x512, posterplaceholder 320x480 en lijstplaceholder 84x126. Alle HTML-layouts gebruiken dezelfde iconen; start/login tonen het logo en externe demoafbeeldingen op home zijn vervangen. Ontbrekende covers in overzicht/preview/detail tonen de placeholder zonder lightbox; laadfouten behouden expliciete feedback. Afbeeldingen uitgeschakeld blijft tekst zonder placeholderrequest. Presentatie blijft documentatie, niet onnodig laden in de applicatie. Geen migratie, retrieval of versieverhoging.
    Validatie: 665 unitcontroles geslaagd, inclusief 17 nieuwe huisstijl-/formaat-/bestandsgroottechecks; gewijzigde PHP-bestanden gelint. Bronbestanden bij verplaatsen via SHA256 gecontroleerd. Browser controleert alle lokale afbeeldingen, ontbrekende en falende covers, uitgeschakelde afbeeldingen, start/login en donker/licht op 320px en 1440px zonder horizontale overflow.

58. **Directe dubbelklik en compacte hero-acties**
    Gebruiker kiest enkele klik voor de bestaande slide-in-preview en dubbelklik op de hele overzichtsregel voor directe navigatie naar de volledige spot. Gedelegeerde events gelden ook voor bijgeladen rijen; tweede klik start geen tweede previewrequest, dubbelklik annuleert de lopende preview. Titellink/toetsenbord en modifierklikken blijven behouden. Reactie-, NZB- en SABnzbd-acties gebruiken compacte Bootstrap-knoppen in een rechts uitgelijnde, afbrekende actierij bovenin de hero, in preview en detail, zonder titeloverlap. Placeholder is voldoende visuele feedback: de oude coverwaarschuwing is verwijderd. Ook de hero-achtergrond gebruikt de placeholder als er geen cover is of deze niet laadt. De instelling afbeeldingen uit blijft alles blokkeren; lightbox/NZB/downloaderfouten behouden hun eigen foutmeldingen.
    Browseronderzoek liet zien dat direct inschuiven de tweede klik kan onderscheppen. De muisklik wacht daarom 500ms voor het openen; toetsenbordactivatie reageert direct. Validatie: 668 unitcontroles geslaagd en gewijzigde PHP-bestanden gelint; browser bevestigt enkele klik, dubbelklik op titel en overige cellen (ook met 350ms klikinterval), Enter/Escape en lazy-loaded rijen. Preview en detail getest op 320/768/1440px, donker/licht, ontbrekende/falende/uitgeschakelde afbeelding: geen horizontale pagina-/paneeloverflow, compacte acties rechtsboven zonder overlap en loaded blurred placeholderachtergrond waar toegestaan.

59. **TheTVDB als eerste externe metadataprovider**
    Gebruiker kiest expliciet een centrale API-key in admin, persoonlijke opt-in/out voor iedereen en conservatieve automatische matching met handmatige correcties uitsluitend door admins. Beide schakelaars zijn standaard uit; een globale blokkade geldt ook voor admins. API V4-key, optionele abonnee-PIN en taal (Nederlands met Engelse fallback of Engels) blijven centraal. Secrets/token moeten voor authenticatie herstelbaar zijn en worden nooit teruggetoond of in auditdetails opgenomen. De eigen TVDB-key/licentie moet op productie worden geaccepteerd; er is geen echte key in de ontwikkeltests gebruikt.

60. **On-demand verrijking zonder brondata te vervangen**
    Alleen volledige filmdetails en serie-/afleveringdetails starten een asynchrone CSRF-beveiligde POST met effectieve toestemming; overzicht, preview en retrieval starten geen TVDB-fetch. Originele titel, omschrijving, technische metadata, reacties en NZB/SAB/lightbox blijven bruikbaar. Unieke exact genormaliseerde titel/alias/vertaling, plus filmjaar, voorkomt gegokte remakes; exacte afleveringen gebruiken official ordering met ID/seizoen/nummervalidatie. Pakketten blijven series. Bij onduidelijkheid of fouten blijft de oorspronkelijke spot. Admin kan zoeken/kiezen/herstellen/blokkeren; wijzigingen gelden gedeeld. Een onvindbare handmatige aflevering wijzigt geen geldige eerdere keuze.

61. **Databasecache en begrensde providerkoppeling**
    Migratie 0013 voegt TVDB-settings/token, username-voorkeuren, providercache en match-/keuzecache toe. Entiteiten/matches zeven dagen, zoeken/negatieve matches een dag, foutstatus vijf minuten; opnieuw controleren respecteert TTL. Benamede locks en hercontrole na lock voorkomen dubbel werk. Centraal 90 uitgaande requests/minuut, uitsluitend vaste HTTPS-API/endpointallowlist, TLS-validatie, geen redirects/proxy, 3s connect/8s request/22s totaal, maximaal 4MiB response. Token maximaal 25 dagen hergebruiken en eenmaal vernieuwen bij 401. `db cleanup` verwijdert verlopen providercache. Geen metadata als runtimebestanden; artwork zelf laadt rechtstreeks van toegestane TVDB-hosts met no-referrer en valt onder de globale afbeeldingenschakelaar.

62. **Plex-geinspireerde inhoud, geen fictieve Plex-functies**
    Hero met echte fanart zonder blur, poster of widescreen afleveringstill, synopsis, datum/speelduur/genres, cast/crew en studio/netwerk. TVDB-teksten zijn UTF-8-veilig begrensd en geescapet; oorspronkelijke Spotnet-opmaak blijft apart. Attributie verwijst naar het juiste record. Geen playback, reviews of verzonnen ratings: `score` is populariteit. Persoon-/bedrijfs-/externe IDs worden bewaard voor later klikbaar ontdekken; Newznab IMDb/TVDB-ID-zoeken is nog niet toegevoegd. Versie blijft 0.0.1 Delta Integrale.
    Validatie: 1037 controles geslaagd met PHP 8.4 en echte MariaDB 11.4, inclusief metadata-matching, caches, tokenopslag, exact 90/91-requestbudget, verlopen-cachecleanup, UTF-8-lengtegrens, locks, toestemmingen, HTTP-CSRF/adminrechten, hidden-spotbeleid en bestaande regressies. Browser verifieert automatisch laden, zoeken/kiezen/blokkeren/reset, gedeelde keuzes zonder admincontrols voor gewone gebruikers, persoonlijke opt-out/in, intrekken van globale toegang tijdens een geopende detailpagina, expliciete 502-foutfallback, lege geheimvelden, zeven adminonderdelen, lightbox en bestaand SAB-paneel. Film en aflevering op 320/390/768/1440px in beide thema's zonder pagina-overflow. Lokale testafbeeldingen vervangen provider-artwork; echte TVDB-login/titels/posters moeten met de eigen key op productie worden gecontroleerd.

63. **TVDB-attributie en compact matchbeheer**
    Conform de door gebruiker aangewezen TVDB-attributierichtlijn krijgt daadwerkelijk getoonde metadata een zichtbare directe recordlink met tekst "Metadata van TheTVDB" en het kleine, originele lokale logo voor donker/licht. Aangeleverde tvdb-dark.png/tvdb-light.png zijn ongewijzigd in public/assets/images behouden. Branding blijft zichtbaar als postafbeeldingen uit staan; zonder TVDB-data geen bronclaim. De hero toont linksboven alleen "TVDB link"; alle matchstatus/opnieuw-controleren en admin-only handmatige keuzes staan in een native modaal slide-in/out-paneel met bestaande downloadpaneelstyling, zelfstandig scrollen, reduced-motion, Escape en focusherstel. Het paneel blijft buiten de AJAX-samenvatting en de trigger werkt na koppelen/blokkeren/reset. Sluiten blijft mogelijk tijdens requests; tijdelijk uitgeschakelde knoppen verliezen na het antwoord niet de toetsenbordfocus. Rechten, cache en opt-in blijven ongewijzigd.

64. **Direct naar overzicht, geen tussenhomepage**
    De gebruiker wil geen homepage met knoppen meer. De renderer is verwijderd; geslaagde login, bezoek aan login met actieve sessie en root-URL verwijzen direct naar /spots. Anoniem blijft de normale logincontrole gelden. Admin-/voorkeuren-/sidebarlinks verwijzen naar het overzicht. Geen migratie of nieuwe retrieval nodig; versie blijft 0.0.1 Delta Integrale.
    Validatie: 1048 controles geslaagd met PHP 8.4 en aparte MariaDB 11.4, inclusief daadwerkelijke HTTP-redirects, attributie met afbeeldingen uit, adminrechten en paneelmarkup. Browser bevestigt directe login/root-redirect, beide originele thema-logo's geladen, compacte hero-link, admin zoeken/kiezen/blokkeren/reset zonder paneelduplicatie, gewone gebruiker zonder correctiecontrols, Escape/focusherstel ook na AJAX, zelfstandig scrollen en geen horizontale pagina-/paneeloverflow op 320/390/768/1440px in donker/licht. Afleveringattributie verwijst naar de aflevering; coverlightbox en reduced-motion sluiten blijven werken. Geen echte TVDB-key gebruikt.

65. **Releaseopschoning zonder gegokte filmcollecties**
    Gebruiker vraagt betere matching van nummerprefixen en technische editie-/kwaliteitssuffixen. `03. ` wordt verwijderd, numerieke titels blijven behouden; IMAX, Theatrical Version, 4K/Ultra HD en Blu-ray begrenzen de zoekterm. Gebruiker bevestigt expliciet dat filmcollecties niet automatisch aan een enkele film mogen worden gekoppeld. Collection/trilogy/boxset en meerdere BD-discs blokkeren daarom een automatische filmkeuze, ook met jaar. Unieke exacte titel/alias en filmjaar blijven vereist. Resultaatcache krijgt parserversie v2; de bestaande identiteit van handmatige keuzes/blokkeringen blijft behouden. Geen migratie en geen provider-cacheflush.

66. **Afleveringsnummering niet repareren door andere aflevering te gokken**
    Officiele V4-specificatie gecontroleerd: het bestaande endpoint met official ordering, season/episodeNumber en integer episodevelden klopt. De publieke TVDB-pagina voor De Bondgenoten (ID 442105) biedt bij onderzoek alleen officiele seizoenen 1-3; seizoen 4 retourneert publiek 404. Er is dus geen bewijs voor een codefout die S04E01 naar een andere aflevering zou mogen omzetten. Exacte verificatie blijft behouden. Ontbrekende afleveringen tonen alleen seriedata met expliciet aangevraagd seizoen/nummer als niet door TVDB bevestigd. Fixtures bewijzen zowel correcte koppeling zodra exact S04E01 beschikbaar is als afwijzing van verkeerde serie/seizoen en dubbele nummers; geen echte API-key gebruikt.

67. **Releasekwaliteit en ronde castfallback**
    Video/audio/taal/ondertiteling komen uitsluitend uit herkende tokens van de oorspronkelijke titel, niet uit TVDB en niet uit een bestandsanalyse. De bronmelding voorkomt schijnzekerheid; geen verzonnen audiotalen, bitdiepte, subtitleformaat of HDR. Een gedeelde UI-helper levert hetzelfde resultaat in preview, oorspronkelijke hero en verrijkte hero, onafhankelijk van opt-in. Ontbrekende/defecte cast- en crewfoto's krijgen de bestaande lokale placeholder in dezelfde ronde 120px-stijl; bij afbeeldingen uit ook geen lokale placeholderrequests. Versie blijft 0.0.1 Delta Integrale.
    Validatie: 1077 controles geslaagd met PHP 8.4 en echte afzonderlijke MariaDB 11.4, inclusief de drie gebruikervoorbeelden, exacte/ontbrekende/dubbele afleveringen, parserversie-cachehercontrole met behoud van handmatige keuzes/blokkeringen, releasekwaliteit en HTTP-presentatie voor/na verrijking. PHP-syntax en editorchecks geslaagd. Browser: drie voorbeeldreleases op 320/390/768/1440px in donker/licht (24 combinaties) zonder horizontale pagina-overflow, kwaliteit in preview en detail, dubbelkliknavigatie, juiste opgeschoonde LOTR-zoekterm zonder filmkeuze, eerlijke S04E01-melding, drawer/Escape/focus en lightbox. Normaal, ontbrekend en defect castbeeld allemaal geladen als rond 120px-portret. Browser gebruikt databasefixtures en onderschepte synthetische artwork, geen echte providerkey.

68. **README als volledig en blijvend actueel instappunt**
    Op verzoek van de gebruiker is de README opnieuw opgebouwd met het lokale logo bovenaan, een inventaris van huidige functionaliteit, installatie vanaf een lege database inclusief eerste admin, historische eerste retrieve en incrementeel vervolg, configuratie van Newznab/SABnzbd/TVDB, onderhoud, tests en expliciete grenzen. Third-party runtimecode, protocollen, externe applicaties en ontwikkeltools met links staan als laatste onderdeel, met onderscheid tussen afhankelijkheid, integratie, referentie en toekomstdoel. Broncontrole bevestigt dat opgeslagen spotterlijsten nog niet als filter worden afgedwongen; de README maakt dit expliciet. De projectinstructies verplichten voortaan README-onderhoud bij iedere nieuwe of gewijzigde feature, inclusief configuratie/commando's/beperkingen/third-partygebruik. Geen runtimewijziging, migratie of versie-/codenaamwijziging.
    Validatie: functies/configuratie en commando's gecontroleerd tegen bestaande renderers, console, retrieve-service, dependency-manifest/lockfile en installatiedocumentatie. Lokale documentatielinks/logo gecontroleerd; bestaande TVDB-ankerlink blijft behouden. Alleen documentatie gewijzigd, geen runtime-tests nodig.

69. **Metadata voor alle relevante domeinen als toekomstwerk**
    Gebruiker wil op termijn metadata voor overige categorieen: muziek bijvoorbeeld MusicBrainz, games IGDB of RAWG, software AppStream en boeken Open Library. Opgenomen in projectinstructies en README als roadmap, niet als bestaande koppelingen of definitieve keuzes. Per domein nog matching/identificatie, databron/API, toegang/licentie, attributie, limieten en opt-in/caching uitwerken. AppStream is een standaard/catalogus-ecosysteem, geen universele softwarezoek-API. De huidige TVDB-integratie blijft ongewijzigd.

70. **Plex-accenten zonder layoutwijziging**
    Op verzoek worden primaire knoppen, badges, filterlabels/selectiecontrols en schakelaars oranje/goud in plaats van blauw. Gedeelde stylesheet dekt ook login en Bootstrap-hover/focus/active/disabled; donker/licht krijgen leesbare tekstkleuren. Semantische danger/success/info, layout, achtergronden en grafiek blijven gelijk.

71. **Nieuw sinds vorig browsebezoek in filterbadges**
    Gebruiker kiest expliciet een persoonlijke grens vanaf de vorige browsersessie, vast tijdens hetzelfde bezoek. Overzichttotalen en volledige filterresultaten blijven ongewijzigd; uitsluitend sidebarbadges tellen nieuwe imports. Migratie 0014 bewaart laatste overzicht-/detailactiviteit per username, maakt importtijd microseconde-nauwkeurig en indexeert die. De eerst gelezen vorige tijd blijft in de authsessie bevroren; activiteit wordt transactioneel opgeslagen voor de volgende sessie. Eerste gebruik start op nu zonder de historische database als nieuw te markeren. Login/logout reset de authgrens; tabs delen een sessie, bestaande andere sessies behouden hun grens. Achtergrondbatches, API, afbeeldingen en providerpolling verplaatsen niets. Nieuw is gebaseerd op eerste importtijd, niet publicatiedatum; refresh/duplicaten tellen niet opnieuw. Zoek-/categorie-/EN-/erotica-beleid geldt ook voor nieuwe aantallen. Geen nieuwe retrieve nodig voor de migratie; plan downtime/backup voor de spotstabel-ALTER. Versie blijft 0.0.1 Delta Integrale.
    Validatie: 1095 checks geslaagd met PHP 8.4/MariaDB 11.4 inclusief echte HTTP-sessies, exact 100 nieuwe tegenover 101 totale zichtbare films, microsecondegrens, eerste bezoek, herladen, gebruikersisolatie, gelijktijdige sessies, filters, verborgen erotica, refresh en cascade. Browser: 24 combinaties van overzicht/detail/admin/voorkeuren, Default/Plex Dark/Plex Light en 390/1440px zonder horizontale overflow; badge 100 tegenover 110 totaal blijft bij navigatie staan, nieuwe login en eerste andere gebruiker tonen 0 tegenover hetzelfde totaal. Login, preview en echte knopstaten base/hover/focus/active/disabled gecontroleerd; tekstcontrast van gevulde knoppen minimaal 6.17:1, licht label op wit 6.24:1. PHP-syntax en editorcontroles zonder fouten.

72. **Browsergeschiedenis herstelt diepe spotlijsten**
    Gebruiker wil na detailnavigatie met browser-terug doorgaan waar hij was, ook na 500/1000 spots. Iedere geschiedenisvermelding bewaart via `pagehide` alleen gebruikersgebonden URL/positie/lijstlengte/spotanker, geen private spot-HTML. Bij reconstructie worden de bestaande gefilterde batches opgehaald tot de eerdere diepte, daarna herstellen scrolloffsets en het zichtbare spotanker. Tijdelijk geen automatisch verder bijladen tijdens herstel; fouten staan zichtbaar met opnieuw proberen. Native back-forward-cache blijft bruikbaar zonder dubbele batches. Nieuwe categorie-/zoekoverzichten krijgen geen positie van andere geschiedenisvermeldingen. Actuele serverdata/zichtbaarheidsregels blijven leidend; bij een verwijderde ankerspot wordt de offset beperkt door de nog beschikbare lijst. Geen migratie/retrieve of versie-/codenaamwijziging.
    Validatie: 717 unitcontroles geslaagd, PHP-/JS-syntax en editorcontrole zonder fouten. Echte Edge/Playwright-browser met de productierenderer en 2000 fixture-regels: 500/1000 geladen spots op 1440px/390px, dubbelklik en preview-naar-detail, terug/vooruit/terug, behoud van zoekterm en twee EN-filters, afzonderlijke geschiedenisvermeldingen en HTTP 503 met succesvol opnieuw proberen. Lijstlengte en regelpositie exact hersteld, geen JavaScriptfouten. Native BFCache afzonderlijk bevestigd via `pageshow.persisted`, plus herladen zonder BFCache, gebruikersisolatie en positie binnen de eerste batch. Geintegreerde browser was onbereikbaar; validatie uitgevoerd met een geisoleerde lokale Edge-instantie en tijdelijke tooling, zonder productiedatabase of externe provider.

73. **Loginpagina gebruikt donker thema**
    De loginpagina liet `data-bs-theme` weg, waardoor Bootstrap standaard de lichte kleurmodus koos, ook al gebruikt de ingelogde applicatie standaard donker. De renderer zet de login nu expliciet op `dark`; gebruikerskeuzes voor Default, Plex Dark en Plex Light na login blijven ongewijzigd. README en Linux-serverchecks beschrijven het gedrag. Geen migratie, retrieve of versie-/codenaamwijziging.
    Validatie: 718 controles geslaagd, inclusief regressiecheck in `tests/run.php` die het dark-thema-attribuut op de loginpagina verifieert. PHP-syntaxcontrole geslaagd.

74. **Overzichtsnavigatie, retrievebediening en snellere eerste paginalaad**
    Op verzoek staat de enige zoekbalk in de header; de duplicate zoek-/filterbalk is verwijderd. Hoofdcategorieen staan in de linker sidebar boven de subcategorieen, actieve filters en wissen staan naast kolominstellingen. Voorkeuren en adminpanel zijn naar een username-menu verplaatst; de losse Spotsoverzicht-link is verwijderd. Admins kunnen handmatig `--since-last` starten. De voortgang komt uit de retrieval-runstatus; een databasegebonden MySQL/MariaDB advisory lock voorkomt parallelle handmatige/cron-runs. De laatste afgeronde run wordt als lokale datum/tijd getoond, ongeacht of het een cron- of handmatige run was. De status wordt na laden periodiek vernieuwd zodat een reeds geopende pagina ook een nieuwe cronrun detecteert.
    Performance-oorzaak: initiele rendering haalde al een keyset-batch op, maar daarnaast synchroon een exacte totaaltelling over de volledige gefilterde set; ook werden facetten berekend op de rootpagina waar ze niet werden weergegeven. Exacte totaaltelling is uit de request verwijderd en rootfacetten worden overgeslagen. Nieuwe batches blijven keyset-paginated en worden alleen bij scrollen opgehaald; filters blijven server-side gelijk. De browsebezoekgrens wordt nog steeds op rootbezoek vastgelegd.
    Geen schemawijziging of retrieve nodig. Validatie: volledige `composer test` (723 controles), PHP-syntax en JS parse/gedrag via browser op gesimuleerde statusantwoorden; visueel gecontroleerd op desktop en 320/390px zonder horizontale overflow. MySQL/MariaDB-integratietest niet uitgevoerd: `NNSPOTTY_TEST_DSN` is niet geconfigureerd.

## Open vragen (voor volgende stap)
- Welk framework past het best bij verdere uitbreiding? De huidige basis gebruikt PHP 8.2+ en PDO/MySQL/MariaDB.
- Welke metadata-provider biedt de beste betrouwbare TVDB/IMDb-ID-koppeling voor Newznab-vervolgfunctionaliteit?
- Welke moderatieregels gelden bij conflicten tussen whitelist en blacklist, na verificatie van de spotteridentiteit?
- Hoe versnellen we grote historische scans zonder de volledigheid van datumfilters te verliezen?

## Volgende stappen
1. TheTVDB op productie accepteren met eigen key/PIN/licentie en echte films/afleveringen; daarna klikbaar ontdekken op cast/crew/studio/netwerk en koppeling met Newznab-ID-zoeken uitwerken.
2. Newznab-MVP op productie testen met Prowlarr, Sonarr en de eigen Usenet-provider: titel-/categoriezoektocht en echte NZB-download.
3. SABnzbd op productie accepteren met echte categorienamen, persoonlijke publieke host en centrale interne host; daarna eventueel NZBGet en afgeronde downloadstatussen toevoegen.
4. Retentiebeheer uitbreiden met duidelijke begin-/einddatums en kalenderbediening, zonder onvolledige datumscans.
5. Kernmodules verdiepen:
   - usenet/indexing
   - nzb-forwarding
   - metadata-integratie
   - theming
   - newznab API
6. Handtekeningverificatie, geverifieerde spotteridentiteiten en moderatie in de retrieval-laag toevoegen.
7. Geteste compatibiliteit voor afwijkende legacy-XML uitwerken.
8. Grote backfills en commentretrieval optimaliseren; scheduling en jobs toevoegen.
9. Installatieflow verder uitwerken (wizard + validaties).
10. Metadata verbreden naar muziek (MusicBrainz), games (IGDB/RAWG), software (AppStream-catalogi) en boeken (Open Library), met domeinspecifieke matching/licentie/attributie en voorkeuren.

## Updateprotocol
Werk deze second brain bij bij:
- elke architectuurbeslissing;
- scopewijziging;
- versie-/codenaamwijziging;
- afronding van belangrijke milestones.
