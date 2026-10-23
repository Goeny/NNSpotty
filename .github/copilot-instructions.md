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
- Houd [CHANGELOG.md](../CHANGELOG.md) clean en beknopt (features, relevante fixes, security/performance).
- Gebruik [docs/cli-commands.md](../docs/cli-commands.md) voor CLI-documentatie.

## Privacybeleid

- Zet geen hostnames, gebruikersnamen, paden of secrets van private omgevingen in publieke docs.
- Bewaar private operationele notities lokaal in `docs/private/*.local.md`.
