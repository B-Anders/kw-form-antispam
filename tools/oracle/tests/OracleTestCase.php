<?php

declare(strict_types=1);

namespace Kreiswolke\FormAntispam\Oracle\Tests;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as OfficialAltcha;
use AltchaOrg\Altcha\Challenge as OfficialChallenge;
use AltchaOrg\Altcha\HmacAlgorithm;
use AltchaOrg\Altcha\Solution as OfficialSolution;
use AltchaOrg\Altcha\SolveChallengeOptions;
use PHPUnit\Framework\TestCase;

/**
 * Shared plumbing for the differential suite.
 */
abstract class OracleTestCase extends TestCase
{
    /** HMAC secret used throughout. Deliberately ASCII, as the research doc advises. */
    public const SECRET = 'kwfa-oracle-secret-0123456789';

    /** A different secret, for the "wrong secret" cases. */
    public const OTHER_SECRET = 'kwfa-oracle-secret-9876543210';

    /**
     * Cost used in tests.
     *
     * Kept tiny so the whole suite runs in seconds: with keyPrefix '00' the solver
     * needs ~256 derivations, so total work is ~256 * COST PBKDF2 iterations.
     */
    public const COST = 200;

    protected function pbkdf2(): Pbkdf2
    {
        return new Pbkdf2(HmacAlgorithm::SHA256);
    }

    protected function official(?string $secret = null): OfficialAltcha
    {
        return new OfficialAltcha($secret ?? self::SECRET);
    }

    /**
     * Solve a challenge (given in our JSON-ready array form) with the official library.
     *
     * @param array<string, mixed> $challenge
     */
    protected function solveWithOfficial(array $challenge): OfficialSolution
    {
        $solution = $this->official()->solveChallenge(new SolveChallengeOptions(
            challenge: OfficialChallenge::fromArray($challenge),
            algorithm: $this->pbkdf2(),
            start: 0,
            step: 1,
            timeout: 60.0,
        ));

        self::assertNotNull($solution, 'The official library failed to solve the challenge within the timeout.');

        return $solution;
    }

    /**
     * Reproduce exactly what the widget writes into the hidden input:
     * `btoa(JSON.stringify({challenge: {parameters, signature}, solution}))`,
     * with `parameters` echoed verbatim in the server's original key order.
     *
     * @param array<string, mixed> $challenge
     * @param array<string, mixed> $solution
     */
    protected function widgetPayload(array $challenge, array $solution): string
    {
        $json = json_encode(
            [
                'challenge' => [
                    'parameters' => $challenge['parameters'],
                    'signature'  => $challenge['signature'] ?? null,
                ],
                'solution'  => $solution,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        self::assertIsString($json);

        return base64_encode($json);
    }

    /**
     * @return array<string, mixed>
     */
    protected function solutionArray(OfficialSolution $solution): array
    {
        return [
            'counter'    => $solution->counter,
            'derivedKey' => $solution->derivedKey,
            'time'       => 123.45,
        ];
    }

    /**
     * Sign an arbitrary parameters array with our own implementation, producing a
     * challenge that our verifier will accept as authentic. Used to construct
     * negative cases that must be rejected for reasons *other* than the signature.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    protected function signOurs(array $parameters, ?string $secret = null): array
    {
        ksort($parameters);

        return [
            'parameters' => $parameters,
            'signature'  => \Kreiswolke\FormAntispam\Altcha\Protocol::hmac_hex(
                \Kreiswolke\FormAntispam\Altcha\Protocol::canonical_json($parameters),
                $secret ?? self::SECRET,
            ),
        ];
    }

    protected function base64Json(mixed $value): string
    {
        return base64_encode((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
