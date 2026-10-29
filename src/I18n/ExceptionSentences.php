<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\I18n;

use Phlix\Console\Api\ApiError;
use Phlix\Console\Api\NetworkError;
use Phlix\Console\Ui\Truncate;

/**
 * Turns a {@see \Throwable} into a single end-user-safe sentence.
 *
 * Doctrine — the line between wire prose and internals (review
 * console__copywriter M11):
 *
 *  - `ApiError` / `AuthError` messages are minted by `ApiClient::decode()`
 *    from the server's own error envelope, already control-sequence
 *    sanitized at that boundary (`Sanitize::untrustedForDisplay`). The
 *    server addresses the user in those strings, so they pass through
 *    verbatim (flattened and cell-capped) — that is intentional wire prose.
 *  - `NetworkError` is the transport family (DNS, refused, TLS, timeout).
 *    Raw cURL/socket prose is host and protocol detail: it rides the
 *    exception's `previous` chain to the log and never into the UI. The
 *    user gets the curated {@see network()} sentence.
 *  - Any other `Throwable` is program state leaking text (`SQLSTATE[...]`,
 *    filesystem paths, type errors). It is logged; the user gets the
 *    generic sentence, or a screen-supplied curated fallback.
 *
 * Same doctrine forbids nesting two sentence prefixes — a composed toast
 * renders its context clause plus AT MOST one sanitized reason; a curated
 * network sentence or a local-throw context stands alone. Every diagnostic
 * dropped from the UI is preserved on the log surface via `error_log()`,
 * the established sink in this codebase.
 */
final class ExceptionSentences
{
    /** Longest reason clause allowed into a one-line toast (render cells). */
    private const REASON_MAX_CELLS = 160;

    /** House sentence for the unreachable-server family (App.php proves the style). */
    public static function network(): string
    {
        return Lang::t('errors.network');
    }

    /**
     * Standalone sentence for $e (login-headline shape).
     *
     * @param string      $logContext labels the error_log line for diagnosis
     * @param string|null $generic    curated fallback replacing the generic
     *                                unexpected-error sentence for this screen
     */
    public static function sentence(\Throwable $e, string $logContext = 'ui', ?string $generic = null): string
    {
        if ($e instanceof NetworkError) {
            self::log($logContext, $e);

            return self::network();
        }
        $wire = self::wireReason($e);
        if ($wire !== '') {
            return $wire;
        }

        self::log($logContext, $e);

        return $generic ?? Lang::t('errors.unexpected');
    }

    /**
     * $context clause with at most one sanitized reason appended — never a
     * second sentence prefix. Sites whose catch can see API or transport
     * failures (server add, watch-room load/create/join, playback start).
     */
    public static function composed(string $context, \Throwable $e, ?string $logContext = null): string
    {
        $label = $logContext ?? $context;
        if ($e instanceof NetworkError) {
            self::log($label, $e);

            return self::network();
        }
        $wire = self::wireReason($e);
        if ($wire !== '') {
            return $context . ': ' . $wire;
        }

        self::log($label, $e);

        return self::terminate($context);
    }

    /**
     * Context sentence alone; everything $e carries goes to the log.
     * For catches over purely local operations (config persistence) and for
     * fallback notices whose context already states the whole outcome
     * ("…using local config.", "Settings saved locally, …").
     */
    public static function contextOnly(string $context, \Throwable $e, ?string $logContext = null): string
    {
        self::log($logContext ?? $context, $e);

        return self::terminate($context);
    }

    /** Wire prose (server-authored, boundary-sanitized) flattened and capped; '' otherwise. */
    private static function wireReason(\Throwable $e): string
    {
        if ($e instanceof NetworkError || !$e instanceof ApiError) {
            return '';
        }

        return self::clamp($e->getMessage());
    }

    /** Flatten multi-line transport noise and cap to one toast line of cells. */
    private static function clamp(string $text): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Truncate::withEllipsis($flat, self::REASON_MAX_CELLS);
    }

    private static function terminate(string $sentence): string
    {
        return preg_match('/[.!?…]$/u', $sentence) === 1 ? $sentence : $sentence . '.';
    }

    /** Diagnostic prose goes here — the log surface, never the render surface. */
    private static function log(string $context, \Throwable $e): void
    {
        $line = $e::class . ': ' . $e->getMessage();
        $previous = $e->getPrevious();
        if ($previous !== null) {
            // The source fixes park raw transport/provenance detail in the
            // chain, so the log line must unwrap it for diagnosis.
            $line .= ' <- ' . $previous::class . ': ' . $previous->getMessage();
        }

        error_log($context . ' failed: ' . $line);
    }
}
