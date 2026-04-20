<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Smart Search',
    'description' => 'Generic vector embedding, semantic search, and RAG infrastructure for TYPO3.',
    'category' => 'services',
    'author' => 'Matthias Böhm',
    'author_email' => 'me@matthiasboehm.at',
    'author_company' => '',
    'state' => 'alpha',
    'version' => '0.1.0',
    'clearCacheOnLoad' => true,
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'php' => '8.4.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'BoehmMatthias\\SmartSearch\\' => 'Classes/',
        ],
    ],
];
