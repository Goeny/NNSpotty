# NNSpotty Second Brain

Doel: centrale contextbron voor keuzes, aannames, besluiten en volgende stappen.

## Snapshot
- **Datum:** 2026-10-18
- **Versie:** 0.0.1
- **Codenaam:** Delta Integrale
- **Fase:** Database/console/auth-fundament met echte Spotnet-index en spotdetailweergave

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

## Open vragen (voor volgende stap)
- Welk framework past het best bij verdere uitbreiding? De huidige basis gebruikt PHP 8.2+ en PDO/MySQL/MariaDB.
- Welke metadata-provider krijgt prioriteit: IMDB, TVDB of beide vanaf start?
- Welke Newznab endpoint-scope is minimaal vereist voor MVP?
- Welke moderatieregels gelden bij conflicten tussen whitelist en blacklist, na verificatie van de spotteridentiteit?
- Hoe versnellen we grote historische scans zonder de volledigheid van datumfilters te verliezen?

## Volgende stappen
1. Beslissen over definitieve stackkeuzes (framework ja/nee, DB, queue/caching).
2. Kernmodules verdiepen:
   - usenet/indexing
   - nzb-forwarding
   - metadata-integratie
   - theming
   - newznab API
3. Handtekeningverificatie, geverifieerde spotteridentiteiten en moderatie in de retrieval-laag toevoegen.
4. Veilige Spotnet-opmaak en geteste compatibiliteit voor afwijkende legacy-XML uitwerken.
5. Grote backfills en commentretrieval optimaliseren; scheduling en jobs toevoegen.
6. Installatieflow verder uitwerken (wizard + validaties).

## Updateprotocol
Werk deze second brain bij bij:
- elke architectuurbeslissing;
- scopewijziging;
- versie-/codenaamwijziging;
- afronding van belangrijke milestones.
