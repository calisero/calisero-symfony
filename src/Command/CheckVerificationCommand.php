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

#[AsCommand(name: 'calisero:verification:check', description: 'Check a verification code (validation handled by the Calisero API)')]
final class CheckVerificationCommand extends Command
{
    use RendersApiOutput;

    public function __construct(
        private readonly SmsClientInterface $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('to', InputArgument::REQUIRED, 'The phone number, in E.164 format')
            ->addArgument('code', InputArgument::REQUIRED, 'The verification code');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = $input->getArgument('to');
        $code = $input->getArgument('code');
        \assert(\is_string($to) && \is_string($code));

        $io->writeln("Verifying code for {$to}...");

        try {
            $verification = $this->client->checkVerification(['to' => $to, 'code' => $code])->getData();
        } catch (\Throwable $e) {
            return $this->renderFailure($io, $e, 'Verification not found');
        }

        $verified = 'verified' === $verification->getStatus();

        if ($verified) {
            $io->success('Code verified');
        } else {
            $io->warning('Code not verified');
        }

        $io->table(['Property', 'Value'], $this->verificationRows($verification));

        return $verified ? Command::SUCCESS : Command::FAILURE;
    }
}
