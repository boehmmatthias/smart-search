<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'smartsearch:reindex',
    description: 'Run registered reindex handlers to rebuild vector embeddings.',
)]
final class ReindexCommand extends Command
{
    /** @var ReindexCommandInterface[] */
    private readonly array $handlers;

    /**
     * @param iterable<ReindexCommandInterface> $handlers Services tagged smartsearch.reindex_handler.
     */
    public function __construct(iterable $handlers)
    {
        parent::__construct();
        $this->handlers = $handlers instanceof \Traversable ? iterator_to_array($handlers) : $handlers;
    }

    protected function configure(): void
    {
        $this->addArgument(
            'collection',
            InputArgument::OPTIONAL,
            'Limit reindexing to this collection. Omit to run every handler.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filter = $input->getArgument('collection');
        $filter = is_string($filter) ? $filter : null;

        if ($this->handlers === []) {
            $io->warning('No reindex handlers registered. Tag services with "smartsearch.reindex_handler".');
            return Command::SUCCESS;
        }

        $ran = 0;
        foreach ($this->handlers as $handler) {
            if ($filter !== null && $handler->getCollection() !== $filter) {
                continue;
            }

            $io->section(sprintf('%s [%s]', $handler->getLabel(), $handler->getCollection()));
            $ran++;

            try {
                $io->success(sprintf('Indexed %d record(s).', $handler->reindex()));
            } catch (\Throwable $e) {
                // One handler failing should not be reported as a successful run, but neither
                // should it hide the handlers that did work.
                $io->error(sprintf('Handler failed: %s', $e->getMessage()));
                return Command::FAILURE;
            }
        }

        if ($filter !== null && $ran === 0) {
            $io->warning(sprintf('No handler is registered for collection "%s".', $filter));
        }

        return Command::SUCCESS;
    }
}
