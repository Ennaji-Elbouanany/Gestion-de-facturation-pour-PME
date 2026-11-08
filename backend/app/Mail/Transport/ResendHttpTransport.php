<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class ResendHttpTransport extends AbstractTransport
{
    public function __construct(private readonly string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        if (! $original instanceof Email) {
            throw new RuntimeException('Le format de cet e-mail n’est pas pris en charge.');
        }

        if ($this->apiKey === '') {
            throw new RuntimeException('Le service d’envoi d’e-mails n’est pas configuré.');
        }

        // Do not silently lose attachments: use a dedicated MIME-aware transport
        // if application mails start including files or inline images.
        if ($original->getAttachments() !== []) {
            throw new RuntimeException('Les pièces jointes ne sont pas prises en charge par ce transport.');
        }

        $from = $original->getFrom();
        $to = $this->addresses($original->getTo());
        if (count($from) !== 1 || $to === []) {
            throw new RuntimeException('L’expéditeur ou le destinataire de cet e-mail est invalide.');
        }

        $payload = [
            'from' => $this->addresses($from)[0],
            'to' => $to,
            'subject' => (string) $original->getSubject(),
        ];

        if ($original->getHtmlBody() !== null) {
            $payload['html'] = $original->getHtmlBody();
        }
        if ($original->getTextBody() !== null) {
            $payload['text'] = $original->getTextBody();
        }
        if (! isset($payload['html']) && ! isset($payload['text'])) {
            throw new RuntimeException('Cet e-mail ne contient pas de contenu.');
        }

        if ($cc = $this->addresses($original->getCc())) {
            $payload['cc'] = $cc;
        }
        if ($bcc = $this->addresses($original->getBcc())) {
            $payload['bcc'] = $bcc;
        }
        if ($replyTo = $this->addresses($original->getReplyTo())) {
            $payload['reply_to'] = $replyTo;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(12)
                ->post('https://api.resend.com/emails', $payload);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Impossible de contacter le service d’envoi d’e-mails.', 0, $exception);
        }

        if (! $response->successful() || ! is_string($response->json('id'))) {
            // Never put provider response bodies, API keys or full invite URLs in logs.
            throw new RuntimeException('Le service d’envoi d’e-mails a refusé la demande (HTTP '.$response->status().').');
        }
    }

    /** @param Address[] $addresses */
    private function addresses(array $addresses): array
    {
        return array_map(static function (Address $address): string {
            return $address->getName() === ''
                ? $address->getAddress()
                : $address->toString();
        }, $addresses);
    }

    public function __toString(): string
    {
        return 'resend-https';
    }
}
