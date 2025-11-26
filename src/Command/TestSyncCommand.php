<?php

namespace App\Command;

use App\Message\SyncOrdersMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:test-sync',
    description: 'Test the sync message dispatch',
)]
class TestSyncCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('boutique', 'b', InputOption::VALUE_OPTIONAL, 'Boutique ID', null)
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Number of days', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $boutiqueId = $input->getOption('boutique');
        $days = (int) $input->getOption('days');

        $io->info(sprintf('Dispatching SyncOrdersMessage for boutique=%s, days=%d', $boutiqueId ?? 'all', $days));

        $message = new SyncOrdersMessage(
            boutiqueId: $boutiqueId ? (int) $boutiqueId : null,
            days: $days
        );

        $this->bus->dispatch($message);

        $io->success('Message dispatched successfully!');

        return Command::SUCCESS;
    }
}
