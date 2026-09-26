<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

use A2A\Client\A2ACardResolver;
use A2A\Laravel\Tests\TestCase;
use A2A\Types\AgentCard;
use A2A\Utils\Signing;

final class CardSigningTest extends TestCase
{
    private const KEY = 'laravel-test-signing-key-laravel-test-signing-key-0123456789abcd';

    public function testTheCardIsServedSignedWhenAKeyIsConfigured(): void
    {
        config(['a2a.signing.key' => self::KEY, 'a2a.signing.alg' => 'HS256', 'a2a.signing.kid' => 'test-key']);

        $data = $this->get('/.well-known/agent-card.json')->assertOk()->json();
        self::assertIsArray($data);
        self::assertCount(1, $data['signatures'] ?? []);

        $card = A2ACardResolver::parseAgentCard(json_decode((string) json_encode($data), false, 512, JSON_THROW_ON_ERROR));
        $seenKid = null;
        Signing::createSignatureVerifier(static function (?string $kid) use (&$seenKid): string {
            $seenKid = $kid;

            return self::KEY;
        }, ['HS256'])($card);
        self::assertSame('test-key', $seenKid);
    }

    public function testRepeatedRequestsDoNotAccumulateSignatures(): void
    {
        config(['a2a.signing.key' => self::KEY, 'a2a.signing.alg' => 'HS256']);

        $this->get('/.well-known/agent-card.json');
        $data = $this->get('/.well-known/agent-card.json')->json();

        self::assertIsArray($data);
        self::assertCount(1, $data['signatures'] ?? []);
    }

    public function testUnsignedByDefault(): void
    {
        $data = $this->get('/.well-known/agent-card.json')->json();

        self::assertIsArray($data);
        self::assertArrayNotHasKey('signatures', $data);
    }

    public function testTheSignerReadsAKeyFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'a2a-key-');
        self::assertIsString($file);
        file_put_contents($file, self::KEY);
        try {
            config(['a2a.signing.key' => 'file://' . $file, 'a2a.signing.alg' => 'HS256']);
            $signer = $this->app->make(\A2A\Laravel\A2AManager::class)->cardSigner();
            self::assertNotNull($signer);

            $signed = $signer(new AgentCard(['name' => 'n', 'description' => 'd', 'version' => '1']));
            Signing::createSignatureVerifier(static fn(): string => self::KEY, ['HS256'])($signed);
            $this->addToAssertionCount(1);
        } finally {
            unlink($file);
        }
    }
}
