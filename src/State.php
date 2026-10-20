<?php

declare(strict_types=1);

namespace Playground;

use RuntimeException;

/**
 * Per-browser state kept in an encrypted, authenticated cookie (AES-256-GCM). Serverless hosts
 * such as Vercel don't share PHP session files between invocations, so nothing is stored server-side.
 * Writes happen immediately, so call set()/push() before sending any output.
 */
final class State
{
    private const COOKIE = 'payxy_playground';
    private const CIPHER = 'aes-256-gcm';
    private const LIFETIME_SECONDS = 3600;

    /** @var array<string, mixed> */
    private static array $data = [];

    private static string $key = '';

    private static bool $secure = false;

    public static function boot(string $secret, bool $secure): void
    {
        if ($secret === '') {
            throw new RuntimeException('Set APP_KEY to encrypt the playground cookie.');
        }
        self::$key = hash('sha256', $secret, true);
        self::$secure = $secure;
        self::$data = self::decode((string) ($_COOKIE[self::COOKIE] ?? ''));
    }

    public static function get(string $name, mixed $default = null): mixed
    {
        return self::$data[$name] ?? $default;
    }

    public static function set(string $name, mixed $value): void
    {
        self::$data[$name] = $value;
        self::persist();
    }

    /**
     * Appends to a list, keeping only the most recent $keep entries so the cookie stays small.
     */
    public static function push(string $name, string $value, int $keep = 10): void
    {
        $list = \is_array(self::$data[$name] ?? null) ? self::$data[$name] : [];
        $list[] = $value;
        self::set($name, \array_slice(array_values(array_unique($list)), -$keep));
    }

    private static function persist(): void
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt((string) json_encode(self::$data), self::CIPHER, self::$key, \OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new RuntimeException('Could not encrypt the playground cookie.');
        }

        // One Set-Cookie per response: replace the header from an earlier change in this request.
        $previous = array_filter(headers_list(), static fn (string $h): bool => stripos($h, 'Set-Cookie: ' . self::COOKIE . '=') !== 0);
        header_remove('Set-Cookie');
        foreach ($previous as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                header($header, false);
            }
        }

        setcookie(self::COOKIE, rtrim(strtr(base64_encode($iv . $tag . $cipherText), '+/', '-_'), '='), [
            'expires' => time() + self::LIFETIME_SECONDS,
            'path' => '/',
            'secure' => self::$secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $cookie): array
    {
        $raw = base64_decode(strtr($cookie, '-_', '+/'), true);
        if ($raw === false || \strlen($raw) <= 28) {
            return [];
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, self::$key, \OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        $data = $plain === false ? null : json_decode($plain, true);

        return \is_array($data) ? $data : [];   // tampered or stale cookies are simply ignored
    }
}
