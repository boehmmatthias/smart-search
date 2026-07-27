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
    name: 'smartsearch:clear',
    description: 'Delete all stored vectors for a collection.',
)]
final class ClearCollectionCommand extends Command
{
    public function __construct(
        private readonly VectorRepository $vectorRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('collection', InputArgument::REQUIRED, 'Collection name to clear.');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation prompt.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $collection = (string) $input->getArgument('collection');

        // Deleting vectors is not recoverable from inside this extension: it does not know the
        // source records, so restoring them means the consuming extension re-running its own
        // indexer against a live embedding server.
        if (!$input->getOption('yes')
            && !$io->confirm(sprintf('Delete all vectors in collection "%s"?', $collection), false)
        ) {
            $io->note('Aborted.');
            return Command::SUCCESS;
        }

        $this->vectorRepository->deleteByCollection($collection);
        $io->success(sprintf('Collection "%s" cleared.', $collection));

        return Command::SUCCESS;
    }
}
