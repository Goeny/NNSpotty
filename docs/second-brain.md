# Second brain (publieke samenvatting)

Dit bestand blijft bewust kort en publiek.

## Productrichting

- Nieuwe, modulaire Spotnet-webapp op basis van php-nntp
- Eenvoudige installatie en beheer
- Focus op performance, onderhoudbaarheid en duidelijke UX
- Admin-first configuratie van runtime-instellingen

## Hoofdfunctionaliteiten

- Spots en reacties ophalen via NNTP
- Reactieblok toont alleen lijst en schrijfknop; schrijven/citeren opent dezelfde ruime modal. Concepten blijven bewaard bij sluiten; publicatiefouten heropenen de modal. Korte toestemming voor publieke Usenet-publicatie blijft vereist.
- Spot-overzicht met filters en compacte hero-preview (poster/fanart, kerninformatie, maximaal drie synopsisregels en dezelfde kleine kwaliteitsbadges als de tabel); kleine acties boven de hero, bronvermelding en maximaal acht acteurs eronder. Uitgebreide informatie uitsluitend op de detailpagina.
- NZB-download en SABnzbd-koppeling
- Newznab API
- Metadata-integraties (films/series/muziek)
- Uitgebreide details: regie naast compacte video/audio-informatie; spotgegevens naast TMDB-status/taal/budget/opbrengst; studio's naast trefwoorden. Regie/trefwoorden/status/taal gebruiken metadata-entityfilters, titels en maximaal twaalf TMDB-suggesties boven reacties starten lokale titelzoekopdrachten. Suggesties garanderen geen lokale beschikbaarheid. Bestaande verse matches worden bij openen eenmalig aangevuld via hetzelfde provider-ID, zonder opnieuw op titel te matchen; geen schemawijziging.
- Metadataqueue: nieuwste spotdatum eerst; errors met backoff en dagpauze, unmatched/ambiguous met instelbare herbeoordeling. Admin beheert extra letterlijke herkennings-/opschoonregels per type, alleen-lezen ingebouwde regels, bulkruns voor de volgende cron en live runstatus. Handmatige koppelingen/blokkeringen blijven behouden.
- Posters en fanart van gekoppelde TVDB-/TMDB-records in aparte lightboxgalerijen; cover opent posters, media-icoon opent fanart. Alleen de bekeken afbeelding wordt geladen; hero-keuze blijft gelijk.
- Gebruikers, rollen, rechten en preferences
- Persoonlijke bekijk-/downloadhistorie en geaggregeerde gebruiksstatistieken met afzonderlijk groepsrecht
- Releasegerichte CLI-/GUI-updater met apart groepsrecht, onderhoudsmodus en exacte releasetags; ontwikkelcommits blijven op GitLab, expliciete releases worden ook op GitHub gepubliceerd

## Werkwijze

- README en changelog beknopt houden
- Consoleroutes gebruiken voor beheer en onderhoud
- Gevoelige operationele details alleen lokaal bewaren

## Private notities

Voor interne deploymentdetails en uitgebreide werknotities:

- [docs/private/README.md](./private/README.md)
