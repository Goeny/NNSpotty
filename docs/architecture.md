# Architectuur (scaffold v0.0.1)

## Principes
- **Modulair per capability** (Usenet, NZB-forwarding, metadata, theming, Newznab API, admin, user preferences, moderatie, comments).
- **Eenvoudige start** zonder zware afhankelijkheden in de eerste fase.
- **Expliciete configuratie** via environment variabelen.

## Initiële mappenstructuur
- `public/`  
  HTTP entrypoint en publieke assets.
- `src/Config/`  
  Appconfig en configuratielogica.
- `src/Http/`  
  Router/response basis voor endpoints.
- `src/UI/`  
  Initiële rendering van frontend (Plex-geïnspireerde basisopmaak).
- `src/Usenet/`  
  Integratiepunten voor php-nntp.
- `src/Database/`  
  DB connectie en migraties (MySQL/MariaDB).
- `src/Users/`  
  Userbeheer voor console workflows.
- `bin/`  
  Console entrypoints (`bin/console`).
- `.vscode/`  
  Teamtaken, launch en extensie-aanbevelingen.

## Componenten in deze fase
1. **HTTP basis**
   - `GET /` Homepagina.
   - `GET /health` health endpoint met versie/codenaam.
   - `GET /admin` + `POST /admin/*` voor eerste adminbeheer.
   - `GET /preferences` + `POST /preferences` voor username-based preferences.

2. **Usenet basis**
   - `NntpClientFactory` bouwt een `rvdv/nntp` client op basis van env-config.

3. **Persistence basis**
   - MySQL/MariaDB opslag voor:
     - admin usenet settings;
     - user preferences;
     - spotter whitelist/blacklist;
     - post comments.
     - admin audit logging;
     - usenet post index (met categorie + newsgroup) en retrieval cursors.
   - Migraties via `bin/console db migrate`.

4. **UI baseline**
   - Bootstrap-gebaseerde pagina met duidelijke infoblokken en badges.
   - Fanart-achtige achtergrond + grote cover visual voor Plex-invloed.

## Volgende architectuurstappen
1. Introduceren van service-laag en use-cases per domein.
2. Toevoegen van persistence-laag (spots, indexstatus, gebruikersinstellingen, adminconfiguratie, moderatie en comments).
3. Queue/job infrastructuur voor indexing en metadata refresh.
4. API-laag voor Newznab compatibiliteit.
5. Rolscheiding tussen adminfuncties en gebruikersfuncties.
6. Retrieve pipeline koppelen aan echte NNTP indexing flow.
