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
- **Text chunking** — split long documents with `ParagraphChunker` or `SlidingWindowChunker`, or your own `ChunkingStrategyInterface`. Chunks are stored, updated and removed as a set.
- **Metadata filtering** — store scalar key-value pairs alongside each vector (language, site, tenant, author) and restrict searches to entries matching all of them.
- **Reranking** — widen retrieval and reorder the candidates through a `RerankerInterface` for higher precision.
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
| `semanticThreshold` | float | `0.30` | Minimum cosine similarity score (0.0–1.0) to treat a result as a semantic match. |
| `systemPrompt` | text | *(empty)* | Overrides the built-in RAG system prompt. Leave empty to use the default. A per-call `systemPrompt` argument to `generate()` takes precedence over this. |

`ragTopK`, `documentContextLength` and `semanticThreshold` are **advisory**: `smart_search` does not apply them itself. They exist so a consuming extension and its integrator have one agreed place to configure retrieval policy — read them via `SmartSearchConfiguration` and apply them in your own code. Setting `semanticThreshold` will not change what `findSimilar()` returns.

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

### Storing metadata

Pass scalar key-value pairs to keep entries separable later — language, site, tenant, author:

```php
$this->vectorService->embedAndStore(
    collection: 'my-extension-articles',
    identifier: $record->getUid(),
    text: $text,
    metadata: ['sys_language_uid' => $record->getLanguageUid(), 'site' => 'main'],
);
```

Metadata is compared **strictly**, so the filter value's type must match what was stored: a filter of `'1'` will not match a stored integer `1`. Integers and floats are interchangeable.

Metadata is not part of the change-detection hash, so calling `embedAndStore()` again with the same text but different metadata updates the metadata without re-embedding.

### Long documents: chunking

```php
use BoehmMatthias\SmartSearch\Chunking\ParagraphChunker;

$this->vectorService->embedAndStoreChunked(
    collection: 'my-extension-articles',
    identifier: $record->getUid(),
    text: $text,
    strategy: new ParagraphChunker(maxChars: 1500),
    metadata: ['sys_language_uid' => 1],
);
```

Chunks are stored as `"{identifier}_chunk_{n}"`. Chunks that disappear because the document got shorter are removed automatically. Use `deleteChunked()` to remove a chunked document — `delete()` will not find it, because there is no row under the bare identifier:

```php
$this->vectorService->deleteChunked(collection: 'my-extension-articles', identifier: $uid);
```

### Semantic search

```php
$hits = $this->vectorService->findSimilar(
    collection: 'my-extension-articles',
    query: 'how do I configure caching?',
    topK: 5,
    metadataFilters: ['sys_language_uid' => 1],
    collapseChunks: true,
);

// Returns: [['identifier' => '42', 'score' => 0.87], ['identifier' => '7', 'score' => 0.74], ...]
// Sorted by cosine similarity descending. 'identifier' is always a string.
foreach ($hits as $hit) {
    $record = $this->recordRepository->findByUid((int)$hit['identifier']);
    // filter by threshold if needed: if ($hit['score'] < 0.30) continue;
}
```

`collapseChunks: true` groups chunk hits back to their parent document and returns parent identifiers, keeping each parent's best-scoring chunk. Without it, one long document's chunks can fill `topK` on their own.

### Reranking

Widen retrieval, then reorder the candidates with a more precise signal:

```php
$hits = $this->vectorService->findSimilarWithRerank(
    collection: 'my-extension-articles',
    query: 'how do I configure caching?',
    reranker: $this->reranker,       // RerankerInterface, autowired to LlmReranker
    topK: 5,
    rerankK: 20,
    metadataFilters: ['sys_language_uid' => 1],
);
```

The result is ordered by the reranker's judgement of relevance, **not** by descending `score` — `score` stays the cosine similarity so it remains comparable with `findSimilar()`. Note that the bundled `LlmReranker` sends the model only the candidate identifiers, never your document text; implement `RerankerInterface` yourself for a signal that actually sees the content.

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
            $contextBlocks[] = sprintf("[%d] %s\n%s", $record->getUid(), $record->getTitle(), $excerpt);
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
use BoehmMatthias\SmartSearch\Service\VectorService;

// Remove a single entry (VectorService and VectorRepository are constructor-injected)
$this->vectorService->delete(collection: 'my-extension-articles', identifier: $uid);

// Remove a document that was stored with embedAndStoreChunked(). delete() will not find
// it — a chunked document has no row under its own identifier.
$this->vectorService->deleteChunked(collection: 'my-extension-articles', identifier: $uid);

// Remove all entries in a collection (e.g. before a full reindex)
$this->vectorRepository->deleteByCollection(collection: 'my-extension-articles');
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

## CLI Commands

```bash
vendor/bin/typo3 smartsearch:stats                 # collections, vector counts, last indexed
vendor/bin/typo3 smartsearch:clear <collection>    # delete every vector in a collection
vendor/bin/typo3 smartsearch:reindex [collection]  # run registered reindex handlers
```

`smartsearch:clear` prompts before deleting and defaults to no; pass `-y` to skip the prompt in scripts. There is no undo — this extension does not know your source records, so restoring means re-running your own indexer against a live embedding server.

`smartsearch:reindex` has nothing to reindex on its own, for the same reason. Contribute a handler:

```php
use BoehmMatthias\SmartSearch\Command\ReindexCommandInterface;

final class ArticleReindexHandler implements ReindexCommandInterface
{
    public function getLabel(): string { return 'news articles'; }
    public function getCollection(): string { return 'myext_articles'; }

    public function reindex(): int
    {
        foreach ($this->articles->findAll() as $article) {
            $this->vectorService->embedAndStore(
                collection: 'myext_articles',
                identifier: $article->getUid(),
                text: $article->getSearchableText(),
            );
        }

        return count($articles);
    }
}
```

```yaml
MyVendor\MyExt\Search\ArticleReindexHandler:
  tags:
    - name: smartsearch.reindex_handler
```

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
- **PHP 8.4+ only** — the extension uses readonly constructor properties and other PHP 8.4 features.
- **In-process similarity search** — cosine similarity is computed in PHP after fetching every vector in the collection. Peak memory is roughly 16 bytes per dimension per row, rounded up to the next power of two, so a 128 MB limit is reached at a few thousand rows at 1536 dimensions. Chunking multiplies the row count. For larger corpora, consider a dedicated vector database.
- **Metadata filters do not reduce the rows read** — they are applied in PHP, deliberately, so no JSON-column support is required. They do avoid decoding the vectors of non-matching rows, but the rows are still fetched.
- **Chunk results are not collapsed by default** — `findSimilar()` returns chunk identifiers, and chunks of one document are near-duplicates, so a single long document can fill `topK`. Pass `collapseChunks: true` to group back to parent documents.
- **Reranking does not see your content** — `LlmReranker` sends the model only the candidate identifiers, never the document text, so it is ordering opaque strings. It is a seam for a better reranker rather than a strong signal in itself.
- **Reranked results are ordered by relevance, not by score** — `score` remains the cosine similarity, so it stays comparable with `findSimilar()` and with `semanticThreshold`, but the array is no longer sorted descending by it.
- **One embedding model per collection** — vectors from different models are not comparable. A dimension mismatch is detected and logged, but a same-dimension different-model swap is undetectable and returns confident nonsense. Switching models means `deleteByCollection()` plus a full re-embed.
- **Vectors are stored in native byte order** — `pack('f*')` is architecture-dependent. A database dumped on one architecture and restored on another with different endianness will decode to garbage.

---

## Database Schema

```
tx_smartsearch_vector
├── uid          INT UNSIGNED AUTO_INCREMENT  PRIMARY KEY
├── collection   VARCHAR(255)                 -- scopes entries per extension/use-case
├── identifier   VARCHAR(255)                 -- stable record ID within the collection
├── vector       MEDIUMBLOB                   -- packed IEEE 754 float32, via pack('f*')
├── content_hash VARCHAR(32)                  -- MD5 of the normalised text (change detection)
├── metadata     TEXT                         -- JSON object, scalar values only
└── tstamp       INT UNSIGNED                 -- Unix timestamp of last update

UNIQUE KEY  uq_collection_identifier (collection, identifier(191))
KEY         idx_collection (collection)
```

Multiple extensions can share the table without collision by using distinct collection names (e.g. `news-articles`, `faq-entries`, `product-descriptions`).

---

## Upgrading from 0.1.0

**0.1.0 stored vectors as JSON text. 0.2.0 stores them as packed float32 binary. You must run the upgrade wizard, and search will return nothing until you do.**

The column type change on its own does not convert the data — the JSON bytes survive it untouched. Worse, the failure is silent: reading that text as packed floats produces finite, plausible-looking numbers of the wrong dimension, so every row is quietly skipped and every query returns no results. Only a warning per row per query hints at it.

**Re-indexing does not fix this.** `content_hash` still matches the source text, so `embedAndStore()` short-circuits before embedding and repairs nothing.

After `composer update` and the database schema update, run:

```bash
vendor/bin/typo3 upgrade:run smartSearchMigrateJsonVectorsToPackedFloat32
```

Or apply it from the Install Tool under *Upgrade → Upgrade Wizard*.

The wizard converts the stored values in place. It needs no embedding server — only the encoding changes, not the text the vectors were derived from — so `content_hash` is left alone and nothing has to be re-embedded. It is safe to re-run: it only touches rows still holding JSON.

On PostgreSQL the wizard also performs the column type change itself. Doctrine cannot express that conversion (`ALTER COLUMN vector TYPE BYTEA` fails without a `USING` clause), so the schema update alone leaves a PostgreSQL install unable to complete the upgrade.

If you would rather start clean, the alternative is to call `VectorRepository::deleteByCollection()` for each collection and re-index from your consuming extension. That requires a running embedding server and costs a full re-embed of your corpus.

---

## Contributing

1. Fork the repository and create a branch.
2. Install dependencies: `composer install`
3. Run the test suite: `Build/Scripts/runTests.sh -s unit`
4. Run static analysis: `Build/Scripts/runTests.sh -s phpstan`
5. Check coding standards: `Build/Scripts/runTests.sh -s cgl` (`-s cgl-fix` to apply)
6. Submit a pull request with a clear description of the change.

Please follow the existing code style (strict types, readonly constructors, PER-CS). All three checks run in CI on every pull request.

---

## Testing

```bash
# Unit tests
Build/Scripts/runTests.sh -s unit
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
