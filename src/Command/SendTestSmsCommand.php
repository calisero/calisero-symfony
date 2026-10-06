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

#[AsCommand(name: 'calisero:sms:test', description: 'Send a test SMS message (validation handled by the Calisero API)')]
final class SendTestSmsCommand extends Command
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
            ->addArgument('to', InputArgument::REQUIRED, 'Recipient phone number, in E.164 format')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'An approved sender ID')
            ->addOption('text', null, InputOption::VALUE_REQUIRED, 'Message text', 'Hello from Calisero')
            ->addOption('visible-body', null, InputOption::VALUE_REQUIRED, 'The body shown in the dashboard and the API instead of the text')
            ->addOption('validity', null, InputOption::VALUE_REQUIRED, 'Validity period, in hours')
            ->addOption('schedule-at', null, InputOption::VALUE_REQUIRED, 'Send later: Y-m-d H:i:s, in Romania time')
            ->addOption('callback-url', null, InputOption::VALUE_REQUIRED, 'The URL Calisero posts the delivery status to')
            ->addOption('shorten-urls', null, InputOption::VALUE_NONE, 'Shorten the links of the text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $params = [
            'to' => $input->getArgument('to'),
            'text' => $input->getOption('text'),
        ];

        foreach (['from' => 'from', 'visible-body' => 'visible_body', 'validity' => 'validity', 'schedule-at' => 'schedule_at', 'callback-url' => 'callback_url'] as $option => $param) {
            $value = $input->getOption($option);

            if (null !== $value && '' !== $value) {
                $params[$param] = $value;
            }
        }

        if ($input->getOption('shorten-urls')) {
            $params['shorten_urls'] = true;
        }

        $io->writeln('Creating SMS message...');

        try {
            $response = $this->client->sendSms($params);
        } catch (\Throwable $e) {
            return $this->renderFailure($io, $e);
        }

        $message = $response->getData();

        $io->success('SMS created');
        $io->table(['Property', 'Value'], [...$this->messageRows($message), ...$this->responseMetaRows($response->getResponseMeta())]);
        $this->renderShortenedUrls($io, $message->getShortenedUrls());

        return Command::SUCCESS;
    }
}
