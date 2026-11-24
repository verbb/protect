<?php
namespace verbb\protect\helpers;

use verbb\protect\Protect;

use Craft;
use craft\web\Request;

use yii\validators\IpValidator;

use ReflectionClass;
use Throwable;

/**
 * Resolves the client address without Craft's `getUserIP()`, which reads `Client-IP` first and
 * takes the leftmost forwarded entry, both of which the visitor controls. Forwarding headers
 * count only when Craft's own `trustedHosts` says the connection came through a proxy.
 */
class Ip
{
    // Static Methods
    // =========================================================================

    public static function clientIp(Request $request): ?string
    {
        $remote = self::normalise($_SERVER['REMOTE_ADDR'] ?? '');

        if ($remote === null) {
            return null;
        }

        $hosts = (array)$request->trustedHosts;
        $explicit = self::_explicitIpHeaders($request);

        if (self::_isNarrowed($hosts, $remote)) {
            $entry = self::_trustEntry($remote, $hosts);

            return $entry === false ? $remote : self::_fromTrustedProxy($request, $remote, $hosts, $entry, $explicit ?? ['X-Forwarded-For']);
        }

        // Nothing here proves a proxy, so list headers can't be believed: their rightmost entry
        // is the visitor's own on an origin reachable directly. A named single-value header is the
        // one exception, because the site asked for it and an edge overwrites it.
        foreach ($explicit ?? [] as $name) {
            if (self::_isSingleValueHeader($name) && ($ip = self::_singleValue($request->getHeaders()->get($name)))) {
                self::_warn($request);

                return $ip;
            }
        }

        self::_warn($request);

        return $remote;
    }

    /**
     * Returns a canonical address, or null if it isn't one: strips brackets and ports, unmaps
     * `::ffff:0:0/96` and canonicalises IPv6. Without this, one visitor could present as several
     * strings, and a mapped address would never match an IPv4 range.
     */
    public static function normalise(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $value, $matches)) {
            $value = $matches[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $value, $matches)) {
            $value = $matches[1];
        }

        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = inet_pton($value);

        if ($binary === false) {
            return null;
        }

        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10) . "\xFF\xFF")) {
            $binary = substr($binary, 12);
        }

        return inet_ntop($binary) ?: null;
    }

    /**
     * The address a rate limit should count against. Applied only in rate keys, so providers
     * still receive the exact address.
     */
    public static function bucket(string $ip): string
    {
        $normalised = self::normalise($ip);

        if ($normalised === null) {
            return $ip;
        }

        if (!str_contains($normalised, ':')) {
            return $normalised;
        }

        return inet_ntop(substr(inet_pton($normalised), 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    // Private Static Methods
    // =========================================================================

    private static function _fromTrustedProxy(Request $request, string $remote, array $hosts, array|true $entry, array $names): string
    {
        foreach ($names as $name) {
            // RFC 7239 isn't parsed in 1.0, and a matched `cidr => headers` entry limits what counts.
            if (strtolower($name) === 'forwarded' || ($entry !== true && !in_array(strtolower($name), array_map('strtolower', $entry), true))) {
                continue;
            }

            $values = self::_trustedHeaderValues($request, $name);

            if (self::_isSingleValueHeader($name)) {
                $ip = self::_singleValue($values[0] ?? null);
            } else {
                $ip = self::_walk($values, $hosts, $remote);
            }

            if ($ip !== null) {
                return $ip;
            }
        }

        return $remote;
    }

    private static function _walk(array $values, array $hosts, string $remote): ?string
    {
        $tokens = array_filter(array_map('trim', explode(',', implode(',', $values))), fn(string $token) => $token !== '');

        if (!$tokens) {
            return null;
        }

        $last = $remote;

        foreach (array_reverse($tokens) as $token) {
            $ip = self::normalise($token);

            if ($ip === null) {
                return $last;
            }

            $last = $ip;

            if (self::_trustEntry($ip, $hosts) === false) {
                return $ip;
            }
        }

        // Every hop is one of the site's own proxies, so the leftmost is the closest to a client.
        return $last;
    }

    private static function _trustedHeaderValues(Request $request, string $name): array
    {
        $values = $request->getHeaders()->get($name, null, false);

        if ($values) {
            return $values;
        }

        // Yii strips secure headers before Protect sees them when it can't match the connecting
        // address, and it can't match a mapped one. Protect has matched it by this point.
        $server = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null;

        return is_string($server) ? [$server] : [];
    }

    private static function _singleValue(?string $value): ?string
    {
        return $value === null || str_contains($value, ',') ? null : self::normalise($value);
    }

    private static function _isSingleValueHeader(string $name): bool
    {
        return !in_array(strtolower($name), ['x-forwarded-for', 'forwarded-for', 'x-cluster-client-ip', 'forwarded'], true);
    }

    private static function _isNarrowed(array $hosts, string $remote): bool
    {
        $ipv6 = str_contains($remote, ':');

        foreach ($hosts as $key => $value) {
            $range = strtolower((string)(is_array($value) ? $key : $value));

            if (in_array($range, ['any', '*'], true) || $range === ($ipv6 ? '::/0' : '0.0.0.0/0')) {
                return false;
            }
        }

        return true;
    }

    private static function _trustEntry(string $ip, array $hosts): array|bool
    {
        foreach ($hosts as $key => $value) {
            [$range, $headers] = is_array($value) ? [$key, $value] : [$value, true];

            if ((new IpValidator(['ranges' => [(string)$range]]))->validate($ip)) {
                return $headers;
            }
        }

        return false;
    }

    private static function _explicitIpHeaders(Request $request): ?array
    {
        $default = (new ReflectionClass(Request::class))->getDefaultProperties()['ipHeaders'];
        $current = (array)$request->ipHeaders;

        return array_map('strtolower', $current) === array_map('strtolower', $default) ? null : $current;
    }

    private static function _warn(Request $request): void
    {
        $names = ['Client-IP', 'X-Forwarded-For', 'X-Forwarded', 'X-Cluster-Client-IP', 'Forwarded-For', 'Forwarded', 'X-Real-IP', 'True-Client-IP', 'CF-Connecting-IP'];
        $present = array_values(array_filter($names, fn(string $name) => $request->getHeaders()->has($name)));

        if (!$present) {
            return;
        }

        try {
            if (!Craft::$app->getCache()->add('protect:ip-warning', true, 86400)) {
                return;
            }
        } catch (Throwable) {
            // Any client can reach this path, so a failing cache backend can't be allowed to break resolution.
            return;
        }

        // Names only: any client can send these headers, so their values aren't safe to log.
        Protect::warning('Forwarding headers arrived (' . implode(', ', $present) . ') but Craft\'s trustedHosts isn\'t narrowed to your proxies. If this site is behind a proxy or CDN, every visitor may share one address until trustedHosts is configured.');
    }
}
