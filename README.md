# JALabs_SymSearch — Semantic Hybrid Search for Magento 2

Adds semantic (vector) search to a Magento 2 catalog search results page as an
OpenSearch **hybrid** query (BM25 keyword + k-NN over cloud-API embeddings),
layered on top of **Mirasvit Search Ultimate**. Semantic is always an
enhancement: any embedding failure falls back to stock keyword search
automatically, so search never gets *worse*.

- **Natural-language queries** — "gift for a 7 year old who loves space" → telescopes & astronomy books.
- **Zero-result rescue** — paraphrases that share no tokens with product titles still match.
- **Cross-language** — French/Arabic queries against English product data.

Module name: `JALabs_SymSearch` · Composer package: `jalabs/module-sym-search`

## Compatibility

| | |
|---|---|
| Magento | 2.4.x (developed on 2.4.8-p2 Commerce) |
| PHP | 8.1 – 8.4 |
| Search engine | OpenSearch 3.x with `opensearch-knn` + `opensearch-neural-search` plugins |
| Companion | Mirasvit Search Ultimate (tested 2.6.x) — recommended, not strictly required |
| Queue | RabbitMQ (`amqp` connection in `env.php`) |
| Embeddings | OpenAI, Google Gemini, or Voyage AI (API key) |

## Installation

### Option A — Composer (recommended)

Add the repository to your Magento project's root `composer.json`, then require it:

```bash
composer config repositories.symsearch vcs https://github.com/JohnnyAbdelnour/symsearch.git
composer require jalabs/module-sym-search:dev-main
bin/magento module:enable JALabs_SymSearch
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode only
```

### Option B — Manual (app/code)

Clone (or copy) the repository contents into `app/code/JALabs/SymSearch/`:

```bash
git clone https://github.com/JohnnyAbdelnour/symsearch.git app/code/JALabs/SymSearch
bin/magento module:enable JALabs_SymSearch
bin/magento setup:upgrade
```

> The repository root **is** the module root (`registration.php` at the top
> level) — the standard Composer `magento2-module` layout.

After install, follow **Deploy / rollout order** below.

## Documentation

- [`docs/USER-GUIDE.md`](docs/USER-GUIDE.md) — admin configuration, the indexing
  lifecycle, CLI tools, first-time backfill, rate-limit guidance, day-2
  operations, the relevance suite, and troubleshooting. (PDF: `docs/USER-GUIDE.pdf`)
- [`docs/TECHNICAL.md`](docs/TECHNICAL.md) — architecture, how it plugs into the
  Magento/Mirasvit/OpenSearch stack, the data model, query-rewrite internals,
  data flows, the failure model, and the extension-point reference.
  (PDF: `docs/TECHNICAL.pdf`)

## Requirements at runtime
- OpenSearch 3.x with `opensearch-knn` + `opensearch-neural-search` (verified by `symsearch:pipeline:setup`).
- RabbitMQ (`amqp` connection in `env.php`) — the embedding pipeline is inert without it.
- An embedding API key (OpenAI / Gemini / Voyage) configured in admin.
- Note: Anthropic/Claude has no embeddings API — Anthropic officially recommends Voyage AI, the "Claude ecosystem" option here.

## Configuration (Stores → Configuration → JALabs → Semantic Search)
| Path | Default | Notes |
|---|---|---|
| symsearch/general/enabled | 0 | Master switch: index mapping, vectors, queue (default scope) |
| symsearch/general/storefront_enabled | 0 | Hybrid query on results page (per store view) |
| symsearch/general/debug | 0 | Verbose logging |
| symsearch/provider/type | openai | openai \| gemini \| voyage |
| symsearch/provider/api_key | — | Encrypted |
| symsearch/provider/model | text-embedding-3-small | gemini: gemini-embedding-001 \| voyage: voyage-3.5-lite |
| symsearch/provider/dimensions | 512 | Change requires full re-embed + reindex |
| symsearch/indexing/attributes | name,short_description,description | Attribute codes to embed |
| symsearch/indexing/batch_size | 96 | Texts per API call |
| symsearch/indexing/throttle_ms | 2000 | Pause between API calls (rate-limit pacing) |
| symsearch/ranking/keyword_weight | 0.7 | Global (one shared pipeline); run pipeline:setup after change |
| symsearch/ranking/semantic_weight | 0.3 | Global; run pipeline:setup after change |
| symsearch/ranking/knn_k | 100 | Per store view |
| symsearch/ranking/min_score | 0 | >0 replaces k-based retrieval with score floor |
| symsearch/ranking/query_timeout_ms | 800 | Min 100ms enforced |

## CLI
- `symsearch:embed:generate [--store=ID] [--limit=N] [--force]` — seed + queue embedding generation
- `symsearch:embed:status` — coverage per store
- `symsearch:pipeline:setup` — create/update the OpenSearch normalization pipeline + verify engine plugins
- `symsearch:relevance:run <csv> [--store=ID] [--suggest]` — relevance regression suite

## Admin panel (Operations)
**Stores → Configuration → JALabs → Semantic Search → Operations** — admin equivalents of the
CLI, for operators without shell access. Shows a per-store coverage table (pending/queued/
ready/failed/%), the active model version, and pipeline/plugin health, plus four async buttons:
**Refresh status**, **Generate embeddings** (gap-fill), **Force re-embed** (paid full re-embed;
confirms, then invalidates `catalogsearch_fulltext` — reindex once coverage recovers), and
**Refresh / verify pipeline**. Buttons only queue work; the consumer/cron processes it. A
persistent admin banner appears when embedding settings (attributes/model/dimensions) drift
from what was last embedded; both the panel's Force re-embed and `symsearch:embed:generate
--force` clear it. See `docs/USER-GUIDE.md` for details.

## Deploy / rollout order
1. Install + `setup:upgrade` (creates tables + queue), keep `enabled=0`.
2. Set provider API key (admin), set `symsearch/general/enabled=1`.
3. `symsearch:pipeline:setup`.
4. `indexer:reindex catalogsearch_fulltext` (adds the knn_vector mapping).
5. `symsearch:embed:generate` then run the consumer `queue:consumers:start jalabsSymsearchEmbed`
   (Magento's `consumers_runner` cron also starts it; supervisor only needed for high throughput).
6. Another `indexer:reindex catalogsearch_fulltext` once coverage is high (injects vectors).
7. Record the zero-result baseline; enable `storefront_enabled` on ONE store view.
8. Tune weights via the relevance suite; expand to all store views.

Rollback: set `storefront_enabled=0` (instant, no reindex needed).

## Operations
- Embeddings are stored in `jalabs_symsearch_vector` keyed by `sha1(text)+model_version` —
  reindexes never call the API; unchanged products and text shared across store views are
  never re-embedded.
- Product saves mark items stale (observer); imports/API writes are caught by the nightly
  sweep cron (compares product `updated_at`). The dispatch cron queues pending items every 5 min.
- Failed items retry up to 5 attempts (nightly); `symsearch:embed:status` shows failed counts.
- Model/dimension change: update config, `symsearch:embed:generate --force`, full reindex.

## OpenSearch 3.3 note
OpenSearch 3.3.2 + freshly-written Lucene 10.3 segments can crash on Mirasvit's
case-insensitive wildcard clauses under concurrent segment search
(`array_index_out_of_bounds` / "points[i] is null"). The module's
`EnableKnnIndexSettingPlugin` sets `index.search.concurrent_segment_search.mode: none`
on the product indices so rebuilds keep it off. This affects all searches on
rebuilt indices, independent of this module.

## License
[OSL-3.0](LICENSE) — Open Software License 3.0 (the standard Magento module license).
