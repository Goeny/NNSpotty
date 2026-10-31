<p align="center">
  <img src="./public/assets/images/logo.png" alt="NNSpotty logo" width="320">
</p>

# NNSpotty

NNSpotty is een moderne Spotnet-webapplicatie voor het ophalen, browsen en downloaden van spots via Usenet.

## Functionaliteiten

- Spots en reacties ophalen via NNTP (php-nntp)
- Spot-overzicht met zoeken, filters en detailpagina’s
- Newznab API ondersteuning
- NZB downloaden en versturen naar SABnzbd of NZBGet: kies in Voorkeuren je standaard downloadclient, of eenmalig een andere via "Verzenden naar..."; wachtrij en snelheid in het Downloads-paneel
- Metadata-koppelingen (o.a. TMDB, TheTVDB, MusicBrainz, Fanart)
- Poster- en fanartgalerijen bij films/series: klik de cover voor overige posters, of het media-icoon naast de trailer voor fanart. Bladeren met knoppen, pijltjestoetsen of swipen; afbeeldingen laden pas bij bekijken.
- Admin beheerpanel voor systeeminstellingen
- Release-updater via CLI en webinterface, met releasemelding in de sidebar en afzonderlijk groepsrecht
- Gebruikers, rollen, rechten en persoonlijke preferences
- Persoonlijke historie met tabs Bekeken/Gedownload (SABnzbd, NZBGet, Newznab API en NZB-bestanden); geaggregeerde statistieken met grafieken achter het groepsrecht `statistics.view`
- Spotter moderatie (whitelist / blacklist)
- Gecontroleerde Spotnet-ID’s naast spotter- en reactienamen; reacties publiceren met een persoonlijke accountsleutel en Spotweb-compatibele message-ID-handtekening
- Light en dark thema (Plex-geïnspireerde UX)

## Vereisten

- PHP 8.2+
- MySQL 8.0+ of MariaDB 10.6+
- Composer
- PHP extensies: curl, dom, iconv, mbstring, openssl, pdo_mysql, simplexml, zlib

## Installatie (simpel)

1. Clone het project en ga naar de map.
2. Installeer dependencies met Composer.
3. Kopieer `.env.example` naar `.env` en vul je database + app settings in.
4. Draai de migraties.
5. Open de webapp.

Zie [docs/installation.md](./docs/installation.md) voor uitgebreide installatiehulp.

## CLI commando’s

Alle consolecommando’s staan in [docs/cli-commands.md](./docs/cli-commands.md).

## Externe resources

- [rvdv/nntp](https://packagist.org/packages/rvdv/nntp) (Usenet/NNTP)
- [Bootstrap](https://getbootstrap.com/) (UI)
- [TMDB](https://www.themoviedb.org/), [TheTVDB](https://thetvdb.com/), [MusicBrainz](https://musicbrainz.org/), [Fanart.tv](https://fanart.tv/) (metadata)
- [SABnzbd API](https://sabnzbd.org/wiki/advanced/api) (NZB forwarding)
- [NZBGet JSON-RPC API](https://nzbget.com/documentation/api/) (NZB forwarding)
