<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Command;

use Calisero\SymfonySms\SmsClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'calisero:verification:send', description: 'Send a verification code (all input validated by the Calisero API)')]
final class SendVerificationCommand extends Command
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
            ->addArgument('to', InputArgument::REQUIRED, 'The recipient phone number, in E.164 format')
            ->addOption('brand', null, InputOption::VALUE_REQUIRED, 'The brand named in the default message')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'A message template containing {code}')
            ->addOption('expires-in', null, InputOption::VALUE_REQUIRED, 'Code expiration time, in minutes (1 to 10)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = $input->getArgument('to');
        \assert(\is_string($to));

        $params = ['to' => $to];

        foreach (['brand' => 'brand', 'template' => 'template', 'expires-in' => 'expires_in'] as $option => $param) {
            $value = $input->getOption($option);

            if (null !== $value && '' !== $value) {
                $params[$param] = $value;
            }
        }

        $io->writeln("Sending verification code to {$to}...");

        try {
            $response = $this->client->sendVerification($params);
        } catch (\Throwable $e) {
            return $this->renderFailure($io, $e);
        }

        $io->success('Verification code sent');
        $io->table(['Property', 'Value'], [...$this->verificationRows($response->getData()), ...$this->responseMetaRows($response->getResponseMeta())]);

        return Command::SUCCESS;
    }
}
