# Changelog

## [0.1.0]

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

[0.1.0]: https://github.com/boehmmatthias/smartsearch/releases/tag/v0.1.0
