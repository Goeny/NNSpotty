# Metadata provider request limits

Dit document bevat alleen limieten die publiek verifieerbaar zijn in officiële providerdocumentatie.

## TMDB (The Movie Database)

- Officiële documentatie zegt dat de legacy-limiet (40 requests per 10 seconden) is uitgezet.
- Tegelijk geeft TMDB aan dat er nog een bovengrens bestaat “somewhere in the 40 requests per second range”.
- Conclusie: **geen harde, vaste publieke limiet**, maar praktisch rond ~40 req/s met kans op `429`.

Bron: https://developer.themoviedb.org/docs/rate-limiting

## MusicBrainz

- MusicBrainz geeft aan dat ze throttlingregels kunnen wijzigen.
- Huidige gepubliceerde IP-regel: gemiddeld **1 request per seconde per bron-IP** (tenzij anders afgesproken).
- Bij overschrijding kan de service requests afwijzen met `503 Service Unavailable`.

Bron: https://musicbrainz.org/doc/MusicBrainz_API/Rate_Limiting

## TheTVDB

- In de publieke V4 API docs/README is **geen exact numeriek request-limit** opgenomen.
- Wel adviseren ze sterk om lokaal te cachen/proxyen en niet onnodig direct tegen de API te leunen.
- Conclusie: **exacte limiet niet publiek gespecificeerd** in de openbare API-documentatie.

Bronnen:
- https://thetvdb.github.io/v4-api/
- https://github.com/thetvdb/v4-api

## Fanart.tv

- De publiek opvraagbare API Blueprint/documentatie beschrijft authenticatie en endpoints, maar bevat **geen expliciete numerieke rate-limit**.
- Conclusie: **exacte limiet niet publiek gespecificeerd** in de opgehaalde openbare API-documentatie.

Bron:
- https://fanarttv.docs.apiary.io/api-description-document

## Praktische vervolgstap voor NNSpotty

Omdat niet elke provider een vaste publieke limiet publiceert:

1. Hanteer conservatieve default-limieten in NNSpotty.
2. Respecteer `429`/`503` responses met backoff.
3. Maak provider-limieten configureerbaar in admin.
4. Her-verifieer deze pagina periodiek; providers kunnen limieten wijzigen.

## NNSpotty policy (aanbevolen defaults)

Onderstaande defaults zijn bewust conservatief en bedoeld als veilige startwaarden per installatie.

| Provider | Startlimiet (default) | Gedrag bij limietfout |
|---|---:|---|
| TMDB | 120 requests/min (runtime gepaced op minimaal ~0.55s tussen calls) | Bij `429`: exponential backoff met jitter, daarna retry |
| TheTVDB | 60 requests/min (runtime gepaced op minimaal ~1.1s tussen calls) | Bij `429`/`503`: exponential backoff met jitter, daarna retry |
| MusicBrainz | 1 request per seconde (runtime gepaced op minimaal ~1.25s tussen calls) | Bij `503`: vaste vertraging + retry met oplopende wachttijd |
| Fanart.tv | 30 requests/min | Bij `429`/`503`: exponential backoff met jitter, daarna retry |

### Backoff-richtlijn

- Maximaal 5 retries per request.
- Basisvertraging: 1 seconde, daarna verdubbelen (1s, 2s, 4s, 8s, 16s).
- Jitter: +0–250ms om pieken te spreiden.
- Respecteer `Retry-After` header als die aanwezig is.

### Admin-instellingen (per provider)

- Enable/disable provider
- Requests per minute (of per second waar relevant)
- Retry count
- Basis backoff (ms)
- Max backoff (ms)
- Cache TTL succes/fout

## Implementatie-roadmap (M1–M5)

Doel: metadata alleen voor **nieuwe** spots ophalen via cron, en daarna gecontroleerd inzetten voor UI, zoekkwaliteit en Newznab.

### M1 — Queue + cronfundament

**Scope**
- Metadata queue introduceren met statussen (`pending`, `processing`, `done`, `failed`, `retry_at`).
- Nieuwe spots schrijven alleen een queue-item weg (geen zware live metadata-calls in retrieve-pad).
- Cron worker verwerkt kleine batches met locks en retries.
- Harde “alleen nieuwe spots”-grens instellen (feature startdatum of start spot-id).

**Acceptatiecriteria**
- Retrieve-performance blijft stabiel; nieuwe spots worden nog steeds normaal geïndexeerd.
- Queue groeit alleen door nieuwe spots, niet door historische data.
- Cron kan veilig parallel gestart worden zonder dubbele verwerking.
- Fouten leiden tot retry/dead-letter gedrag, niet tot stilvallen.

### M2 — Basis metadata-inname + opslagmodel

**Scope**
- Conservatieve provider-calls via queue worker met rate-limits/backoff conform policy.
- Opslag van kernvelden: titel, jaar, samenvatting, genres, runtime, artwork, externe IDs.
- Matchkwaliteit en `last_synced_at` vastleggen.

**Acceptatiecriteria**
- Metadata van nieuwe spots wordt reproduceerbaar en idempotent opgeslagen.
- Geen provider flood: limieten worden gerespecteerd (`429`/`503` afgehandeld).
- Records bevatten bron/provider en sync-tijd voor auditability.

### M3 — UI-verrijking (overzicht, quick details, detail)

**Scope**
- Overzicht: poster/jaar/genre-badges waar metadata beschikbaar is.
- Quick details: compacte metadata-samenvatting.
- Detailpagina: volledige metadata-blokken.
- Fallback naar bestaande spotdata als metadata ontbreekt.

**Acceptatiecriteria**
- Geen regressie in pagina-laadtijden.
- UI blijft volledig bruikbaar zonder metadata.
- Metadataweergave is consistent tussen overview/quick/detail.

### M4 — Zoekverbetering binnen NNSpotty

**Scope**
- Zoekindex uitbreiden met metadata-velden (aliases, cast, crew, studio, year, genres).
- Ranking-boost op sterke metadata-match.
- Geen live provider-calls tijdens user search.

**Acceptatiecriteria**
- Meetbare verbetering op relevante zoekcases (betere topresultaten).
- Zoeklatency blijft binnen bestaande performance-doelen.
- Ranking blijft voorspelbaar met duidelijke fallback op spottekst.

### M5 — Newznab-verrijking + klikbare entities

**Scope**
- Newznab-resultaten verrijken met lokaal beschikbare metadata/external IDs.
- Klikbare entities (acteur, regisseur, studio) met overzicht “waar komt dit in voor”.
- Entity-pagina’s bouwen op lokale index, niet op live provider-calls.

**Acceptatiecriteria**
- Newznab-responses blijven snel en stabiel.
- Entity-navigatie werkt consistent en levert relevante gekoppelde items.
- Geen extra load spikes op providers door API-verkeer van clients.

## Operationeel plan

### Cronadvies
- Frequentie: elke 1–5 minuten.
- Batch: klein beginnen (bijv. 25) en opschalen op basis van metrics.
- Prioriteit: newest-first, met retry op basis van `retry_at`.

### Monitoring/KPI’s
- Queue depth
- Throughput (items/min)
- Succesratio per provider
- Retryratio en dead-letter volume
- Gemiddelde verwerkingstijd per item

### Guardrails

- Feature flag voor globale metadata-ingest.
- Provider toggles per bron.
- “Pauze ingest” knop in admin voor incidenten.
- Handmatige “requeue laatste 24 uur” (niet ouder) voor veilig herstel.

## Implementatiestatus

- Queue + cron-ingest voor metadata is beschikbaar via:
  - `php bin/console metadata enable --batch=25 --retries=5`
  - `php bin/console metadata run --limit=25`
  - `php bin/console metadata status`
- Alleen spots die **na activatie** binnenkomen worden gequeued (geen automatische backfill van oude spots).
- Voor uitzonderingen is er een **admin-only backfilloptie**: tijdelijk oudere spots queueen met een instelbare oudste datumgrens.
- In admin is een metadata-activiteitsoverzicht beschikbaar (laatste 100 events) met bron (cron/handmatig/auto bij detail-open), status, provider en tijdstip.
- Bij status **ambiguous** is de reden compact uitklapbaar in de statuskolom.
