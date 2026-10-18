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
