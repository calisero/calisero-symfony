<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Command;

use Calisero\SymfonySms\SmsClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'calisero:sms:status', description: 'Fetch and display the status and details of an SMS message')]
final class StatusSmsCommand extends Command
{
    use RendersApiOutput;

    public function __construct(
        private readonly SmsClientInterface $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The SMS message ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('id');
        \assert(\is_string($id));
        $id = trim($id);

        if ('' === $id) {
            $io->error('Message id must not be empty');

            return Command::FAILURE;
        }

        $io->writeln("Retrieving SMS status for ID: {$id}...");

        try {
            $message = $this->client->getMessageStatus($id)->getData();
        } catch (\Throwable $e) {
            return $this->renderFailure($io, $e, 'Message not found');
        }

        $io->success('SMS retrieved successfully');
        $io->table(['Property', 'Value'], $this->messageRows($message));
        $this->renderShortenedUrls($io, $message->getShortenedUrls());
        $io->writeln('Status: '.$message->getStatus());

        return Command::SUCCESS;
    }
}
