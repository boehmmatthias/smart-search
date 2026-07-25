<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    // Required for declare_strict_types, which php-cs-fixer classes as risky.
    ->setRiskyAllowed(true)
    ->setRules([
        // Pinned explicitly rather than via '@auto'. That preset resolves to @PER-CS plus
        // @autoPHPMigration and is keyed off the installed fixer version, so a composer update
        // could silently change the ruleset and produce a large unrelated diff. It also enforced
        // neither of the two conventions below: declare_strict_types is risky and was therefore
        // disabled, and ordered_imports is not part of @PER-CS.
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
    ])
    // 💡 by default, Fixer looks for `*.php` files excluding `./vendor/` - here, you can groom this config
    ->setFinder(
        (new Finder())
            // 💡 root folder to check
            ->in(__DIR__)
            // Not ours to format: .Build is the composer vendor dir and public/ is a generated
            // TYPO3 document root. Both are gitignored.
            ->exclude(['.Build', 'public', 'var'])
            // 💡 additional files, eg bin entry file
            // ->append([__DIR__.'/bin-entry-file'])
            // 💡 folders to exclude, if any
            // ->exclude([/* ... */])
            // 💡 path patterns to exclude, if any
            // ->notPath([/* ... */])
            // 💡 extra configs
            // ->ignoreDotFiles(false) // true by default in v3, false in v4 or future mode
            // ->ignoreVCS(true) // true by default
    )
;
