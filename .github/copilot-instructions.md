# NNSpotty – Copilot Instructions

## Doel

Werk aan NNSpotty als moderne, modulaire Spotnet-webapp op basis van php-nntp.

## Kernregels

- Houd wijzigingen gericht en functioneel.
- Gebruik MySQL/MariaDB als primaire datastore.
- Beheer runtime-instellingen via admin (niet primair via `.env`).
- Gebruik Bootstrap-componenten voor feedback/confirmaties.
- Gebruik geen browser-popups (`alert`, `confirm`, `prompt`).

## Documentatiebeleid

- Houd [README.md](../README.md) kort en simpel.
- Documenteer nieuwe functionaliteit beknopt in README en changelog; zet agent-/documentatie-instructies nooit in de publieke README.
- Houd [CHANGELOG.md](../CHANGELOG.md) clean en beknopt (features, relevante fixes, security/performance).
- Gebruik [docs/cli-commands.md](../docs/cli-commands.md) voor CLI-documentatie.

## Privacybeleid

- Zet geen hostnames, gebruikersnamen, paden of secrets van private omgevingen in publieke docs.
- Bewaar private operationele notities lokaal in `docs/private/*.local.md`.

## Releases en Git

- Reguliere commits/pushes gaan uitsluitend naar GitLab (`origin`). Verander daarbij versie en codenaam niet.
- Alleen wanneer de gebruiker expliciet een release vraagt: kies een nieuwe SemVer-versie en een nieuwe automodel-codenaam volgens [docs/versioning.md](../docs/versioning.md).
- `src/Config/Release.php` is de runtimebron voor versie/codenaam. Werk bij een release ook composer.json/composer.lock, versioning-documentatie en changelog consistent bij.
- Publiceer een release op zowel GitLab als GitHub: exact dezelfde releasecommit, releasetag (`vMAJOR.MINOR.PATCH`) en release notes. GitHub-repository: https://github.com/Goeny/NNSpotty.git.
- Werk alleen tijdens die release ook de GitHub-standaardbranch bij naar de releasecommit, zodat nieuwe GitHub-clones direct de laatste release krijgen.
- Push ontwikkelbranches/losse ontwikkelcommits niet tussentijds naar GitHub. Bij een release gaat de noodzakelijke Git-historie wel mee met de releasetag; GitHub is geen volledige ontwikkelmirror.
- Een tag alleen is niet voldoende: maak op beide platformen een gepubliceerde Release. De updater negeert drafts, prereleases, toekomstige releases en losse branchcommits.
- Maak/push geen release of nieuwe versie impliciet bij regulier werk. Verifieer beide gepubliceerde releases en rapporteer afzonderlijk als een platform faalt; gebruik nooit force-push als oplossing.
