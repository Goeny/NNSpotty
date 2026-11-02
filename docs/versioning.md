# Versioning en codenames

## SemVer beleid
We gebruiken Semantic Versioning:
- `MAJOR.MINOR.PATCH`
- Voor development starten we op `0.0.1`.
- De eerste stabiele productie-release wordt `1.0.0`.

## Codenaambeleid
Elke release krijgt een codenaam op basis van een interessante automodel-variant.

Voorbeelden van geschikte stijl:
- Delta Integrale
- Skyline GT-R
- M3 CSL
- 911 GT3 RS
- Alpine A110 S

## Actuele release
- **Versie:** `0.2.3`
- **Codenaam:** `Lancer Evo VI TME`
- **Status:** 0.x-patchrelease met metadata-queue via cron, universele cron-runner (retrieve + metadata), configureerbare backfill vanaf datum en metadata-entity-indexering voor snellere zoek-/Newznab-koppeling; nog geen 1.0-productierelease

## Eerdere releases
- `0.2.2` - Impreza 22B
- `0.2.1` - Escort RS Cosworth
- `0.2.0` - Sierra RS Cosworth
- `0.1.0` - Skyline GT-R

## Releaseadministratie
Alleen een expliciet gevraagde nieuwe release verandert versie **en** codenaam; reguliere commits veranderen die niet.

1. Pas de centrale runtimebron `src/Config/Release.php` aan, plus de versie in Composer, changelog en deze pagina. De runtime leest geen versie/codenaam uit `.env`.
2. Controleer tests en releasecode, maak een releasecommit en een tag `vMAJOR.MINOR.PATCH`.
3. Publiceer dezelfde releasecommit/tag en release notes op GitLab en [GitHub](https://github.com/Goeny/NNSpotty). Werk op dat moment ook de GitHub-standaardbranch bij tot de releasecommit, zodat nieuwe clones de actuele release krijgen.
4. GitLab ontvangt reguliere ontwikkelcommits; GitHub wordt alleen bij expliciete releases bijgewerkt. De noodzakelijke Git-voorgeschiedenis gaat daarbij mee met de release; losse ontwikkelpushes niet.
5. Maak op **beide** platformen een gepubliceerde Release, niet alleen een Git-tag. Verifieer de API en de codeversie. De updater volgt de origin van de installatie en installeert alleen stabiele gepubliceerde releases.
