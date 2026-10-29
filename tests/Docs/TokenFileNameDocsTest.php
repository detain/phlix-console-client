<?php

/**
 * Guard against the console__writer critical class: the docs once named the
 * auth token file `tokens.json` (plural) while TokenStore actually persists
 * `token.json` (singular) — the troubleshooting step then told users to
 * delete a file that does not exist, and their re-auth silently failed.
 *
 * The real name is derived from the code, never restated from the docs, and
 * every doc that names the token file is pinned to it. A wrong path/name in
 * prose reddens the suite; a future code rename reddens the code pin here
 * and its message directs the docs to follow together.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use Phlix\Console\Config\TokenStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TokenFileNameDocsTest extends TestCase
{
    /**
     * Docs that name the token file. If one of these moves, this test fails
     * loud below — update the list in the same change that moves the doc.
     *
     * @var list<string>
     */
    private const DOC_FILES = [
        'docs/clients/console.md',
        'docs/dev/client-console.md',
    ];

    /** @return array<string, array{0: string}> */
    public static function docsThatNameTheTokenFile(): array
    {
        $cases = [];
        foreach (self::DOC_FILES as $relative) {
            $cases[$relative] = [$relative];
        }

        return $cases;
    }

    public function testTheCodePersistsTheTokenFileAsTokenJson(): void
    {
        self::assertSame(
            'token.json',
            self::actualTokenFileName(),
            'TokenStore changed the on-disk token file name; update docs/clients/console.md,'
            . ' docs/dev/client-console.md, and this pin together.'
        );
    }

    #[DataProvider('docsThatNameTheTokenFile')]
    public function testDocsNameTheTokenFileExactlyAsTheCodeWritesIt(string $relative): void
    {
        $contents = self::readDoc($relative);
        $real = self::actualTokenFileName();

        self::assertStringNotContainsString(
            'tokens.json',
            $contents,
            $relative . ' names the drifted plural `tokens.json`; the code writes `' . $real . '`.'
        );
        self::assertStringContainsString(
            $real,
            $contents,
            $relative . ' no longer mentions `' . $real . '`; keep the token-file prose truthful.'
        );
    }

    /**
     * Resolve TokenStore's default path under an isolated XDG_CONFIG_HOME so
     * the real config is never touched (path() is pure string building).
     */
    private static function actualTokenFileName(): string
    {
        $previous = getenv('XDG_CONFIG_HOME');
        putenv('XDG_CONFIG_HOME=' . sys_get_temp_dir() . '/phlix-token-docs-guard');

        try {
            return basename(TokenStore::default()->path());
        } finally {
            if (is_string($previous)) {
                putenv('XDG_CONFIG_HOME=' . $previous);
            } else {
                putenv('XDG_CONFIG_HOME=');
            }
        }
    }

    private static function readDoc(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        if (!is_file($path)) {
            throw new RuntimeException(
                $relative . ' moved or was deleted; update DOC_FILES in ' . self::class . '.'
            );
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read ' . $relative . '.');
        }

        return $contents;
    }
}
