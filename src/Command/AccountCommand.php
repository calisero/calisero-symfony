<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Command;

use Calisero\SymfonySms\SmsClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'calisero:account', description: 'Show the configured account: credit, status and daily sending limit')]
final class AccountCommand extends Command
{
    use RendersApiOutput;

    public function __construct(
        private readonly SmsClientInterface $client,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $account = $this->client->getAccount();
        } catch (\Throwable $e) {
            return $this->renderFailure($io, $e, 'Account not found');
        }

        $dailyLimit = $account->getDailyLimit();

        $io->table(['Property', 'Value'], [
            ['ID', $account->getId()],
            ['Name', $account->getName()],
            ['Status', $account->getStatus()],
            ['Sandbox', $account->isSandbox() ? 'Yes' : 'No'],
            ['Credit', (string) $account->getCredit()],
            ['Daily Limit', null !== $dailyLimit ? (string) $dailyLimit : 'None'],
            ['Daily Remaining', null !== $dailyLimit ? (string) $account->getDailyRemaining() : '—'],
            ['Sent Today', (string) $account->getSentToday()],
        ]);

        if (null !== $dailyLimit) {
            $io->writeln('The daily limit resets at midnight, Romania time.');
        }

        return Command::SUCCESS;
    }
}
