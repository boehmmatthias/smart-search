# Changelog

## [Unreleased]

### Added

- `VectorCodec` — pack/unpack extracted from `VectorRepository` so the float32 round trip is
  testable without a database.
- **Upgrade wizard `smartSearchMigrateJsonVectorsToPackedFloat32`** — converts vectors stored by
  0.1.0 as JSON text into packed float32 binary. **Required when upgrading from 0.1.0; search
  returns nothing until it is run, and re-indexing cannot repair the rows because `content_hash`
  still matches.** The conversion is a pure re-encode: no embedding server is needed, nothing is
  re-embedded, and it is safe to re-run. On PostgreSQL it also performs the `vector` column type
  change, which Doctrine cannot express (`ALTER COLUMN … TYPE BYTEA` needs a `USING` clause).
  Run via `vendor/bin/typo3 upgrade:run smartSearchMigrateJsonVectorsToPackedFloat32` or the
  Install Tool.

### Fixed

- **Breaking:** rerankers no longer rewrite `score`. `LlmReranker` replaced each candidate's cosine
  similarity with `1.0 / (rank + 1)`, which silently changed what `score` means: the documented
  consumer pattern filters on `semanticThreshold` (0.30), and rank-derived values drop below it from
  position 4 onward, so `topK: 10` quietly became `topK: 3` regardless of relevance. Candidates the
  model omitted kept their cosine values, so one array mixed two incompatible scales. `score` is now
  always the cosine similarity and the new ranking is conveyed by array order alone — meaning
  `findSimilarWithRerank()` returns results in relevance order, **not** descending score order.
  `RerankerInterface` documents this requirement for third-party implementations.
- **Breaking:** `LlamaCppEmbeddingClient` throws on HTTP 400 instead of halving the text and
  retrying. The retry produced a vector for as little as an eighth of the document, which was then
  stored against the *full*-text hash — so every later `embedAndStore()` short-circuited on it and
  the row could never be repaired, not even after raising `--ctx-size`. Use `embeddingContextLength`
  or a `ChunkingStrategyInterface` to fit text into the model's window.
- `LlamaCppEmbeddingClient` rejects an empty embedding array. A payload of `[{"embedding":[[]]}]`
  satisfied the previous shape check and returned `[]`, which stored a zero-length vector; if the
  query vector was also empty, every row scored `0.0` and the first `topK` rows were returned
  presented as ranked hits.
- `embeddingContextLength` is clamped to a positive value. Clearing the field in the Install Tool
  left `''`, and `(int) ''` is `0` — so every document embedded the empty string, giving identical
  vectors across the collection and an identical `content_hash`. A negative value stripped the tail
  of each document instead of truncating it.
- `embedAndStoreChunked()` accepts `metadata` and stores it on every chunk. Without it, chunked
  documents were stored with empty metadata, so `findSimilar()` with any metadata filter returned
  **zero results on a chunked collection, always** — silently, and in a mixed collection the
  unchunked records still matched, so the result set looked plausible but was incomplete.
- `findSimilarWithRerank()` accepts `metadataFilters` and forwards them. It previously had no such
  parameter, so switching from `findSimilar()` to the reranked variant silently dropped the filter
  and leaked results across language, site or tenant boundaries.
- `embedAndStore()` now writes metadata that has changed even when the text has not. Metadata is
  not part of the content hash, so it was effectively write-once: correcting a `sys_language_uid`
  or backfilling a new key did nothing and filters kept using the stale values.

### Added

- `VectorRepository::updateMetadata()` and `findContentHashAndMetadata()`.

### Changed

- **Breaking:** metadata filters now compare strictly. The previous loose `!=` inherited PHP's
  coercion rules, under which a bool operand casts the other side to bool — so
  `['published' => true]` matched values stored as `"no"`, `"0.0"`, `42` or `"anything"`, and
  numeric strings compared numerically so `['site' => '7']` matched `'007'`. Filters are the only
  thing separating languages, sites or tenants that share a collection, so this matched far too
  much and did so silently. `int` and `float` are still treated as one numeric domain (a filter of
  `1` matches a stored `1.0`); everything else must match exactly, so `'1'` no longer matches `1`.
  Callers passing filter values whose type differs from what was stored must correct the type.
- **Breaking:** `metadata` is now `TEXT DEFAULT NULL` instead of `TEXT NOT NULL`. The previous
  definition could not be applied to an existing table: PostgreSQL rejects it outright (`column
  "metadata" contains null values`), and `TEXT NOT NULL DEFAULT ''` is not a valid alternative
  because MySQL forbids defaults on `TEXT` columns entirely (error 1101). The read path already
  tolerated `NULL`.

- **Breaking:** `VectorRepository::__construct()` now takes a `Psr\Log\LoggerInterface` as its
  second argument. Only affects code that instantiates the repository directly; DI wiring is
  unchanged.

### Fixed

- Undecodable vector blobs are now detected and logged at error level instead of being decoded
  into plausible-looking garbage. A row still holding the pre-0.2.0 JSON text decoded to finite,
  positive floats of the wrong dimension — indistinguishable downstream from a vector produced by
  a different embedding model. Note `unpack('f*')` does not fail on a bad length; it
  integer-divides and discards the remainder, so both a leading `[` and a byte length that is not
  a multiple of 4 are now checked explicitly. Affected rows are skipped rather than aborting the
  query.

## [0.1.0] - 2026-04-20

### Initial release

- `VectorService` — embed and store arbitrary text with MD5-based change detection; cosine similarity search across collections
- `GenerationService` — RAG generation via chat LLM with configurable context blocks
- `ModelAvailabilityService` — lightweight health checks for embedding and generation servers (per-request caching)
- `VectorRepository` — CRUD operations for the `tx_smartsearch_vector` table
- `LlamaCppEmbeddingClient` — HTTP client for llama.cpp `/embedding` endpoint with automatic text truncation retry
- `LlamaCppGenerationClient` — HTTP client for llama.cpp `/v1/chat/completions` endpoint
- `EmbeddingClientInterface` / `GenerationClientInterface` — pluggable backend contracts
- `SmartSearchConfiguration` — typed accessor for all extension settings
- PSR-3 logging for HTTP errors, embedding failures, and generation failures
- `ext_conf_template.txt` — all settings configurable via TYPO3 Install Tool
- `ext_tables.sql` — `tx_smartsearch_vector` table with collection+identifier unique index
- `llama.sh` — helper script for managing local llama.cpp server processes

[0.1.0]: https://github.com/boehmmatthias/smart-search/releases/tag/v0.1.0
