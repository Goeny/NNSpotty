# NNSpotty Second Brain

Doel: centrale contextbron voor keuzes, aannames, besluiten en volgende stappen.

## Snapshot
- **Datum:** 2026-10-17
- **Versie:** 0.0.1
- **Codenaam:** Delta Integrale
- **Fase:** Documentatie + scaffold
- **Fase:** Documentatie + scaffold + database/console fundament

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

## Open vragen (voor volgende stap)
- Welke PHP-versie en framework-keuze passen het best bij performance + onderhoudbaarheid?
- Welke database-engine wordt primair ondersteund bij de eerste release?
- Welke authenticatie/rollen zijn minimaal nodig voor 0.x?
- Welke metadata-provider krijgt prioriteit: IMDB, TVDB of beide vanaf start?
- Welke Newznab endpoint-scope is minimaal vereist voor MVP?
- Hoe richten we minimale auth/rollen in voor admin versus reguliere gebruikers?
- Welke moderatieregels gelden bij conflicten tussen whitelist en blacklist?
- Welke retrieve-strategie krijgt prioriteit (alles/sinds-laatste/per-retentie window)?

## Volgende stappen
1. Beslissen over definitieve stackkeuzes (framework ja/nee, DB, queue/caching).
2. Kernmodules verdiepen:
   - usenet/indexing
   - nzb-forwarding
   - metadata-integratie
   - theming
   - newznab API
3. Datalaag migreren van JSON prototype naar database-gedreven opslag (MySQL/MariaDB).
4. Console-commando's opzetten (`retrieve`, `user`, `db`) voor beheer en operatie.
5. Auth/rolscheiding toevoegen tussen adminfuncties en gewone gebruikers.
6. Installatieflow verder uitwerken (wizard + validaties).

## Updateprotocol
Werk deze second brain bij bij:
- elke architectuurbeslissing;
- scopewijziging;
- versie-/codenaamwijziging;
- afronding van belangrijke milestones.
