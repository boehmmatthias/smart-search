# smart_search

> Generic vector embedding, semantic search, and RAG (Retrieval-Augmented Generation) infrastructure for TYPO3.

![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php)
![TYPO3 14](https://img.shields.io/badge/TYPO3-14-FF8700?logo=typo3)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

`smart_search` gives any TYPO3 extension the building blocks for semantic search and LLM-powered answers — without being tied to any specific data model or AI provider. Drop in the services, embed your content, and get back results ranked by meaning rather than keyword overlap.

> **Alpha state.** SmartSearch is under active development. The API is functional but may change before 1.0. We'd love your feedback: [open an issue](https://github.com/boehmmatthias/smart-search/issues).


---

## Features

- **Vectorization** — embed arbitrary text into float vectors via a pluggable client. Change detection via MD5 hashing avoids redundant API calls.
- **Semantic search** — find the most relevant stored entries for a natural-language query using cosine similarity, ranked by score.
- **RAG generation** — supply pre-formatted context blocks and get a grounded LLM answer that cites its sources.
- **Pluggable backends** — ships llama.cpp clients for both embedding and generation; swap in OpenAI, Ollama, or any other HTTP-based model by implementing two small interfaces.
- **Collection scoping** — multiple extensions can share the same table using distinct collection names without collision.
- **PSR-3 logging** — all HTTP errors and unexpected responses are logged to the TYPO3 log.

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.4+ |
| TYPO3 | 14.x |
| Embedding server | Any server exposing `POST /embedding` (default `http://localhost:8080`) |
| Generation server | Any OpenAI-compatible chat completions server (default `http://localhost:8081`) |

Ships with llama.cpp clients out of the box. Any other HTTP-based provider (Ollama, OpenAI, Azure OpenAI, …) works by implementing two small interfaces — see [Custom Backend](#implementing-a-custom-backend).

---

## Installation

```bash
composer require boehmmatthias/smartsearch
```

Activate the extension:

```bash
vendor/bin/typo3 extension:activate smart_search
```

Run the database schema update in **Admin Tools → Maintenance → Analyze Database Structure** to create the `tx_smartsearch_vector` table.

---

## Server Setup

The extension is provider-agnostic: any server that exposes `POST /embedding` and `POST /v1/chat/completions` (OpenAI-compatible) works. Update the URLs in **Admin Tools → Settings → Extension Configuration → smart_search** to point at your chosen backend.

### Production

Point the two configuration URLs at your production inference server — a self-hosted [llama.cpp](https://github.com/ggml-org/llama.cpp), [Ollama](https://ollama.com), or a hosted API like OpenAI. No bundled scripts are involved.

```
embeddingServerUrl   → http://your-embedding-host:8080
generationServerUrl  → http://your-generation-host:8081
```

To use a provider that speaks a different API shape (e.g. OpenAI), implement the two interfaces — see [Custom Backend](#implementing-a-custom-backend).

### Development (llama.sh helper)

The extension ships a `llama.sh` convenience script for **local development only**. It manages two llama-server processes, PID files, and log rotation using locally installed llama.cpp binaries.

**Prerequisites**

| Requirement | Notes |
|-------------|-------|
| [llama.cpp](https://github.com/ggml-org/llama.cpp) | Install via `brew install llama.cpp` on macOS, or build from source with `LLAMA_CURL=1`. |
| `llama-server` on `$PATH` | Verify: `llama-server --version` |
| ~6 GB free disk space | Models are cached in `~/.cache/huggingface` after first download. |
| ~4 GB RAM | The generation model needs ~4 GB; the embedding model is much lighter. |

```bash
./llama.sh start    # downloads models on first run, starts both servers
./llama.sh status
./llama.sh stop

# Follow logs
tail -f var/log/llama-embed.log
tail -f var/log/llama-generate.log
```

Verify both servers are up:

```bash
curl -s http://localhost:8080/health   # {"status":"ok"}
curl -s http://localhost:8081/health   # {"status":"ok"}
```

---

## Configuration

All settings are available under **Admin Tools → Settings → Extension Configuration → smart_search**.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `embeddingServerUrl` | string | `http://localhost:8080` | Base URL of the llama-server embedding instance. |
| `generationServerUrl` | string | `http://localhost:8081` | Base URL of the llama-server chat completions instance. |
| `generationMaxTokens` | integer | `512` | Maximum tokens allowed in a generated answer. Increase for longer, more detailed responses. |
| `generationTimeout` | integer | `300` | HTTP timeout in seconds for generation requests. CPU inference is slow — increase if answers are cut off. |
| `embeddingContextLength` | integer | `6000` | Maximum characters of text passed to the embedding server. Keep in sync with the model's `--ctx-size` (roughly 4 chars per token for typical prose). |
| `ragTopK` | integer | `5` | Number of top-scoring documents retrieved and passed as context for RAG generation. |
| `documentContextLength` | integer | `800` | Maximum characters of document content included per context block in RAG requests. |
| `semanticThreshold` | float | `0.30` | Minimum cosine similarity score (0.0–1.0) to treat a result as a semantic match. Results below this threshold can be filtered by the consuming extension. |

---

## Usage

Inject the services via constructor injection — TYPO3's dependency injection container wires everything automatically.

### Storing and updating embeddings

Call `VectorService::embedAndStore()` whenever content is created or updated. Pass a **collection** name (a string that scopes your entries), a stable **identifier**, and the **plain text** to embed. Strip HTML before calling.

```php
use BoehmMatthias\SmartSearch\Service\VectorService;

class MyEventListener
{
    public function __construct(
        private readonly VectorService $vectorService,
    ) {}

    public function afterSave(MyRecord $record): void
    {
        $this->vectorService->embedAndStore(
            collection: 'my-extension-articles',
            identifier: $record->getUid(),
            text: $record->getTitle() . "\n\n" . strip_tags($record->getBodyText()),
        );
    }
}
```

The call is **idempotent** — if the text has not changed since the last call, the embedding server is not contacted and the database is not written to.

### Semantic search

```php
$hits = $this->vectorService->findSimilar(
    collection: 'my-extension-articles',
    query: 'how do I configure caching?',
    topK: 5,
);

// Returns: [['identifier' => '42', 'score' => 0.87], ['identifier' => '7', 'score' => 0.74], ...]
// Sorted by cosine similarity descending. 'identifier' is always a string.
foreach ($hits as $hit) {
    $record = $this->recordRepository->findByUid((int)$hit['identifier']);
    // filter by threshold if needed: if ($hit['score'] < 0.30) continue;
}
```

### RAG generation (full example)

```php
use BoehmMatthias\SmartSearch\Service\GenerationService;
use BoehmMatthias\SmartSearch\Service\VectorService;
use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;

class SearchController
{
    public function __construct(
        private readonly VectorService $vectorService,
        private readonly GenerationService $generationService,
        private readonly SmartSearchConfiguration $configuration,
    ) {}

    public function answerAction(string $question): string
    {
        // 1. Find the most relevant documents
        $hits = $this->vectorService->findSimilar(
            collection: 'my-extension-articles',
            query: $question,
            topK: $this->configuration->getRagTopK(),
        );

        // 2. Filter by semantic threshold
        $threshold = $this->configuration->getSemanticThreshold();
        $hits = array_filter($hits, fn($h) => $h['score'] >= $threshold);

        if (empty($hits)) {
            return 'No relevant documents found.';
        }

        // 3. Build context blocks — one string per source document
        $maxChars = $this->configuration->getDocumentContextLength();
        $contextBlocks = [];
        foreach ($hits as $hit) {
            $record = $this->recordRepository->findByUid((int)$hit['identifier']);
            $excerpt = mb_substr(strip_tags($record->getBodyText()), 0, $maxChars);
            $contextBlocks[] = sprintf('[%d] %s\n%s', $record->getUid(), $record->getTitle(), $excerpt);
        }

        // 4. Generate a grounded answer
        return $this->generationService->generate(
            query: $question,
            contextBlocks: $contextBlocks,
        );
        // The system prompt instructs the model to answer only from the provided
        // documents and to cite sources by their identifier (e.g. [42]).
    }
}
```

### Removing vectors

Remove individual vectors when records are deleted, or wipe an entire collection before a full reindex:

```php
use BoehmMatthias\SmartSearch\Repository\VectorRepository;

// Remove a single entry
$this->vectorRepository->deleteByIdentifier('my-extension-articles', (string)$uid);

// Remove all entries in a collection (e.g. before a full reindex)
$this->vectorRepository->deleteByCollection('my-extension-articles');
```

### Checking server availability

Use `ModelAvailabilityService` to guard features that depend on the llama servers, for example to show or hide a semantic search toggle in the UI:

```php
use BoehmMatthias\SmartSearch\Service\ModelAvailabilityService;

if ($this->modelAvailabilityService->isEmbeddingServerAvailable()) {
    // offer semantic search
}

if ($this->modelAvailabilityService->isGenerationServerAvailable()) {
    // offer RAG answers
}
```

Results are cached for the duration of the current request (null-coalescing pattern).

---

## Implementing a Custom Backend

The two interfaces make it straightforward to replace the llama.cpp clients with any other embedding or generation provider.

### Custom embedding client (example: OpenAI)

```php
namespace MyVendor\MyExtension\Embedding;

use BoehmMatthias\SmartSearch\Embedding\EmbeddingClientInterface;

final class OpenAiEmbeddingClient implements EmbeddingClientInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'text-embedding-3-small',
    ) {}

    /** @return float[] */
    public function embed(string $text): array
    {
        // Call the OpenAI embeddings API and return the float array.
        // ...
    }
}
```

Then bind it in your extension's `Configuration/Services.yaml`:

```yaml
BoehmMatthias\SmartSearch\Embedding\EmbeddingClientInterface:
  alias: MyVendor\MyExtension\Embedding\OpenAiEmbeddingClient
```

The same pattern applies to `GenerationClientInterface` for swapping the chat completion backend.

> **Note:** When using a different embedding model, make sure all vectors in a collection were generated by the same model. Mixing models produces meaningless similarity scores. Use `VectorRepository::deleteByCollection()` and re-embed when switching models.

---

## Troubleshooting

### Search returns empty results

1. Check that the embedding server is running: `curl -s http://localhost:8080/health`
2. Confirm that `embedAndStore()` was called for your records.
3. Query the database directly: `SELECT COUNT(*) FROM tx_smartsearch_vector WHERE collection = 'your-collection';`
4. Lower `semanticThreshold` temporarily to `0.0` to see all results regardless of score.

### Health check fails / server unavailable

- Verify the server is running: `./llama.sh status` or check your Docker containers.
- Confirm the URL in **Extension Configuration** matches the actual server address (especially in DDEV: use `http://llama-embed:8080`, not `localhost`).
- Check server logs: `tail -f var/log/llama-embed.log`

### Generated answers are cut off

- Increase `generationMaxTokens` in the extension configuration.
- Increase `generationTimeout` — CPU inference for long responses can exceed 300 seconds on slow hardware.

### Generation is very slow

- CPU inference speed depends heavily on hardware. A GPU-accelerated llama.cpp build (`LLAMA_METAL=1` on macOS, `LLAMA_CUDA=1` on Linux) can be 10–50× faster.
- Reduce `ragTopK` and `documentContextLength` to pass less context to the model.
- Use a smaller/quantized model (e.g. Q4_K_M instead of Q8_0).

### Results have low relevance / wrong ranking

- Make sure you strip HTML and normalise whitespace before calling `embedAndStore()`. Tags pollute the vector representation.
- Ensure the text passed to `embedAndStore()` contains the full semantic content, not just a title.
- Verify you are using the same model for both embedding stored content and embedding queries. Mismatched models produce meaningless similarity scores.

### Dimension mismatch warning in logs

You switched embedding models without re-indexing. Entries generated by the old model have a different vector dimension than the query vector and are automatically skipped. Run a full reindex:

```php
$vectorRepository->deleteByCollection('your-collection');
// then re-call embedAndStore() for all records
```

---

## Known Limitations

- **No streaming** — generation responses are returned in full after the model finishes. The `stream: false` flag is hardcoded.
- **Single-vector operations** — there is no batch embed API; callers must loop over records.
- **No metadata fields** — the vector table stores only collection, identifier, vector, and a content hash. Extra fields (e.g. source URL, author) must be managed in the consuming extension's own tables.
- **PHP 8.4+ only** — the extension uses readonly constructor properties and other PHP 8.4 features.
- **In-process similarity search** — cosine similarity is computed in PHP after fetching all vectors for a collection. This works well up to tens of thousands of entries; for larger datasets consider a dedicated vector database.

---

## Database Schema

```
tx_smartsearch_vector
├── uid          INT UNSIGNED AUTO_INCREMENT  PRIMARY KEY
├── collection   VARCHAR(255)                 -- scopes entries per extension/use-case
├── identifier   VARCHAR(255)                 -- stable record ID within the collection
├── vector       LONGTEXT                     -- JSON-encoded float array
├── content_hash VARCHAR(32)                  -- MD5 of the normalised text (change detection)
└── tstamp       INT UNSIGNED                 -- Unix timestamp of last update

UNIQUE KEY  uq_collection_identifier (collection, identifier(191))
KEY         idx_collection (collection)
```

Multiple extensions can share the table without collision by using distinct collection names (e.g. `news-articles`, `faq-entries`, `product-descriptions`).

---

## Contributing

1. Fork the repository and create a branch.
2. Install dependencies: `composer install`
3. Run the test suite: `vendor/bin/phpunit packages/smart-search/Tests/Unit/`
4. Run static analysis: `vendor/bin/phpstan analyse -c packages/smart-search/phpstan.neon`
5. Submit a pull request with a clear description of the change.

Please follow the existing code style (strict types, readonly constructors, PSR-12).

---

## Testing

```bash
# Unit tests
Build/Scripts/runTests.sh -s unit
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
