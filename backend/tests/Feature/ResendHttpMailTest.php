<?php

namespace Tests\Feature;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class ResendHttpMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.resend.key', 'test-resend-api-key');
        config()->set('mail.from.address', 'contact@factura.example');
        config()->set('mail.from.name', 'Factura');
    }

    public function test_resend_http_mailer_sends_json_over_https_without_smtp(): void
    {
        Http::fake([
            'api.resend.com/emails' => Http::response(['id' => 'email_test_123'], 200),
        ]);

        Mail::mailer('resend_http')->raw('Votre invitation Factura', function (Message $mail): void {
            $mail->to('invited@example.test')->subject('Invitation à rejoindre Factura');
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.resend.com/emails'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer test-resend-api-key')
                && $request['from'] === 'Factura <contact@factura.example>'
                && $request['to'] === ['invited@example.test']
                && $request['subject'] === 'Invitation à rejoindre Factura'
                && $request['text'] === 'Votre invitation Factura';
        });
        Http::assertSentCount(1);
    }

    public function test_resend_http_mailer_reports_provider_rejection_without_api_key(): void
    {
        Http::fake(['api.resend.com/emails' => Http::response(['message' => 'Invalid API key'], 403)]);

        try {
            Mail::mailer('resend_http')->raw('Test', function (Message $mail): void {
                $mail->to('invited@example.test')->subject('Test');
            });
            $this->fail('The transport must report a rejected message.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 403', $exception->getMessage());
            $this->assertStringNotContainsString('test-resend-api-key', $exception->getMessage());
            $this->assertStringNotContainsString('Invalid API key', $exception->getMessage());
        }
    }

    public function test_missing_api_key_fails_before_any_outbound_request(): void
    {
        config()->set('services.resend.key', '');
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('n’est pas configuré');

        try {
            Mail::mailer('resend_http')->raw('Test', function (Message $mail): void {
                $mail->to('invited@example.test')->subject('Test');
            });
        } finally {
            Http::assertNothingSent();
        }
    }
}
