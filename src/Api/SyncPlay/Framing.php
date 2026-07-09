<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\SyncPlay;

/**
 * SyncPlay protocol message framing and encoding/decoding.
 *
 * Mirrors the TypeScript `framing.ts` implementation.
 */
final class Framing
{
    /**
     * Encode a message object into a JSON wire format.
     *
     * @param array<string, mixed> $message
     */
    public static function encode(array $message): string
    {
        $message['protocol_version'] = Messages::PROTOCOL_VERSION;

        return (string) json_encode($message, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a raw JSON string into a message array.
     *
     * @return array<string, mixed>
     * @throws \JsonException
     */
    public static function decode(string $raw): array
    {
        $message = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($message)) {
            throw new \JsonException('Message must be a JSON object');
        }

        /** @var array<string, mixed> $message */
        return $message;
    }

    /**
     * Validate that a decoded message has the required base envelope fields.
     *
     * @param array<string, mixed> $message
     */
    public static function validateEnvelope(array $message): bool
    {
        if (!isset($message['type']) || !is_string($message['type'])) {
            return false;
        }

        if (!isset($message['protocol_version']) || $message['protocol_version'] !== Messages::PROTOCOL_VERSION) {
            return false;
        }

        return Messages::isValid($message['type']);
    }

    /**
     * Build a frame string for sending via WebSocket.
     *
     * @param array<string, mixed> $payload
     */
    public static function frame(string $type, array $payload = []): string
    {
        return self::encode(array_merge(['type' => $type], $payload));
    }
}
