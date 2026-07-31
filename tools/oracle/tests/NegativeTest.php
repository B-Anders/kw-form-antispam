<?php

declare(strict_types=1);

namespace Kreiswolke\FormAntispam\Oracle\Tests;

use Kreiswolke\FormAntispam\Altcha\Challenge as KwChallenge;
use Kreiswolke\FormAntispam\Altcha\Protocol;
use Kreiswolke\FormAntispam\Altcha\Verification;
use Kreiswolke\FormAntispam\Altcha\Verifier as KwVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Everything that must be rejected, and with which error code.
 */
#[Group('negative')]
final class NegativeTest extends OracleTestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $solvedChallenge = null;

    /** @var array<string, mixed>|null */
    private static ?array $solvedSolution = null;

    /**
     * One solved challenge, reused across the tamper cases.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function solvedFixture(): array
    {
        if (null === self::$solvedChallenge) {
            $challenge = KwChallenge::create(self::SECRET, [
                'cost'       => self::COST,
                'expires_in' => 600,
                'data'       => 'form:42',
            ]);
            self::$solvedChallenge = $challenge;
            self::$solvedSolution  = $this->solutionArray($this->solveWithOfficial($challenge));
        }

        return [self::$solvedChallenge, (array) self::$solvedSolution];
    }

    private function assertRejected(string $expectedCode, string $payload, string $secret = self::SECRET): Verification
    {
        $result = KwVerifier::verify($secret, $payload);

        self::assertFalse($result->is_valid(), 'Expected rejection with "' . $expectedCode . '" but the payload was accepted.');
        self::assertSame($expectedCode, $result->get_error_code());

        return $result;
    }

    // ------------------------------------------------------------------
    // Sanity: the fixture itself must be accepted
    // ------------------------------------------------------------------

    #[TestDox('Control: the untampered fixture verifies')]
    public function testControlFixtureVerifies(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $result = KwVerifier::verify(self::SECRET, $this->widgetPayload($challenge, $solution));

        self::assertTrue($result->is_valid(), 'Rejected with: ' . $result->get_error_code());
    }

    // ------------------------------------------------------------------
    // Tampered parameters — each altered independently
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function tamperedParameterCases(): iterable
    {
        yield 'cost'      => ['cost', 1];
        yield 'salt'      => ['salt', '00000000000000000000000000000000'];
        yield 'nonce'     => ['nonce', 'ffffffffffffffffffffffffffffffff'];
        yield 'keyPrefix' => ['keyPrefix', 'ff'];
        yield 'keyLength' => ['keyLength', 16];
        yield 'algorithm' => ['algorithm', 'SHA-256'];
        yield 'expiresAt' => ['expiresAt', 4102444800];
    }

    #[TestDox('Tampered parameter is rejected as bad_signature')]
    #[DataProvider('tamperedParameterCases')]
    public function testTamperedParameterIsRejected(string $key, mixed $value): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $challenge['parameters'][$key] = $value;

        $this->assertRejected(Verification::ERROR_BAD_SIGNATURE, $this->widgetPayload($challenge, $solution));
    }

    #[TestDox('Tampered bound data is rejected as bad_signature')]
    public function testTamperedDataIsRejected(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $challenge['parameters']['data'] = [Protocol::DATA_KEY => 'form:999'];

        $this->assertRejected(Verification::ERROR_BAD_SIGNATURE, $this->widgetPayload($challenge, $solution));
    }

    #[TestDox('Removing the bound data entirely is rejected as bad_signature')]
    public function testStrippedDataIsRejected(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        unset($challenge['parameters']['data']);

        $this->assertRejected(Verification::ERROR_BAD_SIGNATURE, $this->widgetPayload($challenge, $solution));
    }

    #[TestDox('Injecting a keySignature is rejected as bad_signature')]
    public function testInjectedKeySignatureIsRejected(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $challenge['parameters']['keySignature'] = str_repeat('ab', 32);

        $this->assertRejected(Verification::ERROR_BAD_SIGNATURE, $this->widgetPayload($challenge, $solution));
    }

    // ------------------------------------------------------------------
    // Tampered / missing signature, wrong secret
    // ------------------------------------------------------------------

    #[TestDox('Tampered signature is rejected as bad_signature')]
    public function testTamperedSignatureIsRejected(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $sig = $challenge['signature'];
        $challenge['signature'] = ('0' === $sig[0] ? '1' : '0') . substr($sig, 1);

        $this->assertRejected(Verification::ERROR_BAD_SIGNATURE, $this->widgetPayload($challenge, $solution));
    }

    #[TestDox('Missing signature is rejected as bad_signature')]
    public function testMissingSignatureIsRejected(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $payload = $this->base64Json([
            'challenge' => ['parameters' => $challenge['parameters']],
            'solution'  => $solution,
        ]);

        $this->assertRejected(Verification::ERROR_BAD_SIGNATURE, $payload);
    }

    #[TestDox('Empty signature is rejected as bad_signature')]
    public function testEmptySignatureIsRejected(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $challenge['signature'] = '';

        $this->assertRejected(Verification::ERROR_BAD_SIGNATURE, $this->widgetPayload($challenge, $solution));
    }

    #[TestDox('A perfectly valid solution is rejected under the WRONG secret')]
    public function testValidSolutionUnderWrongSecretIsRejected(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $this->assertRejected(
            Verification::ERROR_BAD_SIGNATURE,
            $this->widgetPayload($challenge, $solution),
            self::OTHER_SECRET,
        );
    }

    #[TestDox('An empty secret never verifies anything (no silent degradation)')]
    public function testEmptySecretRejectsEverything(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $this->assertRejected(Verification::ERROR_BAD_SIGNATURE, $this->widgetPayload($challenge, $solution), '');

        // ...and a challenge "signed" with the empty secret is not a way in either.
        $forged = $this->signOurs($challenge['parameters'], '');
        $this->assertRejected(Verification::ERROR_BAD_SIGNATURE, $this->widgetPayload($forged, $solution), '');
    }

    #[TestDox('Challenge::create() refuses to mint anything without a secret')]
    public function testCreateRejectsEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KwChallenge::create('', ['cost' => self::COST]);
    }

    // ------------------------------------------------------------------
    // Expiry
    // ------------------------------------------------------------------

    #[TestDox('An expired but correctly signed and solved challenge is rejected as expired')]
    public function testExpiredChallengeIsRejected(): void
    {
        $expiresAt = time() - 10;

        $challenge = $this->signOurs([
            'algorithm' => Protocol::ALGORITHM,
            'cost'      => self::COST,
            'expiresAt' => $expiresAt,
            'keyLength' => Protocol::KEY_LENGTH,
            'keyPrefix' => Protocol::KEY_PREFIX,
            'nonce'     => bin2hex(random_bytes(16)),
            'salt'      => bin2hex(random_bytes(16)),
        ]);

        $solution = $this->solutionArray($this->solveWithOfficial($challenge));

        $result = $this->assertRejected(Verification::ERROR_EXPIRED, $this->widgetPayload($challenge, $solution));

        self::assertSame($expiresAt, $result->get_expires_at());
        self::assertSame($challenge['parameters']['nonce'], $result->get_replay_key());
    }

    #[TestDox('A correctly signed challenge with NO expiry is rejected as expired (fail closed)')]
    public function testMissingExpiryIsRejected(): void
    {
        $challenge = $this->signOurs([
            'algorithm' => Protocol::ALGORITHM,
            'cost'      => self::COST,
            'keyLength' => Protocol::KEY_LENGTH,
            'keyPrefix' => Protocol::KEY_PREFIX,
            'nonce'     => bin2hex(random_bytes(16)),
            'salt'      => bin2hex(random_bytes(16)),
        ]);

        $solution = $this->solutionArray($this->solveWithOfficial($challenge));

        $this->assertRejected(Verification::ERROR_EXPIRED, $this->widgetPayload($challenge, $solution));
    }

    #[TestDox('Expiry lives inside the signed parameters, so pushing it out fails the signature')]
    public function testExpiryCannotBeExtendedByTheClient(): void
    {
        $challenge = $this->signOurs([
            'algorithm' => Protocol::ALGORITHM,
            'cost'      => self::COST,
            'expiresAt' => time() - 10,
            'keyLength' => Protocol::KEY_LENGTH,
            'keyPrefix' => Protocol::KEY_PREFIX,
            'nonce'     => bin2hex(random_bytes(16)),
            'salt'      => bin2hex(random_bytes(16)),
        ]);

        $solution = $this->solutionArray($this->solveWithOfficial($challenge));

        $challenge['parameters']['expiresAt'] = time() + 3600;

        $this->assertRejected(Verification::ERROR_BAD_SIGNATURE, $this->widgetPayload($challenge, $solution));
    }

    // ------------------------------------------------------------------
    // Test-mode payload
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function widgetTestModePayloads(): iterable
    {
        // Exactly what Widget.svelte emits when config.test is true.
        yield 'widget test payload' => [base64_encode('{"challenge":null,"solution":null,"test":true}')];
        yield 'test alongside a real challenge' => [base64_encode('{"challenge":{"parameters":{}},"solution":{"counter":1,"derivedKey":"aa"},"test":true}')];
        yield 'test as string' => [base64_encode('{"challenge":null,"solution":null,"test":"true"}')];
        yield 'test as 1' => [base64_encode('{"challenge":null,"solution":null,"test":1}')];
    }

    #[TestDox('test:true payloads are rejected as test_mode')]
    #[DataProvider('widgetTestModePayloads')]
    public function testTestModePayloadIsRejected(string $payload): void
    {
        $this->assertRejected(Verification::ERROR_TEST_MODE, $payload);
    }

    #[TestDox('test:false does not trip the test-mode guard')]
    public function testTestFalseIsNotTestMode(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $payload = $this->base64Json([
            'challenge' => ['parameters' => $challenge['parameters'], 'signature' => $challenge['signature']],
            'solution'  => $solution,
            'test'      => false,
        ]);

        self::assertTrue(KwVerifier::verify(self::SECRET, $payload)->is_valid());
    }

    // ------------------------------------------------------------------
    // Bad solutions
    // ------------------------------------------------------------------

    #[TestDox('A flipped derivedKey is rejected as bad_solution')]
    public function testWrongDerivedKeyIsRejected(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $key = $solution['derivedKey'];
        $solution['derivedKey'] = substr($key, 0, -1) . ('a' === substr($key, -1) ? 'b' : 'a');

        $this->assertRejected(Verification::ERROR_BAD_SOLUTION, $this->widgetPayload($challenge, $solution));
    }

    #[TestDox('The right derivedKey with the wrong counter is rejected as bad_solution')]
    public function testWrongCounterIsRejected(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $solution['counter'] = $solution['counter'] + 1;

        $this->assertRejected(Verification::ERROR_BAD_SOLUTION, $this->widgetPayload($challenge, $solution));
    }

    #[TestDox('An honest derivation that does not meet keyPrefix is rejected as bad_solution')]
    public function testHonestDerivationWithoutPrefixIsRejected(): void
    {
        [$challenge] = $this->solvedFixture();

        $params    = $challenge['parameters'];
        $nonceBin  = (string) hex2bin($params['nonce']);
        $saltBin   = (string) hex2bin($params['salt']);
        $prefixBin = (string) hex2bin($params['keyPrefix']);

        // Find a counter whose derived key is correct but does NOT start with keyPrefix.
        $counter = 0;
        $derived = '';
        for ($i = 0; $i < 1000; $i++) {
            $derived = Protocol::derive_key($params['cost'], $params['keyLength'], $saltBin, Protocol::password($nonceBin, $i));
            if (substr($derived, 0, strlen($prefixBin)) !== $prefixBin) {
                $counter = $i;
                break;
            }
        }
        self::assertNotSame('', $derived);

        $solution = ['counter' => $counter, 'derivedKey' => bin2hex($derived), 'time' => 1.0];

        $this->assertRejected(Verification::ERROR_BAD_SOLUTION, $this->widgetPayload($challenge, $solution));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: mixed, 2: string}>
     */
    public static function malformedSolutionCases(): iterable
    {
        yield 'counter as string'      => ['5', str_repeat('ab', 32), Verification::ERROR_MALFORMED];
        yield 'counter as float'       => [5.5, str_repeat('ab', 32), Verification::ERROR_MALFORMED];
        yield 'counter missing'        => [null, str_repeat('ab', 32), Verification::ERROR_MALFORMED];
        yield 'derivedKey as int'      => [1, 12345, Verification::ERROR_MALFORMED];
        yield 'derivedKey missing'     => [1, null, Verification::ERROR_MALFORMED];
        yield 'counter negative'       => [-1, str_repeat('ab', 32), Verification::ERROR_BAD_SOLUTION];
        yield 'counter beyond uint32'  => [4294967296, str_repeat('ab', 32), Verification::ERROR_BAD_SOLUTION];
        yield 'derivedKey uppercase'   => [1, str_repeat('AB', 32), Verification::ERROR_BAD_SOLUTION];
        yield 'derivedKey wrong length'=> [1, str_repeat('ab', 16), Verification::ERROR_BAD_SOLUTION];
        yield 'derivedKey not hex'     => [1, str_repeat('zz', 32), Verification::ERROR_BAD_SOLUTION];
        yield 'derivedKey empty'       => [1, '', Verification::ERROR_BAD_SOLUTION];
    }

    #[TestDox('Malformed solution field is rejected')]
    #[DataProvider('malformedSolutionCases')]
    public function testMalformedSolutionIsRejected(mixed $counter, mixed $derivedKey, string $expected): void
    {
        [$challenge] = $this->solvedFixture();

        $solution = [];
        if (null !== $counter) {
            $solution['counter'] = $counter;
        }
        if (null !== $derivedKey) {
            $solution['derivedKey'] = $derivedKey;
        }
        if ([] === $solution) {
            $solution = ['x' => 1];
        }

        $this->assertRejected($expected, $this->widgetPayload($challenge, $solution));
    }

    // ------------------------------------------------------------------
    // Signature-valid but structurally impossible parameters
    // ------------------------------------------------------------------

    /**
     * The keyPrefix landmine: an odd-length prefix makes hex2bin() return false, the
     * comparison degenerate to a zero-length match, and the proof of work vanish.
     * Upstream silently accepts this. We must not.
     */
    #[TestDox('A correctly signed challenge with an odd-length keyPrefix is rejected')]
    public function testOddLengthKeyPrefixIsRejected(): void
    {
        $challenge = $this->signOurs([
            'algorithm' => Protocol::ALGORITHM,
            'cost'      => self::COST,
            'expiresAt' => time() + 600,
            'keyLength' => Protocol::KEY_LENGTH,
            'keyPrefix' => '0',
            'nonce'     => bin2hex(random_bytes(16)),
            'salt'      => bin2hex(random_bytes(16)),
        ]);

        $solution = ['counter' => 0, 'derivedKey' => str_repeat('ab', 32), 'time' => 1.0];

        $this->assertRejected(Verification::ERROR_MALFORMED, $this->widgetPayload($challenge, $solution));
    }

    #[TestDox('A correctly signed challenge with an EMPTY keyPrefix is rejected')]
    public function testEmptyKeyPrefixIsRejected(): void
    {
        $challenge = $this->signOurs([
            'algorithm' => Protocol::ALGORITHM,
            'cost'      => self::COST,
            'expiresAt' => time() + 600,
            'keyLength' => Protocol::KEY_LENGTH,
            'keyPrefix' => '',
            'nonce'     => bin2hex(random_bytes(16)),
            'salt'      => bin2hex(random_bytes(16)),
        ]);

        $solution = ['counter' => 0, 'derivedKey' => str_repeat('ab', 32), 'time' => 1.0];

        $this->assertRejected(Verification::ERROR_MALFORMED, $this->widgetPayload($challenge, $solution));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function impossibleParameterCases(): iterable
    {
        $base = [
            'algorithm' => 'PBKDF2/SHA-256',
            'cost'      => 200,
            'expiresAt' => 0, // replaced below
            'keyLength' => 32,
            'keyPrefix' => '00',
            'nonce'     => '39baf91a9e3b5b2e6f2c0a1d4e8f7c6b',
            'salt'      => '5e00d5d1c2b3a4958677564534231201',
        ];

        yield 'SHA KDF (never issued)'   => [array_merge($base, ['algorithm' => 'SHA-256'])];
        yield 'unknown algorithm'        => [array_merge($base, ['algorithm' => 'ARGON2ID'])];
        yield 'deterministic keySignature' => [array_merge($base, ['keySignature' => str_repeat('cd', 32)])];
        yield 'cost above the cap'       => [array_merge($base, ['cost' => 250001])];
        yield 'cost zero'                => [array_merge($base, ['cost' => 0])];
        yield 'keyLength zero'           => [array_merge($base, ['keyLength' => 0])];
        yield 'keyLength absurd'         => [array_merge($base, ['keyLength' => 4096])];
        yield 'odd-length nonce'         => [array_merge($base, ['nonce' => 'abc'])];
        yield 'non-hex nonce'            => [array_merge($base, ['nonce' => 'zzzz'])];
        yield 'uppercase nonce'          => [array_merge($base, ['nonce' => 'AABBCCDDEEFF00112233445566778899'])];
        yield 'empty salt'               => [array_merge($base, ['salt' => ''])];
        yield 'odd-length salt'          => [array_merge($base, ['salt' => 'abc'])];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[TestDox('Signature-valid but structurally impossible parameters are rejected as malformed')]
    #[DataProvider('impossibleParameterCases')]
    public function testImpossibleParametersAreRejected(array $parameters): void
    {
        $parameters['expiresAt'] = time() + 600;

        $challenge = $this->signOurs($parameters);
        $solution  = ['counter' => 0, 'derivedKey' => str_repeat('ab', 32), 'time' => 1.0];

        $this->assertRejected(Verification::ERROR_MALFORMED, $this->widgetPayload($challenge, $solution));
    }

    // ------------------------------------------------------------------
    // Garbage input
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function garbageInputs(): iterable
    {
        yield 'empty string'          => [''];
        yield 'whitespace only'       => ["  \n\t "];
        yield 'not base64'            => ['@@@@ not base64 @@@@'];
        yield 'base64 of garbage'     => [base64_encode('this is not json at all')];
        yield 'base64 of broken json' => [base64_encode('{"challenge":{')];
        yield 'json null'             => [base64_encode('null')];
        yield 'json true'             => [base64_encode('true')];
        yield 'json number'           => [base64_encode('12345')];
        yield 'json string'           => [base64_encode('"hello"')];
        yield 'json empty object'     => [base64_encode('{}')];
        yield 'json empty array'      => [base64_encode('[]')];
        yield 'json list'             => [base64_encode('["challenge","solution"]')];
        yield 'challenge missing'     => [base64_encode('{"solution":{"counter":1,"derivedKey":"ab"}}')];
        yield 'solution missing'      => [base64_encode('{"challenge":{"parameters":{},"signature":"ab"}}')];
        yield 'challenge is null'     => [base64_encode('{"challenge":null,"solution":{"counter":1,"derivedKey":"ab"}}')];
        yield 'solution is null'      => [base64_encode('{"challenge":{"parameters":{},"signature":"ab"},"solution":null}')];
        yield 'challenge is a string' => [base64_encode('{"challenge":"nope","solution":{"counter":1,"derivedKey":"ab"}}')];
        yield 'parameters missing'    => [base64_encode('{"challenge":{"signature":"ab"},"solution":{"counter":1,"derivedKey":"ab"}}')];
        yield 'parameters is a string'=> [base64_encode('{"challenge":{"parameters":"x","signature":"ab"},"solution":{"counter":1,"derivedKey":"ab"}}')];
        yield 'nul bytes'             => [base64_encode("\0\0\0\0")];
        yield 'utf-16 junk'           => [base64_encode("\xff\xfe{\x00}\x00")];
    }

    #[TestDox('Garbage input is rejected as malformed, never as an exception')]
    #[DataProvider('garbageInputs')]
    public function testGarbageIsRejected(string $payload): void
    {
        $this->assertRejected(Verification::ERROR_MALFORMED, $payload);
    }

    #[TestDox('Absurdly large input is rejected without doing any work')]
    public function testAbsurdlyLargeInputIsRejected(): void
    {
        $huge = base64_encode(str_repeat('A', 4 * 1024 * 1024));
        self::assertGreaterThan(Protocol::MAX_PAYLOAD_BYTES, strlen($huge));

        $start = microtime(true);
        $this->assertRejected(Verification::ERROR_MALFORMED, $huge);
        self::assertLessThan(0.5, microtime(true) - $start, 'The size guard must short-circuit before decoding.');
    }

    #[TestDox('A JSON nesting bomb is rejected as malformed')]
    public function testDeeplyNestedJsonIsRejected(): void
    {
        $json = str_repeat('[', 200) . str_repeat(']', 200);

        $this->assertRejected(Verification::ERROR_MALFORMED, base64_encode($json));
    }

    #[TestDox('A challenge JSON that is 32 KiB of bound data is still bounded')]
    public function testOversizedButWellFormedPayloadIsRejected(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $challenge['parameters']['data'] = [Protocol::DATA_KEY => str_repeat('x', 40000)];

        $this->assertRejected(Verification::ERROR_MALFORMED, $this->widgetPayload($challenge, $solution));
    }

    // ------------------------------------------------------------------
    // Replay key
    // ------------------------------------------------------------------

    #[TestDox('The replay key is the challenge nonce and is stable across verifications')]
    public function testReplayKeyIsStable(): void
    {
        [$challenge, $solution] = $this->solvedFixture();

        $payload = $this->widgetPayload($challenge, $solution);

        $a = KwVerifier::verify(self::SECRET, $payload);
        $b = KwVerifier::verify(self::SECRET, $payload);

        self::assertTrue($a->is_valid());
        self::assertTrue($b->is_valid());
        self::assertSame($a->get_replay_key(), $b->get_replay_key());
        self::assertSame($challenge['parameters']['nonce'], $a->get_replay_key());
        self::assertSame(32, strlen($a->get_replay_key()));
    }

    #[TestDox('Distinct challenges get distinct replay keys')]
    public function testReplayKeysAreUnique(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $challenge = KwChallenge::create(self::SECRET, ['cost' => 1]);
            $seen[]    = $challenge['parameters']['nonce'];
        }

        self::assertCount(200, array_unique($seen));
    }
}
