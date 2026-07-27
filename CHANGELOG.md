# Changelog

## [Unreleased]

### Added

- `VectorRepository::getCollectionStats()` — per-collection vector count and last-indexed
  timestamp, aggregated in SQL.

## [0.2.0] - 2026-07-25

This release consolidates four features that landed after 0.1.0 but were never recorded here
— text chunking, packed-binary vector storage, metadata filtering and reranking, plus a
configurable system prompt — together with the correctness work that followed from auditing
them. **Upgrading from 0.1.0 requires running the upgrade wizard; see Upgrading below.**

### Upgrading from 0.1.0

0.1.0 stored vectors as JSON text in a `LONGTEXT` column; they are now packed float32 in a
`MEDIUMBLOB`. The schema update does not convert the data, and the failure is silent: the JSON
bytes decode as plausible floats of the wrong dimension, so every row is skipped and every
query returns nothing. Re-indexing cannot repair it, because `content_hash` still matches and
`embedAndStore()` short-circuits before embedding.

After `composer update` and the database schema update, run:

```bash
vendor/bin/typo3 upgrade:run smartSearchMigrateJsonVectorsToPackedFloat32
```

No embedding server is required — only the encoding changes, not the text.

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
- `VectorService::deleteChunked()` — removes every chunk of a chunked document. There was
  previously **no working delete path for chunked content**: the documented removal call matches
  one exact identifier, and a chunked document has no row under its own identifier, so deleting the
  source record removed nothing and left orphans that kept surfacing in search pointing at a record
  that no longer existed.
- `VectorService::delete()` — mirrors the repository call so consumers can stay on the service.
- `findSimilar(..., collapseChunks: true)` — groups chunk hits back to their parent document,
  keeping each parent's best-scoring chunk, and returns parent identifiers. Chunks of one document
  are near-duplicates by construction, so without it a single long document could occupy every slot
  and turn `topK: 5` into five passages from one source. Opt-in; the default is unchanged.
- `VectorService::CHUNK_SEPARATOR` — the `_chunk_` separator consumers `explode()` on, now a named
  constant rather than a repeated literal.
- `VectorRepository::updateMetadata()` and `findContentHashAndMetadata()`.

- Text chunking: `ChunkingStrategyInterface` with `ParagraphChunker` and
  `SlidingWindowChunker`, plus `VectorService::embedAndStoreChunked()`.
- Metadata: scalar key-value pairs stored per vector and filterable via `$metadataFilters`.
- Reranking: `RerankerInterface` with `LlmReranker`, plus
  `VectorService::findSimilarWithRerank()`.
- Configurable RAG system prompt via the `systemPrompt` setting or a per-call argument.
- A CI workflow running unit tests, PHPStan and php-cs-fixer on every push and pull request.

### Changed

- **Breaking:** `embeddingServerUrl` and `generationServerUrl` now default to `http://localhost:8080`
  and `:8081` in `ext_conf_template.txt`, matching what `SmartSearchConfiguration` and the README
  always claimed. The shipped template said `host.docker.internal`, which does not resolve outside a
  Docker Desktop container — so a fresh non-Docker install reported both servers down while the
  README told the user to `curl localhost`, which succeeded, pointing them at the wrong cause.
  Installs that never saved the setting will pick up the new default.
- `RerankerInterface` has an explicit `Services.yaml` alias. It previously resolved only through
  Symfony's singly-implemented-interface rule, which would have vanished the moment a second
  implementation existed — taking down the whole container, not just this extension.
- Documentation: the README documented `LONGTEXT` JSON storage, claimed the extension had "no
  metadata fields", and described none of chunking, metadata filtering, reranking or `systemPrompt`.
  Its Contributing commands referenced `packages/smart-search/…` paths and a `vendor/` directory,
  neither of which exists. Known Limitations has been rewritten against what the code actually does.
- `ragTopK`, `documentContextLength` and `semanticThreshold` are now labelled advisory in both the
  README and `ext_conf_template.txt`. Nothing in the extension reads them; they exist for consuming
  extensions to apply.
- `EmbeddingClientInterface` and `GenerationClientInterface` document that implementations must throw
  rather than return an empty result. An empty vector is stored as a zero-length blob and then scores
  `0.0` against everything, which is indistinguishable from a genuinely unrelated document.
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

- **Breaking:** vectors are stored as packed IEEE 754 float32 (`pack('f*')`) in a `MEDIUMBLOB`
  rather than as JSON text in a `LONGTEXT`. Roughly 4 bytes per dimension instead of 8–14.
- `composer.json` no longer declares a `version` field; the version comes from the git tag.
  A stale field would have published 0.2.0 as 0.1.0, letting `^0.1.0` pull in this release's
  breaking changes and defeating the point of the bump.
- PHPStan raised from level 6 to 8, and php-cs-fixer pinned to an explicit rule set that
  actually enforces `declare(strict_types=1)` and import ordering.

### Fixed

- `VectorService::embedAndStoreChunked()` no longer deletes vectors belonging to other
  documents. The stale-chunk sweep deleted everything matching
  `LIKE '{identifier}_chunk_%'`, which also matches standalone documents that merely share
  the prefix (e.g. `faq_chunk_overview` when re-chunking `faq`) and the chunks of a document
  named `{identifier}_chunk_{n}`. Deletion is now restricted to identifiers matching
  `{identifier}_chunk_{int}` exactly. This also fixes deletion of case-differing documents on
  PostgreSQL and SQLite, where `LIKE` is case-insensitive but the unique index is not.
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
- `findByCollection()` streams rows instead of buffering them, and applies metadata filters before
  unpacking each vector. It previously held every raw row in memory while a second pass built the
  float arrays, so both representations were alive at peak — roughly 33 KB retained per row at 1536
  dimensions, exhausting a 128M limit at around 3,400 rows, with chunking multiplying the row count.
  Filtered-out rows are now never decoded at all.
- `upsert()` no longer throws a `UniqueConstraintViolationException` into the caller's request
  cycle when two workers index the same record concurrently. The existence check and the insert are
  separate statements with an HTTP embedding round trip between them, so the window is wide; a
  duplicate-key insert now falls through to an update.
- `Connection::PARAM_*` types are keyed by column name instead of by position. The positional form
  was correct only by coincidence of field order — inserting a field before `vector` would have
  shifted `PARAM_LOB` onto another column and bound the blob as a string.
- Change-detection reads no longer `SELECT *`, which pulled the entire vector blob (~12 KB per
  record at 3072 dimensions) on the hot path of every re-index just to read a 32-character hash.
- `embedAndStore()` now writes metadata that has changed even when the text has not. Metadata is
  not part of the content hash, so it was effectively write-once: correcting a `sys_language_uid`
  or backfilling a new key did nothing and filters kept using the stale values.
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

[0.2.0]: https://github.com/boehmmatthias/smart-search/releases/tag/v0.2.0
[0.1.0]: https://github.com/boehmmatthias/smart-search/releases/tag/v0.1.0
