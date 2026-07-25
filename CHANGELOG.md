# Changelog

## [Unreleased]

### Added

- `VectorCodec` — pack/unpack extracted from `VectorRepository` so the float32 round trip is
  testable without a database.

### Changed

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
