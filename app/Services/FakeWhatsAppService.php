<?php

namespace App\Services;

use PHPUnit\Framework\Assert;

/**
 * In-memory WhatsApp stand-in for tests.
 *
 * Bind via app()->instance(WhatsAppService::class, new FakeWhatsAppService())
 * or use the TestCase::fakeWhatsApp() helper.
 */
class FakeWhatsAppService extends WhatsAppService
{
    /** @var list<array{to: string|null, code: string}> */
    public array $authMessages = [];

    /** @var list<array{to: string|null, message: string}> */
    public array $messages = [];

    public function sendAuthMessage(?string $to, string $code): ?array
    {
        $this->authMessages[] = ['to' => $to, 'code' => $code];
        return ['messages' => [['id' => 'fake-wamid']]];
    }

    public function sendMessage(?string $to, string $message): ?array
    {
        $this->messages[] = ['to' => $to, 'message' => $message];
        return ['messages' => [['id' => 'fake-wamid']]];
    }

    public function assertAuthMessageSentTo(string $phone): void
    {
        $sent = array_filter($this->authMessages, fn ($m) => $m['to'] === $phone);
        Assert::assertNotEmpty($sent, "Expected WhatsApp auth message to [{$phone}] was not sent.");
    }

    public function assertAuthMessageSentWith(string $phone, string $code): void
    {
        $sent = array_filter($this->authMessages, fn ($m) => $m['to'] === $phone && $m['code'] === $code);
        Assert::assertNotEmpty($sent, "Expected WhatsApp auth message to [{$phone}] with code [{$code}] was not sent.");
    }

    public function assertMessageSentTo(string $phone): void
    {
        $sent = array_filter($this->messages, fn ($m) => $m['to'] === $phone);
        Assert::assertNotEmpty($sent, "Expected WhatsApp message to [{$phone}] was not sent.");
    }

    public function assertMessageContaining(string $phone, string $substring): void
    {
        $sent = array_filter($this->messages, fn ($m) => $m['to'] === $phone && str_contains((string) $m['message'], $substring));
        Assert::assertNotEmpty($sent, "Expected WhatsApp message to [{$phone}] containing [{$substring}] was not sent.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->authMessages, 'Expected no WhatsApp auth messages to be sent.');
        Assert::assertEmpty($this->messages, 'Expected no WhatsApp messages to be sent.');
    }
}
