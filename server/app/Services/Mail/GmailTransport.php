<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\MailboxListHeader;
use Symfony\Component\Mime\Message;

/**
 * Mail over the Gmail API's HTTPS endpoint (users.messages.send).
 *
 * Exists because Render's free plan blocks outbound SMTP ports. Registered as
 * the `gmail` mail transport, so every Mailable, `Mail::fake()` and the
 * `mail:test-send` check work unchanged; only MAIL_MAILER selects it.
 *
 * Failures throw TransportException, exactly as SMTP does, so a code that
 * cannot be delivered fails its request rather than appearing to succeed.
 */
class GmailTransport extends AbstractTransport
{
    public function __construct(
        private GmailOAuth $oauth,
        private string $endpoint,
        private int $timeoutSeconds = 10,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (! $this->oauth->isConfigured()) {
            throw new TransportException('MAIL_MAILER is gmail, but GMAIL_CLIENT_ID, GMAIL_CLIENT_SECRET or GMAIL_REFRESH_TOKEN is empty.');
        }

        $payload = ['raw' => rtrim(strtr(base64_encode($this->rawMessage($message)), '+/', '-_'), '=')];

        $response = $this->post($payload, $this->oauth->accessToken());

        // The cached token can be revoked before it expires; one fresh attempt.
        if ($response->status() === 401) {
            $response = $this->post($payload, $this->oauth->accessToken(fresh: true));
        }

        if (! $response->successful()) {
            throw new TransportException('Gmail rejected the message (HTTP '.$response->status().'): '.$this->errorText($response));
        }

        if (is_string($id = $response->json('id'))) {
            $message->setMessageId($id);
        }
    }

    public function __toString(): string
    {
        return 'gmail+api://default';
    }

    private function post(array $payload, string $token): Response
    {
        try {
            return Http::withToken($token)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(5)
                ->post($this->endpoint, $payload);
        } catch (ConnectionException $e) {
            throw new TransportException('Could not reach the Gmail API: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * The message as Gmail must receive it.
     *
     * Symfony strips Bcc from the rendered message and leaves those recipients
     * on the envelope, which suits SMTP. Gmail reads recipients from the headers
     * instead, so anyone on the envelope but not in To or Cc is written back as
     * Bcc - Gmail removes that header before delivery.
     */
    private function rawMessage(SentMessage $message): string
    {
        $raw = $message->toString();
        $original = $message->getOriginalMessage();

        $visible = [];

        if ($original instanceof Message) {
            foreach (['To', 'Cc'] as $name) {
                $header = $original->getHeaders()->get($name);

                if ($header instanceof MailboxListHeader) {
                    foreach ($header->getAddresses() as $address) {
                        $visible[strtolower($address->getAddress())] = true;
                    }
                }
            }
        }

        $hidden = array_filter(
            $message->getEnvelope()->getRecipients(),
            fn (Address $address) => ! isset($visible[strtolower($address->getAddress())])
        );

        if ($hidden === []) {
            return $raw;
        }

        return 'Bcc: '.implode(', ', array_map(fn (Address $address) => $address->getAddress(), $hidden))."\r\n".$raw;
    }

    private function errorText(Response $response): string
    {
        $message = $response->json('error.message');

        return is_string($message) ? $message : 'no parsable error detail';
    }
}
