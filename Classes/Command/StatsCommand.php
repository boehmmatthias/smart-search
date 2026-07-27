<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Command;

use BoehmMatthias\SmartSearch\Repository\VectorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'smartsearch:stats',
    description: 'Show collection names, vector counts, and last-indexed timestamps.',
)]
final class StatsCommand extends Command
{
    public function __construct(
        private readonly VectorRepository $vectorRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stats = $this->vectorRepository->getCollectionStats();

        if ($stats === []) {
            $io->note('No collections indexed yet.');
            return Command::SUCCESS;
        }

        $io->table(
            ['Collection', 'Vectors', 'Last indexed'],
            array_map(static fn(array $s): array => [
                $s['collection'],
                number_format($s['count']),
                $s['last_indexed'] > 0 ? date('Y-m-d H:i:s', $s['last_indexed']) : '-',
            ], $stats),
        );

        return Command::SUCCESS;
    }
}
