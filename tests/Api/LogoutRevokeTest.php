<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Api;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\ApiError;
use Phlix\Console\Config\TokenBundle;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

/**
 * The server-side sign-out ladder [review console__secaudit #2 / CU-004]:
 * every logout and server-removal path best-effort revokes the bundle it is
 * dropping, against the bundle's OWN host — phlix-server form first
 * (root /auth/logout, handler-then-302), hub JSON twin on a 404
 * (/api/v1/auth/logout, 204). Wire coordinates re-verified at tips:
 * phlix-server src/Server/Core/Application.php:462-463 (root form), phlix-hub
 * src/Application.php:484 (JSON form) — the two hosts each serve exactly one.
 */
final class LogoutRevokeTest extends TestCase
{
    private function bundle(): TokenBundle
    {
        return new TokenBundle('stored-access', 'stored-refresh', 'Bearer', null);
    }

    public function testServerFormIsTriedFirstWithTheDroppedBundleBearer(): void
    {
        $transport = new FakeTransport();
        $api = new ApiClient('https://srv', $transport);
        // A DIFFERENT credential sits live in the client: the revoke must
        // never borrow it (removal path drops local state while in flight).
        $api->setToken(new TokenBundle('live-access', 'live-refresh', 'Bearer', null));
        $dropped = $this->bundle();

        $this->await($api->logoutAt('https://old-host', $dropped));

        self::assertSame(1, $transport->requestCount());
        $request = $transport->requestAt(0);
        self::assertSame('POST', $request['method']);
        self::assertSame('https://old-host/auth/logout', $request['url']);
        self::assertSame($dropped->authorizationHeader(), $request['headers']['Authorization']);
        self::assertStringNotContainsString('live-access', $request['headers']['Authorization']);
    }

    public function testServerAckShapesResolve(): void
    {
        // Bare 302 (no followable Location in this fake transport): the
        // handler has run by the time the server answers, so 3xx is an ack.
        $transport = (new FakeTransport())->raw(302, '');
        $api = new ApiClient('https://srv', $transport);
        self::assertSame([], $this->await($api->logoutAt('https://srv', $this->bundle())));

        // The React Browser follows the 302 to /login in production: the
        // terminal HTML 200 must resolve too (the revoke leg decodes with
        // ackOnly, so a JSON-less 2xx is an empty success payload there —
        // everywhere else it fails closed [review CON-6]).
        $followed = (new FakeTransport())->raw(200, '<!doctype html><title>Sign in</title>');
        $api2 = new ApiClient('https://srv', $followed);
        self::assertSame([], $this->await($api2->logoutAt('https://srv', $this->bundle())));
    }

    public function testHubFormIsReachedOnAPlainFourOhFour(): void
    {
        // A hub host has no root /auth/logout — 404 (HTML or JSON) falls
        // through to the JSON twin, which answers 204.
        $transport = (new FakeTransport())
            ->raw(404, '<html>not found</html>')
            ->json(204, []);
        $api = new ApiClient('https://hub', $transport);

        self::assertSame([], $this->await($api->logoutAt('https://hub', $this->bundle())));

        self::assertSame(2, $transport->requestCount());
        self::assertSame('https://hub/auth/logout', $transport->requestAt(0)['url']);
        self::assertSame('https://hub/api/v1/auth/logout', $transport->requestAt(1)['url']);
    }

    public function testFourOhFourOnlyLegIsNotSwallowedSilently(): void
    {
        // If BOTH forms 404, the ladder surfaces the second leg's error — the
        // caller decides how loud to be (App: warning toast, local drop
        // unconditional either way).
        $transport = (new FakeTransport())
            ->raw(404, '')
            ->raw(404, '');
        $api = new ApiClient('https://weird', $transport);

        $error = null;
        $this->await($api->logoutAt('https://weird', $this->bundle()), $error);

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame(404, $error->statusCode);
    }

    public function testNonFourOhFourFirstLegFailureIsNotRetriedAgainstTheOtherForm(): void
    {
        // 410 is a definitive answer from the FIRST host; probing a different
        // path on the same dead-end host would be noise, and a 5xx must
        // reach the caller's best-effort warning.
        $transport = (new FakeTransport())->raw(500, '');
        $api = new ApiClient('https://srv', $transport);

        $error = null;
        $this->await($api->logoutAt('https://srv', $this->bundle()), $error);

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame(500, $error->statusCode);
        self::assertSame(1, $transport->requestCount());
    }

    public function testAuthErrorFromTheRevokeLegStaysFinal(): void
    {
        // A 401 here means the session is already dead — the ladder must NOT
        // refresh-and-retry (that would churn a rotated credential against a
        // host that just rejected the old one).
        $transport = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
        $api = new ApiClient('https://srv', $transport);

        $error = null;
        $this->await($api->logoutAt('https://srv', $this->bundle()), $error);

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame(401, $error->statusCode);
        self::assertSame(1, $transport->requestCount(), 'no refresh churn on the revoke leg');
    }

    /**
     * Drive a promise to settlement without asserting on its value; the error
     * (if any) is written into $error by reference.
     */
    private function await(PromiseInterface $promise, ?\Throwable &$error = null): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($v) use (&$state): void {
                $state['value'] = $v;
                $state['done'] = true;
                Loop::stop();
            },
            function ($e) use (&$state): void {
                $state['error'] = $e;
                $state['done'] = true;
                Loop::stop();
            },
        );

        if (!$state['done']) {
            $timer = Loop::addTimer(2.0, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        $error = $state['error'];

        if ($error !== null && func_num_args() > 1) {
            return null;
        }

        if ($error !== null) {
            throw $error;
        }

        return $state['value'];
    }
}
