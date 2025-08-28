<?php
declare(strict_types=1);

namespace Listrak\Service;

/**
 * Stateless, signed, URL-safe token with optional gzip compression.
 *
 * Format: base64url( <flag:1 byte> + <json or deflated-json> ) . base64url( HMAC-SHA256 over the left part )
 *   flag = "\x00" (no compression) | "\x01" (deflated)
 */
final class TokenCodec
{
    public function __construct(
        private readonly string $secret,
        private readonly bool $enableCompression = true,
        private readonly int $compressThreshold = 600 // bytes BEFORE base64;
    ) {
    }

    public function encode(array $payload): string
    {
        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES);

        $useCompression = false;
        $body = $json;

        if (
            $this->enableCompression
            && \function_exists('gzdeflate')
            && \strlen($json) >= $this->compressThreshold
        ) {
            $deflated = @gzdeflate($json, 9);
            if ($deflated !== false && \strlen($deflated) < \strlen($json)) {
                $body = $deflated;
                $useCompression = true;
            }
        }

        $flag = $useCompression ? "\x01" : "\x00";
        $blob = $flag . $body;

        $b64 = self::b64urlEncode($blob);

        $mac = hash_hmac('sha256', $b64, $this->secret, true);
        $s64 = self::b64urlEncode($mac);

        return $b64 . '.' . $s64;
    }

    /**
     * @throws \RuntimeException|\JsonException on invalid/expired token
     */
    public function decode(string $token): array
    {
        if (!str_contains($token, '.')) {
            throw new \RuntimeException('Malformed token');
        }

        [$b64, $s64] = explode('.', $token, 2);

        $calc = self::b64urlEncode(hash_hmac('sha256', $b64, $this->secret, true));
        if (!hash_equals($calc, $s64)) {
            throw new \RuntimeException('Invalid token');
        }

        // Decode envelope
        $blob = self::b64urlDecode($b64);
        if ($blob === false || $blob === '') {
            throw new \RuntimeException('Corrupt token');
        }

        $flag = $blob[0];
        $body = substr($blob, 1);

        if ($flag === "\x01") {
            if (!\function_exists('gzinflate')) {
                throw new \RuntimeException('Compression not supported on server');
            }
            $json = @gzinflate($body);
            if ($json === false) {
                throw new \RuntimeException('Failed to decompress token');
            }
        } elseif ($flag === "\x00") {
            $json = $body;
        } else {
            $maybeJson = $flag . $body;
            $json = $maybeJson;
        }

        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (isset($data['exp']) && time() > (int) $data['exp']) {
            throw new \RuntimeException('Expired token');
        }

        return $data;
    }

    private static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $b64)
    {
        $pad = \strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        return base64_decode(strtr($b64, '-_', '+/'), true);
    }
}
