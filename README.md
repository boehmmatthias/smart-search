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
- **RAG generation** — supply pre-formatted context blocks and get a grounded LLM answer that cites its sources, in one call or streamed token by token.
- **Multi-turn conversations** — carry prior turns into the next question with `ConversationHistory`, so the model can resolve follow-ups.
- **Pluggable backends** — ships llama.cpp, Ollama and OpenAI clients for both embedding and generation, selected by configuration. Any other HTTP-based provider works by implementing two small interfaces.
- **Text chunking** — split long documents with `ParagraphChunker` or `SlidingWindowChunker`, or your own `ChunkingStrategyInterface`. Chunks are stored, updated and removed as a set.
- **Metadata filtering** — store scalar key-value pairs alongside each vector (language, site, tenant, author) and restrict searches to entries matching all of them.
- **Hybrid search** — fuse the semantic ranking with your own keyword search using Reciprocal Rank Fusion.
- **Reranking** — widen retrieval and reorder the candidates through a `RerankerInterface` for higher precision.
- **Query caching** — `findSimilar()` results are cached and invalidated per collection on every write and delete, so the TTL is a ceiling rather than a staleness window.
- **Collection scoping** — multiple extensions can share the same table using distinct collection names without collision.
- **PSR-3 logging** — all HTTP errors and unexpected responses are logged to the TYPO3 log.

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.4+ |
| TYPO3 | 14.x |
| A model backend | llama.cpp, Ollama or OpenAI — see below |

Ships with llama.cpp, Ollama and OpenAI clients for both embedding and generation, selected with the `embeddingProvider` and `generationProvider` settings. The two are chosen independently, so embedding locally on llama.cpp while generating with a hosted model is a supported arrangement.

| Provider | Embedding endpoint | Generation endpoint | Configured with |
|----------|--------------------|---------------------|-----------------|
| `llamacpp` *(default)* | `POST {embeddingServerUrl}/embedding` | `POST {generationServerUrl}/v1/chat/completions` | `embeddingServerUrl`, `generationServerUrl` |
| `ollama` | `POST {ollamaServerUrl}/api/embeddings` | `POST {ollamaServerUrl}/api/chat` | `ollamaServerUrl`, `ollamaEmbeddingModel`, `ollamaGenerationModel` |
| `openai` | `POST https://api.openai.com/v1/embeddings` | `POST https://api.openai.com/v1/chat/completions` | `openAiApiKey`, `openAiEmbeddingModel`, `openAiGenerationModel` |

Any other provider (Azure OpenAI, a gateway, an in-house service) works by implementing two small interfaces — see [Custom Backend](#implementing-a-custom-backend).

---

## Installation

```bash
composer require boehmmatthias/smartsearch
```

Set the extension up. This is what creates the `tx_smartsearch_vector` table:

```bash
vendor/bin/typo3 extension:setup --extension=smart_search
```

Omit `--extension` to set up every extension at once. In the backend, the equivalent is **Admin Tools → Maintenance → Analyze Database Structure**.

---

## Server Setup

Pick a provider and configure it in **Admin Tools → Settings → Extension Configuration → smart_search**.

### Production

**llama.cpp** — point the two URLs at your inference server. No bundled scripts are involved.

```
embeddingProvider    → llamacpp
generationProvider   → llamacpp
embeddingServerUrl   → http://your-embedding-host:8080
generationServerUrl  → http://your-generation-host:8081
```

**Ollama** — one URL serves both sides; name the models you have pulled.

```
embeddingProvider    → ollama
generationProvider   → ollama
ollamaServerUrl      → http://your-ollama-host:11434
```

**OpenAI** — set the API key. Note that it is stored in plain text in the site configuration and is readable by any backend administrator.

```
embeddingProvider    → openai
generationProvider   → openai
openAiApiKey         → sk-...
```

The two providers are independent, so `embeddingProvider: llamacpp` with `generationProvider: openai` is a valid combination. To use a provider none of the three clients speaks, implement the two interfaces — see [Custom Backend](#implementing-a-custom-backend).

> **Changing `embeddingProvider` invalidates every stored vector.** Vectors from different models are not comparable, and a same-dimension swap is undetectable. Clear the collection and re-embed.

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
| `embeddingProvider` | options | `llamacpp` | Embedding backend: `llamacpp`, `ollama` or `openai`. An unrecognised value falls back to `llamacpp`. **Changing this invalidates every stored vector** — vectors from different models are not comparable, so clear the collection and re-embed. |
| `generationProvider` | options | `llamacpp` | Generation backend, chosen independently of the embedding one. |
| `openAiApiKey` | string | *(empty)* | Required for the `openai` providers. **Stored in plain text in the site configuration and readable by any backend admin.** |
| `openAiEmbeddingModel` | string | `text-embedding-3-small` | |
| `openAiGenerationModel` | string | `gpt-4o-mini` | |
| `ollamaServerUrl` | string | `http://localhost:11434` | |
| `ollamaEmbeddingModel` | string | `nomic-embed-text` | |
| `ollamaGenerationModel` | string | `llama3.2` | |
| `queryCacheTtl` | integer | `3600` | Seconds to cache `findSimilar()` results. `0` disables caching. Entries are invalidated per collection whenever a vector is written or deleted, so stale results are not served — the TTL is only a ceiling. |
| `systemPrompt` | text | *(empty)* | Overrides the built-in RAG system prompt. Leave empty to use the default. A per-call `systemPrompt` argument to `generate()` takes precedence over this. |

Clearing a field in the Install Tool stores an empty string rather than removing the setting, so every getter falls back to the default shown above rather than to `''` or `0`. A `generationTimeout` of `0` in particular would mean "wait indefinitely".

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

Metadata is not part of the change-detection hash, so calling `embedAndStore()` again with the same text but different metadata updates the metadata without re-embedding. Key order is not significant — passing the same pairs in a different order counts as unchanged and writes nothing.

### Long documents: chunking

```php
use BoehmMatthias\SmartSearch\Chunking\ParagraphChunker;

$this->vectorService->embedAndStoreChunked(
    collection: 'my-extension-articles',
    identifier: $record->getUid(),
    text: $text,
    strategy: new ParagraphChunker(minChunkSize: 100, maxChunkSize: 1500),
    metadata: ['sys_language_uid' => 1],
);
```

Two strategies ship. Both reject arguments that would silently produce bad chunks, throwing `InvalidArgumentException` at construction:

| Strategy | Arguments | Constraints |
|----------|-----------|-------------|
| `ParagraphChunker` | `minChunkSize` (100), `maxChunkSize` (800) | both ≥ 1, and `minChunkSize` ≤ `maxChunkSize` |
| `SlidingWindowChunker` | `chunkSize` (800), `overlapSize` (100) | `chunkSize` ≥ 1, and `0` ≤ `overlapSize` < `chunkSize` |

`ParagraphChunker` splits on blank lines (any line-ending style) and merges paragraphs below `minChunkSize` into their neighbour. `maxChunkSize` bounds that merging — it is not a cap on chunk length, so a single paragraph longer than it is emitted whole. Use `SlidingWindowChunker` when your paragraphs are themselves longer than the embedding context window.

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

### Hybrid search

`HybridSearchService` fuses the semantic ranking with a keyword ranking using Reciprocal Rank Fusion. RRF compares *ranks* rather than scores, which is the point: a cosine similarity and a keyword relevance score are on unrelated scales and cannot be combined arithmetically.

This extension has no keyword search of its own — it knows nothing about your records. Supply one:

```php
namespace MyVendor\MyExtension\Search;

use BoehmMatthias\SmartSearch\Search\KeywordSearchInterface;

final class ArticleKeywordSearch implements KeywordSearchInterface
{
    /** @return string[] Identifiers in ranked order, best match first. */
    public function search(string $collection, string $query): array
    {
        // Your own fulltext/LIKE query. Identifiers must come from the same
        // namespace as those passed to embedAndStore(), or the two rankings
        // cannot be fused.
    }
}
```

```yaml
BoehmMatthias\SmartSearch\Search\KeywordSearchInterface:
  alias: MyVendor\MyExtension\Search\ArticleKeywordSearch
```

Then search:

```php
$hits = $this->hybridSearchService->findSimilar(
    collection: 'my-extension-articles',
    query: 'how do I configure caching?',
    topK: 5,
    semanticWeight: 0.7,
    keywordWeight: 0.3,
    metadataFilters: ['sys_language_uid' => 1],
    collapseChunks: true,
);
```

Until you bind an implementation, `NullKeywordSearch` is bound and finds nothing, so hybrid search degrades to pure semantic search rather than failing.

> The returned `score` is an **RRF value, not a cosine similarity** — do not compare it against `semanticThreshold`. Negative weights, or two zero weights, throw `InvalidArgumentException`.

### Streaming generation

`generateStream()` takes the same arguments as `generate()` and resolves the system prompt identically, invoking your callback once per text delta as it arrives:

```php
$this->generationService->generateStream(
    query: $question,
    contextBlocks: $contextBlocks,
    onChunk: function (string $delta): void {
        echo $delta;
        flush();
    },
);
```

Streaming is served by `StreamingGenerationClientInterface`, bound to `LlamaCppStreamingGenerationClient` (Server-Sent Events against an OpenAI-compatible endpoint). Unlike `generate()`, it does **not** follow `generationProvider` — bind the interface yourself to stream from another backend.

### Multi-turn conversations

Pass prior turns so the model can resolve follow-ups like "and the second one?". They are inserted between the system message and the current question:

```php
use BoehmMatthias\SmartSearch\ValueObject\ConversationHistory;

$history = ConversationHistory::empty()
    ->withUserMessage('What are the opening hours?')
    ->withAssistantMessage('The camp is open from 8:00 to 20:00.')
    ->truncated(maxTurns: 5);

$answer = $this->generationService->generate(
    query: 'And on weekends?',
    contextBlocks: $contextBlocks,
    history: $history,
);
```

`ConversationHistory` is immutable — every method returns a new instance. `truncated()` keeps the most recent turns and drops the oldest, which is what a context window running out actually needs. It holds `user` and `assistant` turns only and throws `InvalidArgumentException` on anything else: the system message is prepended by `GenerationService`, so one in here would produce two.

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

Use `ModelAvailabilityService` to guard features that depend on a model backend, for example to show or hide a semantic search toggle in the UI:

```php
use BoehmMatthias\SmartSearch\Service\ModelAvailabilityService;

if ($this->modelAvailabilityService->isEmbeddingServerAvailable()) {
    // offer semantic search
}

if ($this->modelAvailabilityService->isGenerationServerAvailable()) {
    // offer RAG answers
}
```

Each side follows its own provider setting, so the check reflects the backend actually in use:

| Provider | How availability is determined |
|----------|-------------------------------|
| `llamacpp` | `GET {serverUrl}/health` returns < 300 |
| `ollama` | `GET {ollamaServerUrl}/api/tags` returns < 300 |
| `openai` | An `openAiApiKey` is configured. No network call is made — OpenAI has no free health endpoint, and the cheapest probe would be a billable request |

Probes use a 2-second timeout and never throw: an unreachable server is reported as unavailable and logged at debug level. Results are cached for the duration of the current request.

---

## CLI Commands

```bash
vendor/bin/typo3 smartsearch:stats                 # collections, vector counts, last indexed
vendor/bin/typo3 smartsearch:clear <collection>    # delete every vector in a collection
vendor/bin/typo3 smartsearch:reindex [collection]  # run registered reindex handlers
vendor/bin/typo3 smartsearch:cleanup [collection]  # remove vectors for deleted records
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
        $indexed = 0;

        foreach ($this->articles->findAll() as $article) {
            $this->vectorService->embedAndStore(
                collection: 'myext_articles',
                identifier: $article->getUid(),
                text: $article->getSearchableText(),
            );
            $indexed++;
        }

        return $indexed;
    }
}
```

```yaml
MyVendor\MyExt\Search\ArticleReindexHandler:
  tags:
    - name: smartsearch.reindex_handler
```

`smartsearch:cleanup` works the same way, via `OrphanProviderInterface` and the
`smartsearch.orphan_provider` tag. Your provider returns the identifiers that still exist; anything
else in the collection is deleted.

If a provider returns an empty list the command **stops rather than deleting the collection** — an
empty list is indistinguishable from a provider that failed to load its records, and guessing wrong
wipes everything. Pass `--allow-empty` when the source really is empty.

---

## Implementing a Custom Backend

llama.cpp, Ollama and OpenAI are already covered by the `embeddingProvider` and `generationProvider` settings — you do not need to write a client for those. This section is for a provider none of them speaks: Azure OpenAI, an LLM gateway, or an in-house service.

### Custom embedding client (example: Azure OpenAI)

```php
namespace MyVendor\MyExtension\Embedding;

use BoehmMatthias\SmartSearch\Embedding\EmbeddingClientInterface;

final class AzureOpenAiEmbeddingClient implements EmbeddingClientInterface
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly string $deployment,
    ) {}

    /** @return float[] Never empty. */
    public function embed(string $text): array
    {
        // Call your deployment and return the float array.
        // MUST throw \RuntimeException on transport failure or an unexpected
        // payload — returning [] stores a zero-length vector that then scores
        // 0.0 against everything, which is indistinguishable from a genuinely
        // unrelated document.
    }
}
```

Then bind it in your extension's `Configuration/Services.yaml`:

```yaml
BoehmMatthias\SmartSearch\Embedding\EmbeddingClientInterface:
  alias: MyVendor\MyExtension\Embedding\AzureOpenAiEmbeddingClient
```

This replaces `ConfigurableEmbeddingClient`, so the `embeddingProvider` setting no longer has any effect — your client is used unconditionally. The same pattern applies to `GenerationClientInterface` for the chat completion backend, and to `StreamingGenerationClientInterface` for streaming.

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

- **Single-vector operations** — there is no batch embed API; callers must loop over records.
- **PHP 8.4+ only** — set by `composer.json` and `ext_emconf.php`, which move together. TYPO3 14 itself requires 8.2+; this extension pins higher.
- **The keyword half of hybrid search is not metadata-filtered** — `smart_search` cannot apply filters to a search it does not run. If your collection mixes languages or tenants, scope it inside your own `KeywordSearchInterface`, or those rows re-enter the fused ranking through that branch.
- **Streaming ignores `generationProvider`** — `generateStream()` resolves through `StreamingGenerationClientInterface`, which is bound to the llama.cpp SSE client. Ollama and OpenAI streaming clients are not bundled; bind your own.
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
