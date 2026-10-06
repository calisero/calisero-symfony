<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Notifier;

use Calisero\Sms\Exceptions\ApiException;
use Calisero\SymfonySms\SmsClientInterface;
use Symfony\Component\Notifier\Event\FailedMessageEvent;
use Symfony\Component\Notifier\Event\MessageEvent;
use Symfony\Component\Notifier\Event\SentMessageEvent;
use Symfony\Component\Notifier\Exception\LogicException;
use Symfony\Component\Notifier\Exception\UnsupportedMessageTypeException;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Sends the Notifier's SMS messages through the Calisero API.
 *
 * It sends through the bundle's SmsClient, not Symfony's HttpClient, so it needs no
 * symfony/http-client; the Notifier events are dispatched as AbstractTransport does.
 * Created by CaliseroTransportFactory from a calisero:// DSN.
 */
final class CaliseroTransport implements TransportInterface
{
    public function __construct(
        private readonly SmsClientInterface $client,
        private readonly string $endpoint = 'rest.calisero.ro',
        private readonly ?string $from = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('calisero://%s', $this->endpoint).(null !== $this->from ? '?from='.$this->from : '');
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof SmsMessage
            && (null === $message->getOptions() || $message->getOptions() instanceof CaliseroOptions);
    }

    /**
     * @throws CaliseroTransportException when the API refuses the message or does not answer
     */
    public function send(MessageInterface $message): SentMessage
    {
        if (null === $this->dispatcher) {
            return $this->doSend($message);
        }

        $this->dispatcher->dispatch(new MessageEvent($message));

        try {
            $sentMessage = $this->doSend($message);
        } catch (\Throwable $error) {
            $this->dispatcher->dispatch(new FailedMessageEvent($message, $error));

            throw $error;
        }

        $this->dispatcher->dispatch(new SentMessageEvent($sentMessage));

        return $sentMessage;
    }

    private function doSend(MessageInterface $message): SentMessage
    {
        if (!$message instanceof SmsMessage) {
            throw new UnsupportedMessageTypeException(__CLASS__, SmsMessage::class, $message);
        }

        $options = $message->getOptions();

        if (null !== $options && !$options instanceof CaliseroOptions) {
            throw new LogicException(\sprintf('The "%s" transport only supports instances of "%s" for options.', __CLASS__, CaliseroOptions::class));
        }

        $params = ['to' => $message->getPhone(), 'text' => $message->getSubject()] + ($options?->toArray() ?? []);

        $from = '' !== $message->getFrom() ? $message->getFrom() : $this->from;

        if (null !== $from && '' !== $from) {
            $params['from'] = $from;
        }

        try {
            $response = $this->client->sendSms($params);
        } catch (ApiException $e) {
            throw new CaliseroTransportException($e);
        }

        // The SDK's answer, for SentMessageEvent listeners: parts, status, daily limit left...
        // (SentMessage::getInfo(), Symfony 7.3+; an older SentMessage ignores the argument)
        $sentMessage = new SentMessage($message, (string) $this, ['response' => $response]);
        $sentMessage->setMessageId($response->getData()->getId());

        return $sentMessage;
    }
}
