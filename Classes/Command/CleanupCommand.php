<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Command;

use BoehmMatthias\SmartSearch\Repository\VectorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'smartsearch:cleanup',
    description: 'Remove orphaned vectors for records that no longer exist.',
)]
final class CleanupCommand extends Command
{
    /** @var OrphanProviderInterface[] */
    private readonly array $providers;

    /**
     * @param iterable<OrphanProviderInterface> $providers Services tagged smartsearch.orphan_provider.
     */
    public function __construct(
        private readonly VectorRepository $vectorRepository,
        iterable $providers,
    ) {
        parent::__construct();
        $this->providers = $providers instanceof \Traversable ? iterator_to_array($providers) : $providers;
    }

    protected function configure(): void
    {
        $this->addArgument(
            'collection',
            InputArgument::OPTIONAL,
            'Limit cleanup to this collection. Omit to run every provider.',
        );
        $this->addOption(
            'allow-empty',
            null,
            InputOption::VALUE_NONE,
            'Permit a provider to report zero live identifiers, which deletes the whole collection.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filter = $input->getArgument('collection');
        $filter = is_string($filter) ? $filter : null;
        $allowEmpty = (bool) $input->getOption('allow-empty');

        if ($this->providers === []) {
            $io->warning('No orphan providers registered. Tag services with "smartsearch.orphan_provider".');
            return Command::SUCCESS;
        }

        $totalDeleted = 0;
        $ran = 0;

        foreach ($this->providers as $provider) {
            $collection = $provider->getCollection();

            if ($filter !== null && $collection !== $filter) {
                continue;
            }

            $io->section(sprintf('Collection: %s', $collection));
            $ran++;

            try {
                $deleted = $this->vectorRepository->deleteOrphans(
                    $collection,
                    $provider->getLiveIdentifiers(),
                    $allowEmpty,
                );
            } catch (\InvalidArgumentException $e) {
                // The provider reported nothing live. That is either a genuinely empty source or
                // a provider that failed to load — indistinguishable from here, and the wrong
                // guess wipes the collection. Ask rather than assume.
                $io->error($e->getMessage());
                $io->note('Re-run with --allow-empty if the source really is empty.');
                return Command::FAILURE;
            } catch (\Throwable $e) {
                $io->error(sprintf('Provider for "%s" failed: %s', $collection, $e->getMessage()));
                return Command::FAILURE;
            }

            $totalDeleted += $deleted;
            $io->writeln(sprintf('  Deleted %d orphaned vector(s).', $deleted));
        }

        if ($filter !== null && $ran === 0) {
            $io->warning(sprintf('No provider is registered for collection "%s".', $filter));
            return Command::SUCCESS;
        }

        $io->success(sprintf('Cleanup complete. %d orphaned vector(s) removed.', $totalDeleted));

        return Command::SUCCESS;
    }
}
