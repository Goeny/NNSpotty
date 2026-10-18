# NNSpotty – Copilot Instructions

## Projectdoel
NNSpotty is een volledig nieuwe webapplicatie (geen voortbouw op legacy Spotweb-code) met focus op:
- eenvoudige installatie;
- eenvoudige gebruikerservaring;
- moderne, snelle architectuur;
- duidelijke uitbreidbaarheid.

## Technische basis
- Gebruik **php-nntp** voor Usenet-koppeling en indexing van spots.
- Bouw modulair met duidelijke domeinlagen (indexing, metadata, theming, Newznab API, NZB forwarding).
- Vermijd legacy patronen en mixed paradigms.
- Sla applicatiedata op in een database (voorkeur: MySQL/MariaDB), niet in losse bestanden.

## UI/UX-richtlijnen
- Gebruik Bootstrap-componenten voor bevestigingen en feedback:
  - bevestigen via modals;
  - feedback via alerts, modals of toasts.
- Geen browser-popups (`alert`, `confirm`, `prompt`).

## Productrichtlijnen (samenvatting)
1. Makkelijk te installeren.
2. Makkelijk in gebruik.
3. Theming mogelijk.
4. Usenet-verbinding + spots indexeren.
5. NZB direct forwarden (o.a. SABnzbd/NZBGet).
6. Inzetbaar als Newznab provider.
7. Modern en snel.
8. IMDB/TVDB metadata-integratie.
9. Standaardthema met Plex-invloeden.
10. Second brain onderhouden.
11. Versioning + codenames hanteren.
12. Admin beheerpanel met centrale systeeminstellingen.
13. Gebruikersspecifieke preferences op username-basis.
14. Spotter whitelist/blacklist beheer.
15. Commentaar op posts ondersteunen.
16. Console-commando's voor kernbeheer leveren (`bin/console`).

## Verplichte beheerfuncties
- Admin panel moet minimaal beheren:
  - Usenet host/port/login;
  - retentie;
  - overige kerninstellingen voor indexing.
- User preferences zijn gekoppeld aan username en staan los van globale settings.
- Moderatie bevat whitelist/blacklist van spotters.
- Posts moeten comments ondersteunen.
- Databasebeheer moet minimaal via console ondersteunen:
  - `bin/console db migrate`
  - `bin/console db status`
  - `bin/console db health`
  - `bin/console db cleanup`
- Userbeheer moet minimaal via console ondersteunen:
  - `bin/console user create|delete|edit|password|list`
- Retrieve moet minimaal via console ondersteunen:
  - `bin/console retrieve --days=<n>`
  - `bin/console retrieve --all`
  - `bin/console retrieve --since-last`

## Werkwijze bij wijzigingen
- Documenteer elke nieuwe of gewijzigde feature in [`README.md`](../README.md) als onderdeel van dezelfde wijziging. Houd functionaliteiten, configuratie, installatie- en retrieve-commando's, beperkingen en gebruikte third-party applicaties/code/tools met links actueel; presenteer geplande functies niet als reeds beschikbaar.
- Als er wijzigingen zijn, lever altijd concrete Linux server-commando's mee voor testen op `nnspotty.hopseflop.nl`.
- Zodra aanpassingen gereed en gevalideerd zijn, commit en push de taakgerelateerde wijzigingen naar de huidige Git-branch zonder een apart verzoek af te wachten.
- Neem geen ongerelateerde wijzigingen of secrets mee. Gebruik geen force-push; meld expliciet als committen of pushen niet lukt.

## Versioning en codenaambeleid
- Start development op **0.0.1**.
- Final release wordt **1.0.0**.
- Elke release krijgt een codenaam op basis van (interessante) automodel-varianten.
- Huidige codenaam: **0.0.1 “Delta Integrale”**.

## Second brain beleid
- Houd [`docs/second-brain.md`](../docs/second-brain.md) continu up-to-date.
- Werk minimaal bij op:
  - beslissingsmomenten;
  - scopewijzigingen;
  - release-/versie-updates.
