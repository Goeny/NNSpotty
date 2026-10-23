# Richtlijnen voor de nieuwe NNSpotty webapplicatie

Deze richtlijnen zijn leidend voor ontwerp, implementatie en validatie.

## Functionele en producteisen
1. **Makkelijk te installeren voor iedereen**  
   - Heldere setup flow.
   - Realistische defaults.
   - Minimaliseer handmatige configuratie.

2. **Makkelijk in gebruik voor iedereen**  
   - Intuïtieve navigatie.
   - Duidelijke informatie-architectuur.
   - Korte leercurve voor nieuwe gebruikers.

3. **Theming moet mogelijk zijn**  
   - Thema-laag loskoppelen van businesslogica.
   - Ondersteuning voor meerdere thema’s.
   - Voorbereid op community-thema’s.

4. **Verbinding met Usenet + spots indexeren**  
   - Integratie via php-nntp.
   - Betrouwbare indexing pipeline.
   - Herstartbare jobs en foutafhandeling.

5. **NZB direct doorsturen naar externe applicaties**  
   - Ondersteun minimaal SABnzbd en NZBGet.
   - Configurabele endpoints en API keys.
   - Duidelijke statusfeedback bij verzending.

6. **Inzetbaar als Newznab provider**  
   - API-compatibele endpoints.
   - Beheerbare toegang en rate limiting.
   - Duidelijke API-documentatie.

7. **Modern en snel**  
   - Modulaire architectuur.
   - Efficiënte caching en query-strategieën.
   - Performance als vaste kwaliteits-eis.

8. **Metadata via IMDB en/of TVDB**  
   - Provider-agnostische metadata-laag.
   - Fallback tussen bronnen waar mogelijk.
   - Opslag en vernieuwing van metadata beheersbaar maken.

9. **Standaardthema met Plex-invloeden**  
   - Grote cover images.
   - Fanart als achtergrond.
   - Duidelijke informatieblokken.
   - Badges voor extra informatie.
   - Referenties: [docs/screenshots/](./screenshots/).

10. **Second brain bijhouden**  
   - Beslissingen, rationale, open vragen en vervolgstappen vastleggen.
   - Continu actualiseren bij elke substantiële stap.

11. **Versioning + codenames**  
   - Development start op **0.0.1**.
   - Final release target: **1.0.0**.
   - Elke versie krijgt een codenaam met automodel-variant.

12. **Admin beheerpanel verplicht**  
   - Centrale plek voor systeembeheer.
   - Minimaal Usenet settings beheren (retentie, server, login).
   - Uitleesbare status/feedback bij opslaan van instellingen.
   - Runtime operationele instellingen (Usenet/retrieve) horen hier thuis, niet primair in `.env`.

13. **Username-gebaseerde user preferences**  
   - Persoonlijke instellingen per gebruiker.
   - Los van globale adminconfiguratie.

14. **Spotter whitelist/blacklist**  
   - Beheer van toegestane en geblokkeerde spotters als moderatie-optie.
   - Standaard trust-model: niet-whitelisted spotters zijn niet automatisch geblokkeerd.
   - Whitelist alleen inzetten bij twijfelachtige bronnen; blacklist bij concrete problemen.

15. **Comments op posts**  
   - Gebruikers moeten comments op posts kunnen plaatsen.
   - Commentweergave moet context (post + auteur + tijd) tonen.

16. **Alle data in database opslaan**  
   - Geen file-based opslag als primaire datastore.
   - Voorkeur engine: MySQL/MariaDB.

17. **Console-commando's voor belangrijke taken**  
   - `bin/console retrieve` met opties zoals:
     - aantal dagen;
     - alles;
     - sinds laatste retrieve.
   - `bin/console user` met taken zoals:
     - create;
     - delete;
     - edit;
     - password.
   - `bin/console db` met functies zoals:
     - migrate;
     - status;
     - health;
     - cleanup.
   - Ruimte voor aanvullende commandgroepen.

18. **Server testinstructies bij wijzigingen**  
   - Bij elke wijziging moeten concrete Linux testcommando's worden meegegeven.
   - Gebruik in publieke documentatie altijd generieke hostnames en paden.

## Niet-functionele uitgangspunten
- Vermijd legacy-mix van oude en moderne patronen.
- Code moet onderhoudbaar, testbaar en modulair zijn.
- UX-feedback via Bootstrap componenten; geen browser popups.
- Houd `README.md` kort, praktisch en eenvoudig.
- Houd `CHANGELOG.md` beknopt en alleen op hoofdlijnen (features, relevante fixes, security/performance).
