<?php

namespace App\Domains\PeopleConnector\Connector\Services;

/**
 * Replaces anything in a support bundle that must not leave the tenant with
 * a fingerprint (#250): `sha256:` and the first 8 hex of the value's hash,
 * enough for support to match two bundles, never enough to use the value.
 *
 * Four rules, each named in the bundle manifest when it fires:
 *  - secret_key:  a key naming a secret, token, password, credential, key,
 *                 authorization, cookie or private material; its string
 *                 value, or every bare or secret-named string inside it, is
 *                 fingerprinted (a rotation entry's expires_at is kept).
 *  - private_key: a PEM block anywhere in a string value.
 *  - email:       an e-mail address anywhere in a string value.
 *  - id_number:   a run of nine or more digits (national ids, account
 *                 numbers) anywhere in a string value.
 */
final class SupportBundleRedactor
{
    public const RULES = ['secret_key', 'private_key', 'email', 'id_number'];

    private const SECRET_KEY = '/(secret|token|password|credential|api[_-]?key|authorization|cookie|private[_-]?key|\bkey$)/i';

    private const PRIVATE_KEY = '/-----BEGIN[A-Z ]*PRIVATE KEY-----.*?-----END[A-Z ]*PRIVATE KEY-----|-----BEGIN[A-Z ]*PRIVATE KEY-----.*/s';

    private const EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    private const ID_NUMBER = '/(?<![0-9A-Za-z])[0-9][0-9 -]{7,}[0-9](?![0-9A-Za-z])/';

    /** @var array<string, true> */
    private array $fired = [];

    public static function fingerprint(string $value): string
    {
        return 'sha256:'.substr(hash('sha256', $value), 0, 8);
    }

    /** Walk any config-shaped value; keys decide the secret_key rule, values the pattern rules. */
    public function redact(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $childKey = is_string($k) ? $k : $key;
                $out[$k] = is_string($k) && preg_match(self::SECRET_KEY, $k) === 1
                    ? $this->fingerprintDeep($v)
                    : $this->redact($v, $childKey);
            }

            return $out;
        }
        if (! is_string($value)) {
            return $value;
        }
        if ($key !== null && preg_match(self::SECRET_KEY, $key) === 1) {
            return $this->fingerprintDeep($value);
        }

        return $this->redactPatterns($value);
    }

    /** @return list<string> rules that fired, in declaration order */
    public function fired(): array
    {
        return array_values(array_filter(self::RULES, fn (string $rule): bool => isset($this->fired[$rule])));
    }

    /**
     * Inside a secret-named subtree every bare value (numeric or absent key)
     * and every secret-named leaf is fingerprinted; a leaf with another name
     * (a rotation entry's expires_at) keeps its value, pattern rules applied.
     */
    private function fingerprintDeep(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = ! is_string($k) || ctype_digit($k) || preg_match(self::SECRET_KEY, $k) === 1
                    ? $this->fingerprintDeep($v)
                    : $this->redact($v, $k);
            }

            return $out;
        }
        if (is_string($value) && $value !== '') {
            $this->fired['secret_key'] = true;

            return self::fingerprint($value);
        }

        return $value;
    }

    private function redactPatterns(string $value): string
    {
        foreach (['private_key' => self::PRIVATE_KEY, 'email' => self::EMAIL, 'id_number' => self::ID_NUMBER] as $rule => $pattern) {
            $value = (string) preg_replace_callback($pattern, function (array $m) use ($rule): string {
                $this->fired[$rule] = true;

                return self::fingerprint(trim($m[0]));
            }, $value);
        }

        return $value;
    }
}
