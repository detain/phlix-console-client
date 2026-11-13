<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Api;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\ApiError;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use ReflectionMethod;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for the central API response envelope handling in ApiClient::decode().
 *
 * Tests five envelope shapes:
 * 1. {success:false}                     → ApiError with "Request failed"
 * 2. {success:false,message:"x"}        → ApiError with message "x"
 * 3. {success:true,data:{...}}          → unwrapped data
 * 4. {data:{...}}  (no success key)     → NOT unwrapped, passed through
 * 5. {libraries:[]}  (bare object)       → untouched
 */
final class EnvelopeTest extends TestCase
{
    /**
     * Invoke the private static ApiClient::decode() method via reflection.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function decode(int $status, array $body): array
    {
        $response = new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));

        $ref = new ReflectionMethod(ApiClient::class, 'decode');
        $ref->setAccessible(true);

        /** @var array<string,mixed> */
        return $ref->invoke(null, $response);
    }

    // -------------------------------------------------------------------------
    // Envelope shapes
    // -------------------------------------------------------------------------

    #[Test]
    public function successFalseWithoutMessageYieldsRequestFailed(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Request failed');

        $this->decode(200, ['success' => false]);
    }

    #[Test]
    public function successFalseWithMessageYieldsThatMessage(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Nope');

        $this->decode(200, ['success' => false, 'message' => 'Nope']);
    }

    #[Test]
    public function successFalseWithErrorKeyYieldsErrorValue(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Epic fail');

        $this->decode(200, ['success' => false, 'error' => 'Epic fail']);
    }

    #[Test]
    public function successTrueWithDataUnwrapsData(): void
    {
        $result = $this->decode(200, ['success' => true, 'data' => ['id' => 42, 'name' => 'test']]);

        self::assertSame(['id' => 42, 'name' => 'test'], $result);
    }

    #[Test]
    public function dataKeyWithoutSuccessKeyIsNotUnwrapped(): void
    {
        // {data: {...}} without a "success" key must NOT be unwrapped.
        $result = $this->decode(200, ['data' => ['id' => 42]]);

        self::assertSame(['data' => ['id' => 42]], $result);
    }

    #[Test]
    public function bareObjectIsReturnedUnchanged(): void
    {
        // {"libraries": []} — a bare domain-keyed object passes through untouched.
        $result = $this->decode(200, ['libraries' => [['id' => 'lib1']]]);

        self::assertSame(['libraries' => [['id' => 'lib1']]], $result);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    #[Test]
    public function successTrueWithoutDataKeyReturnsFullEnvelope(): void
    {
        // {success: true} without a "data" key should NOT try to unwrap.
        $result = $this->decode(200, ['success' => true, 'extra' => 'field']);

        self::assertSame(['success' => true, 'extra' => 'field'], $result);
    }

    #[Test]
    public function nonArrayBodyYieldsEmptyArray(): void
    {
        $result = $this->decode(200, []);

        self::assertSame([], $result);
    }

    #[Test]
    public function httpErrorIsNotAffectedByEnvelopeLogic(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Not found');

        $this->decode(404, ['error' => 'Not found']);
    }
}
