# Second brain (publieke samenvatting)

Dit bestand blijft bewust kort en publiek.

## Productrichting

- Nieuwe, modulaire Spotnet-webapp op basis van php-nntp
- Eenvoudige installatie en beheer
- Focus op performance, onderhoudbaarheid en duidelijke UX
- Admin-first configuratie van runtime-instellingen

## Hoofdfunctionaliteiten

- Spots en reacties ophalen via NNTP
- Spot-overzicht met filters en detailpagina's
- NZB-download en SABnzbd-koppeling
- Newznab API
- Metadata-integraties (films/series/muziek)
- Posters en fanart van gekoppelde TVDB-/TMDB-records in aparte lightboxgalerijen; cover opent posters, media-icoon opent fanart. Alleen de bekeken afbeelding wordt geladen; hero-keuze blijft gelijk.
- Gebruikers, rollen, rechten en preferences

## Werkwijze

- README en changelog beknopt houden
- Consoleroutes gebruiken voor beheer en onderhoud
- Gevoelige operationele details alleen lokaal bewaren

## Private notities

Voor interne deploymentdetails en uitgebreide werknotities:

- [docs/private/README.md](./private/README.md)
