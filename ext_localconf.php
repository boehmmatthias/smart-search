<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

defined('TYPO3') or die();

// Cache for findSimilar() results. Declared with ??= so an integrator can override the backend
// entirely — a shared Redis backend, for instance — without this overwriting it on every request.
//
// Deliberately not in the 'pages' group: entries are invalidated by collection tag whenever a
// vector is written or deleted, so tying them to an editor's "flush frontend caches" would only
// discard valid work.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['smart_search_queries'] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => SimpleFileBackend::class,
    'options' => [
        'defaultLifetime' => 3600,
    ],
];
