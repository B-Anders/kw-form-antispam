<?php

declare(strict_types=1);

namespace Kreiswolke\FormAntispam\Oracle\Tests;

use AltchaOrg\Altcha\Challenge as OfficialChallenge;
use AltchaOrg\Altcha\ChallengeParameters as OfficialChallengeParameters;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload as OfficialPayload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Kreiswolke\FormAntispam\Altcha\Challenge as KwChallenge;
use Kreiswolke\FormAntispam\Altcha\Protocol;
use Kreiswolke\FormAntispam\Altcha\Verifier as KwVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The four directions of the differential matrix.
 */
#[Group('differential')]
final class DifferentialTest extends OracleTestCase
{
    // ------------------------------------------------------------------
    // Direction 1: ours creates -> official solves -> ours verifies
    // ------------------------------------------------------------------

    #[TestDox('Direction 1: our challenge, solved by the official library, is accepted by our verifier')]
    public function testDirection1OursCreateOfficialSolveOursVerify(): void
    {
        $challenge = KwChallenge::create(self::SECRET, [
            'cost'       => self::COST,
            'expires_in' => 300,
            'data'       => 'form:42|post:7',
        ]);

        $solution = $this->solveWithOfficial($challenge);
        $payload  = $this->widgetPayload($challenge, $this->solutionArray($solution));

        $result = KwVerifier::verify(self::SECRET, $payload);

        self::assertTrue($result->is_valid(), 'Rejected with: ' . $result->get_error_code());
        self::assertSame('', $result->get_error_code());
        self::assertSame($challenge['parameters']['nonce'], $result->get_replay_key());
        self::assertSame('form:42|post:7', $result->get_data());
        self::assertSame($challenge['parameters']['expiresAt'], $result->get_expires_at());
    }

    #[TestDox('Direction 1 also works with no bound data')]
    public function testDirection1WithoutData(): void
    {
        $challenge = KwChallenge::create(self::SECRET, ['cost' => self::COST]);

        self::assertArrayNotHasKey('data', $challenge['parameters'], 'Empty data must be omitted, not emitted as an empty object.');

        $solution = $this->solveWithOfficial($challenge);
        $result   = KwVerifier::verify(self::SECRET, $this->widgetPayload($challenge, $this->solutionArray($solution)));

        self::assertTrue($result->is_valid(), 'Rejected with: ' . $result->get_error_code());
        self::assertSame('', $result->get_data());
    }

    /**
     * The widget echoes `parameters` verbatim, but a proxy, a JSON re-serialiser or
     * an attacker can reorder or pad it. Neither must matter: we re-project onto the
     * fixed schema before re-signing, exactly as the official library does.
     */
    #[TestDox('Direction 1 survives reordered parameter keys and injected unknown keys')]
    public function testDirection1IsInsensitiveToKeyOrderAndUnknownKeys(): void
    {
        $challenge = KwChallenge::create(self::SECRET, ['cost' => self::COST, 'data' => 'x']);
        $solution  = $this->solveWithOfficial($challenge);

        $params = array_reverse($challenge['parameters'], true);
        $params['someKeyWeNeverIssued'] = 'ignored';

        $mangled = ['parameters' => $params, 'signature' => $challenge['signature']];

        $result = KwVerifier::verify(self::SECRET, $this->widgetPayload($mangled, $this->solutionArray($solution)));

        self::assertTrue($result->is_valid(), 'Rejected with: ' . $result->get_error_code());
    }

    // ------------------------------------------------------------------
    // Direction 2: ours creates -> official solves -> OFFICIAL verifies
    // ------------------------------------------------------------------

    #[TestDox('Direction 2: our challenge is protocol-correct — the official library verifies it end to end')]
    public function testDirection2OursCreateOfficialSolveOfficialVerify(): void
    {
        $challenge = KwChallenge::create(self::SECRET, [
            'cost'       => self::COST,
            'expires_in' => 300,
            'data'       => 'form:42',
        ]);

        $solution = $this->solveWithOfficial($challenge);

        $officialPayload = new OfficialPayload(
            OfficialChallenge::fromArray($challenge),
            $solution,
        );

        $result = $this->official()->verifySolution(new VerifySolutionOptions(
            payload: $officialPayload->toBase64(),
            algorithm: $this->pbkdf2(),
        ));

        self::assertTrue($result->verified);
        self::assertFalse($result->expired);
        self::assertNotTrue($result->invalidSignature);
        self::assertNotTrue($result->invalidSolution);
    }

    #[TestDox('Direction 2: the official library rejects our challenge under a different secret')]
    public function testDirection2OfficialRejectsWrongSecret(): void
    {
        $challenge = KwChallenge::create(self::SECRET, ['cost' => self::COST]);
        $solution  = $this->solveWithOfficial($challenge);

        $payload = (new OfficialPayload(OfficialChallenge::fromArray($challenge), $solution))->toBase64();

        $result = $this->official(self::OTHER_SECRET)->verifySolution(new VerifySolutionOptions(
            payload: $payload,
            algorithm: $this->pbkdf2(),
        ));

        self::assertFalse($result->verified);
        self::assertTrue($result->invalidSignature);
    }

    // ------------------------------------------------------------------
    // Direction 3: official creates -> official solves -> ours verifies
    // ------------------------------------------------------------------

    #[TestDox('Direction 3: a challenge minted by the official library is accepted by our verifier')]
    public function testDirection3OfficialCreateOfficialSolveOursVerify(): void
    {
        $expiresAt = time() + 300;

        $challenge = $this->official()->createChallenge(new CreateChallengeOptions(
            algorithm: $this->pbkdf2(),
            cost: self::COST,
            keyLength: 32,
            keyPrefix: '00',
            expiresAt: $expiresAt,
            data: [Protocol::DATA_KEY => 'minted-by-upstream'],
        ));

        $solution = $this->official()->solveChallenge(new \AltchaOrg\Altcha\SolveChallengeOptions(
            challenge: $challenge,
            algorithm: $this->pbkdf2(),
            timeout: 60.0,
        ));
        self::assertNotNull($solution);

        $payload = (new OfficialPayload($challenge, $solution))->toBase64();

        $result = KwVerifier::verify(self::SECRET, $payload);

        self::assertTrue($result->is_valid(), 'Rejected with: ' . $result->get_error_code());
        self::assertSame($challenge->parameters->nonce, $result->get_replay_key());
        self::assertSame('minted-by-upstream', $result->get_data());
        self::assertSame($expiresAt, $result->get_expires_at());
    }

    #[TestDox('Direction 3: data bound under a foreign key is simply not surfaced')]
    public function testDirection3ForeignDataShapeStillVerifies(): void
    {
        $challenge = $this->official()->createChallenge(new CreateChallengeOptions(
            algorithm: $this->pbkdf2(),
            cost: self::COST,
            keyLength: 32,
            keyPrefix: '00',
            expiresAt: time() + 300,
            data: ['formId' => 'abc', 'ip' => '203.0.113.9'],
        ));

        $solution = $this->official()->solveChallenge(new \AltchaOrg\Altcha\SolveChallengeOptions(
            challenge: $challenge,
            algorithm: $this->pbkdf2(),
            timeout: 60.0,
        ));
        self::assertNotNull($solution);

        $result = KwVerifier::verify(self::SECRET, (new OfficialPayload($challenge, $solution))->toBase64());

        self::assertTrue($result->is_valid(), 'Rejected with: ' . $result->get_error_code());
        self::assertSame('', $result->get_data());
    }

    // ------------------------------------------------------------------
    // Direction 4: byte-for-byte canonical JSON and signature equality
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function canonicalCases(): iterable
    {
        $nonce = '39baf91a9e3b5b2e6f2c0a1d4e8f7c6b';
        $salt  = '5e00d5d1c2b3a4958677564534231201';

        yield 'minimal' => [[
            'algorithm' => 'PBKDF2/SHA-256',
            'cost'      => 5000,
            'keyLength' => 32,
            'keyPrefix' => '00',
            'nonce'     => $nonce,
            'salt'      => $salt,
        ]];

        yield 'with expiry' => [[
            'algorithm' => 'PBKDF2/SHA-256',
            'cost'      => 5000,
            'keyLength' => 32,
            'keyPrefix' => '00',
            'nonce'     => $nonce,
            'salt'      => $salt,
            'expiresAt' => 1753970000,
        ]];

        // The JSON-flags landmine: without JSON_UNESCAPED_SLASHES PHP emits "\/",
        // and without JSON_UNESCAPED_UNICODE it emits "ü" / surrogate pairs.
        yield 'slashes and unicode in data' => [[
            'algorithm' => 'PBKDF2/SHA-256',
            'cost'      => 1,
            'keyLength' => 32,
            'keyPrefix' => '00',
            'nonce'     => $nonce,
            'salt'      => $salt,
            'expiresAt' => 1753970000,
            'data'      => ['d' => 'https://example.com/grüße?a=1&b=2 — üöä ß 😀'],
        ]];

        yield 'unsorted nested data' => [[
            'salt'      => $salt,
            'nonce'     => $nonce,
            'keyPrefix' => '00',
            'keyLength' => 32,
            'cost'      => 7,
            'algorithm' => 'PBKDF2/SHA-256',
            'expiresAt' => 1753970000,
            'data'      => ['zebra' => 1, 'alpha' => ['y' => true, 'x' => null], 'mid' => 'ok'],
        ]];

        yield 'data with list value' => [[
            'algorithm' => 'PBKDF2/SHA-256',
            'cost'      => 3,
            'keyLength' => 32,
            'keyPrefix' => '00',
            'nonce'     => $nonce,
            'salt'      => $salt,
            'data'      => ['tags' => ['c', 'a', 'b']],
        ]];

        yield 'all optional fields present' => [[
            'algorithm'    => 'PBKDF2/SHA-256',
            'cost'         => 12,
            'keyLength'    => 16,
            'keyPrefix'    => 'abcd',
            'nonce'        => $nonce,
            'salt'         => $salt,
            'keySignature' => 'deadbeef',
            'memoryCost'   => 65536,
            'parallelism'  => 2,
            'expiresAt'    => 1753970000,
            'data'         => ['d' => 'x'],
        ]];

        yield 'unknown keys must be dropped identically' => [[
            'algorithm' => 'PBKDF2/SHA-256',
            'cost'      => 5000,
            'keyLength' => 32,
            'keyPrefix' => '00',
            'nonce'     => $nonce,
            'salt'      => $salt,
            'his'       => ['url' => 'https://evil.example/collect'],
            'maxnumber' => 1000000,
        ]];

        yield 'wrong-typed fields fall back identically' => [[
            'algorithm' => 'PBKDF2/SHA-256',
            'cost'      => '5000',
            'keyLength' => 32.0,
            'keyPrefix' => 42,
            'nonce'     => $nonce,
            'salt'      => $salt,
            'expiresAt' => '1753970000',
            'data'      => 'not-an-object',
        ]];
    }

    /**
     * @param array<string, mixed> $raw
     */
    #[TestDox('Direction 4: canonical JSON is byte-identical to the official library')]
    #[DataProvider('canonicalCases')]
    public function testDirection4CanonicalJsonIsByteIdentical(array $raw): void
    {
        $theirs = OfficialChallengeParameters::fromArray($raw)->toCanonicalJson();
        $ours   = Protocol::canonical_json(Protocol::normalize_parameters($raw));

        self::assertSame($theirs, $ours);
    }

    /**
     * @param array<string, mixed> $raw
     */
    #[TestDox('Direction 4: normalised parameter arrays are identical to the official library')]
    #[DataProvider('canonicalCases')]
    public function testDirection4NormalisedArraysAreIdentical(array $raw): void
    {
        $theirs = OfficialChallengeParameters::fromArray($raw)->toArray();
        $ours   = Protocol::normalize_parameters($raw);

        self::assertSame($theirs, $ours);
        self::assertSame(array_keys($theirs), array_keys($ours), 'Key order must match too.');
    }

    /**
     * @param array<string, mixed> $raw
     */
    #[TestDox('Direction 4: the HMAC signature is byte-identical to the official library')]
    #[DataProvider('canonicalCases')]
    public function testDirection4SignatureIsByteIdentical(array $raw): void
    {
        $canonical = OfficialChallengeParameters::fromArray($raw)->toCanonicalJson();

        // The official library's hmacHex() is private, so reproduce it exactly as
        // documented (bin2hex of the raw HMAC) and cross-check our helper against it.
        $theirs = bin2hex(hash_hmac('sha256', $canonical, self::SECRET, true));
        $ours   = Protocol::hmac_hex(Protocol::canonical_json(Protocol::normalize_parameters($raw)), self::SECRET);

        self::assertSame($theirs, $ours);
    }

    #[TestDox('Direction 4: a full challenge built by each side from identical inputs is byte-identical')]
    public function testDirection4FullChallengeIsByteIdentical(): void
    {
        $nonce     = '39baf91a9e3b5b2e6f2c0a1d4e8f7c6b';
        $salt      = '5e00d5d1c2b3a4958677564534231201';
        $expiresAt = 1753970000;
        $data      = ['d' => 'form:42 — https://example.com/ü'];

        $theirs = $this->official()->createChallenge(new CreateChallengeOptions(
            algorithm: $this->pbkdf2(),
            cost: 5000,
            keyLength: 32,
            keyPrefix: '00',
            expiresAt: $expiresAt,
            data: $data,
            nonce: $nonce,
            salt: $salt,
        ));

        // The same parameter set our Challenge::create() would emit, minus the two
        // random values, signed through our own code path.
        $ours = $this->signOurs([
            'algorithm' => Protocol::ALGORITHM,
            'cost'      => 5000,
            'expiresAt' => $expiresAt,
            'keyLength' => Protocol::KEY_LENGTH,
            'keyPrefix' => Protocol::KEY_PREFIX,
            'nonce'     => $nonce,
            'salt'      => $salt,
            'data'      => $data,
        ]);

        self::assertSame($theirs->parameters->toArray(), $ours['parameters']);
        self::assertSame($theirs->parameters->toCanonicalJson(), Protocol::canonical_json($ours['parameters']));
        self::assertSame($theirs->signature, $ours['signature']);
        self::assertSame($theirs->toJson(), (string) json_encode($ours, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    #[TestDox('Direction 4: a real Challenge::create() output re-signs identically under the official canonicaliser')]
    public function testDirection4RealChallengeSignatureMatchesOfficialCanonicaliser(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $challenge = KwChallenge::create(self::SECRET, [
                'cost'       => self::COST,
                'expires_in' => 300,
                'data'       => 'iteration ' . $i . ' / ü / ' . random_int(0, PHP_INT_MAX),
            ]);

            $canonical = OfficialChallengeParameters::fromArray($challenge['parameters'])->toCanonicalJson();
            $expected  = bin2hex(hash_hmac('sha256', $canonical, self::SECRET, true));

            self::assertSame($expected, $challenge['signature'], 'Mismatch on iteration ' . $i);
        }
    }

    // ------------------------------------------------------------------
    // KDF agreement
    // ------------------------------------------------------------------

    #[TestDox('PBKDF2 derivation matches the official library for many random inputs')]
    public function testPbkdf2MatchesOfficialLibrary(): void
    {
        $algorithm = $this->pbkdf2();

        for ($i = 0; $i < 50; $i++) {
            $nonce   = bin2hex(random_bytes(16));
            $salt    = bin2hex(random_bytes(16));
            $cost    = random_int(1, 500);
            $keyLen  = [16, 24, 32, 64][random_int(0, 3)];
            $counter = random_int(0, 4294967295);

            $params = OfficialChallengeParameters::fromArray([
                'algorithm' => 'PBKDF2/SHA-256',
                'cost'      => $cost,
                'keyLength' => $keyLen,
                'keyPrefix' => '00',
                'nonce'     => $nonce,
                'salt'      => $salt,
            ]);

            $password = hex2bin($nonce) . pack('N', $counter);

            $theirs = $algorithm->deriveKey($params, (string) hex2bin($salt), $password)->derivedKey;
            $ours   = Protocol::derive_key($cost, $keyLen, (string) hex2bin($salt), Protocol::password((string) hex2bin($nonce), $counter));

            self::assertSame(bin2hex($theirs), bin2hex($ours));
        }
    }

    #[TestDox('PBKDF2 agrees with the official library on the RESEARCH-altcha.md B.3 parameter shape')]
    public function testPbkdf2ResearchVector(): void
    {
        // Same shape as the PHP-vs-WebCrypto vector recorded in the research doc:
        // 16-byte nonce, 16-byte salt, counter=123, cost=5000, keyLength=32.
        // (The doc records only the truncated hex, so this asserts agreement rather
        // than a literal digest.)
        $nonce = '39baf91a9e3b5b2e6f2c0a1d4e8f7c6b';
        $salt  = '5e00d5d1c2b3a4958677564534231201';

        $ours   = Protocol::derive_key(5000, 32, (string) hex2bin($salt), Protocol::password((string) hex2bin($nonce), 123));
        $theirs = $this->pbkdf2()->deriveKey(
            OfficialChallengeParameters::fromArray([
                'algorithm' => 'PBKDF2/SHA-256',
                'cost'      => 5000,
                'keyLength' => 32,
                'keyPrefix' => '00',
                'nonce'     => $nonce,
                'salt'      => $salt,
            ]),
            (string) hex2bin($salt),
            hex2bin($nonce) . pack('N', 123),
        )->derivedKey;

        self::assertSame(bin2hex($theirs), bin2hex($ours));
    }
}
