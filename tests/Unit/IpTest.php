<?php

use verbb\protect\Protect;
use verbb\protect\helpers\Ip;
use verbb\protect\models\RequestSignals;

use craft\console\Request as ConsoleRequest;
use craft\web\Request;

function ipRequest(string $remoteAddr, array $headers = [], array $config = []): Request
{
    foreach (array_keys($_SERVER) as $key) {
        if (str_starts_with($key, 'HTTP_')) {
            unset($_SERVER[$key]);
        }
    }

    $_SERVER['REMOTE_ADDR'] = $remoteAddr;

    foreach ($headers as $name => $value) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return new Request($config + ['cookieValidationKey' => 'protect-tests', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php', 'hostInfo' => 'https://protect.test']);
}

function ipWarnings(): array
{
    $messages = array_filter(Craft::getLogger()->messages, fn(array $message) => is_string($message[0]) && str_contains($message[0], 'Forwarding headers arrived'));

    return array_values(array_map(fn(array $message) => $message[0], $messages));
}

beforeEach(function() {
    $this->server = $_SERVER;
    Craft::getLogger()->messages = [];
    Craft::$app->getCache()->delete('protect:ip-warning');
});

afterEach(function() {
    $_SERVER = $this->server;
});

it('uses REMOTE_ADDR by default and ignores every forwarding header', function() {
    $request = ipRequest('203.0.113.9', ['Client-IP' => '198.51.100.1', 'X-Forwarded-For' => '198.51.100.2'], ['trustedHosts' => ['any']]);

    expect(Ip::clientIp($request))->toBe('203.0.113.9');
});

it('returns null when REMOTE_ADDR is missing or invalid', function() {
    expect(Ip::clientIp(ipRequest('not-an-ip')))->toBeNull();

    $request = ipRequest('203.0.113.9');
    unset($_SERVER['REMOTE_ADDR']);

    expect(Ip::clientIp($request))->toBeNull();
});

it('walks X-Forwarded-For right to left past trusted proxies only', function() {
    $hosts = ['10.0.0.0/8'];

    // The leftmost entry is whatever the visitor sent, and the first untrusted hop from the right is real.
    $request = ipRequest('10.0.0.1', ['X-Forwarded-For' => '198.51.100.66, 203.0.113.7, 10.0.0.2'], ['trustedHosts' => $hosts]);
    expect(Ip::clientIp($request))->toBe('203.0.113.7');

    // All hops trusted: the leftmost is the closest thing to a client.
    $request = ipRequest('10.0.0.1', ['X-Forwarded-For' => '10.0.0.4, 10.0.0.3'], ['trustedHosts' => $hosts]);
    expect(Ip::clientIp($request))->toBe('10.0.0.4');

    // Empty header: the proxy itself.
    expect(Ip::clientIp(ipRequest('10.0.0.1', [], ['trustedHosts' => $hosts])))->toBe('10.0.0.1');

    // An invalid token stops the walk at the last valid hop.
    $request = ipRequest('10.0.0.1', ['X-Forwarded-For' => '198.51.100.66, unknown, 10.0.0.2'], ['trustedHosts' => $hosts]);
    expect(Ip::clientIp($request))->toBe('10.0.0.2');

    $request = ipRequest('10.0.0.1', ['X-Forwarded-For' => '198.51.100.66, unknown'], ['trustedHosts' => $hosts]);
    expect(Ip::clientIp($request))->toBe('10.0.0.1');
});

it('joins multiple header lines before walking', function() {
    $request = ipRequest('10.0.0.1', [], ['trustedHosts' => ['10.0.0.0/8']]);
    $request->getHeaders()->add('X-Forwarded-For', '198.51.100.66');
    $request->getHeaders()->add('X-Forwarded-For', '203.0.113.7, 10.0.0.2');

    expect(Ip::clientIp($request))->toBe('203.0.113.7');
});

it('ignores every header on an untrusted connection when narrowed', function() {
    $request = ipRequest('198.51.100.7', ['X-Forwarded-For' => '192.0.2.1', 'CF-Connecting-IP' => '192.0.2.2', 'Client-IP' => '192.0.2.3'], ['trustedHosts' => ['10.0.0.0/8'], 'ipHeaders' => ['X-Forwarded-For', 'CF-Connecting-IP']]);

    expect(Ip::clientIp($request))->toBe('198.51.100.7');
});

it('never lets Client-IP win unless the site names it', function() {
    $request = ipRequest('10.0.0.1', ['Client-IP' => '192.0.2.3', 'X-Forwarded-For' => '203.0.113.7'], ['trustedHosts' => ['10.0.0.0/8']]);

    expect(Ip::clientIp($request))->toBe('203.0.113.7');
});

it('does not let a forged leftmost entry win under Craft\'s documented ipHeaders example', function() {
    $request = ipRequest('10.0.0.1', ['X-Forwarded-For' => '198.51.100.66, 203.0.113.7', 'CF-Connecting-IP' => '192.0.2.2'], ['trustedHosts' => ['10.0.0.0/8'], 'ipHeaders' => ['X-Forwarded-For', 'CF-Connecting-IP']]);

    expect(Ip::clientIp($request))->toBe('203.0.113.7');

    // Unnarrowed, the same header list can't trust X-Forwarded-For at all.
    $request = ipRequest('203.0.113.9', ['X-Forwarded-For' => '198.51.100.66'], ['trustedHosts' => ['any'], 'ipHeaders' => ['X-Forwarded-For', 'X-Cluster-Client-IP']]);

    expect(Ip::clientIp($request))->toBe('203.0.113.9');
});

it('takes named headers in order when narrowed and trusted', function() {
    $config = ['trustedHosts' => ['10.0.0.0/8'], 'ipHeaders' => ['CF-Connecting-IP', 'X-Forwarded-For']];

    expect(Ip::clientIp(ipRequest('10.0.0.1', ['CF-Connecting-IP' => '203.0.113.5', 'X-Forwarded-For' => '203.0.113.6'], $config)))->toBe('203.0.113.5')
        ->and(Ip::clientIp(ipRequest('10.0.0.1', ['X-Forwarded-For' => '203.0.113.6'], $config)))->toBe('203.0.113.6')
        // A single-value header holding a list is skipped, not split.
        ->and(Ip::clientIp(ipRequest('10.0.0.1', ['CF-Connecting-IP' => '198.51.100.1, 203.0.113.5', 'X-Forwarded-For' => '203.0.113.6'], $config)))->toBe('203.0.113.6');
});

it('skips a header a matched cidr entry omits', function() {
    $request = ipRequest('10.0.0.1', ['X-Forwarded-For' => '203.0.113.6', 'CF-Connecting-IP' => '203.0.113.5'], ['trustedHosts' => ['10.0.0.0/8' => ['CF-Connecting-IP']], 'ipHeaders' => ['X-Forwarded-For', 'CF-Connecting-IP']]);

    expect(Ip::clientIp($request))->toBe('203.0.113.5');
});

it('honours a named single-value header with a warning when not narrowed', function() {
    $request = ipRequest('203.0.113.9', ['CF-Connecting-IP' => '198.51.100.5', 'X-Forwarded-For' => '198.51.100.6'], ['trustedHosts' => ['any'], 'ipHeaders' => ['X-Forwarded-For', 'CF-Connecting-IP']]);

    expect(Ip::clientIp($request))->toBe('198.51.100.5')
        ->and(ipWarnings())->toHaveCount(1);
});

it('treats any, star, and /0 ranges as not narrowed, as value or key', function(array $hosts, string $remote) {
    $request = ipRequest($remote, ['X-Forwarded-For' => '198.51.100.6'], ['trustedHosts' => $hosts]);

    expect(Ip::clientIp($request))->toBe($remote);
})->with([
    'any' => [['any'], '203.0.113.9'],
    'star' => [['*'], '203.0.113.9'],
    'any as key' => [['any' => ['X-Forwarded-For']], '203.0.113.9'],
    'both families, IPv4' => [['0.0.0.0/0', '::/0'], '203.0.113.9'],
    'both families, IPv6' => [['0.0.0.0/0', '::/0'], '2001:db8::9'],
    'ipv4 only, IPv4' => [['0.0.0.0/0'], '203.0.113.9'],
    'ipv4 only, IPv6' => [['0.0.0.0/0'], '2001:db8::9'],
]);

it('counts hostnames in trustedHosts as narrowed, and they never match', function() {
    $request = ipRequest('203.0.113.9', ['X-Forwarded-For' => '198.51.100.6'], ['trustedHosts' => ['proxy.example.com']]);

    expect(Ip::clientIp($request))->toBe('203.0.113.9')
        ->and(ipWarnings())->toHaveCount(0);
});

it('matches a mapped REMOTE_ADDR against an IPv4 range and still reads the stripped header', function() {
    $request = ipRequest('::ffff:10.0.0.1', ['X-Forwarded-For' => '203.0.113.7'], ['trustedHosts' => ['10.0.0.0/8']]);

    expect(Ip::clientIp($request))->toBe('203.0.113.7');
});

it('accepts port-bearing and bracketed hops', function() {
    $request = ipRequest('10.0.0.1', ['X-Forwarded-For' => '[2001:DB8::1]:443, 203.0.113.7:8443, 10.0.0.2:80'], ['trustedHosts' => ['10.0.0.0/8']]);

    expect(Ip::clientIp($request))->toBe('203.0.113.7');

    $request = ipRequest('10.0.0.1', ['X-Forwarded-For' => '[2001:DB8::1]:443, 10.0.0.2:80'], ['trustedHosts' => ['10.0.0.0/8']]);

    expect(Ip::clientIp($request))->toBe('2001:db8::1');
});

it('never parses the Forwarded header', function() {
    $request = ipRequest('10.0.0.1', ['Forwarded' => 'for=203.0.113.7'], ['trustedHosts' => ['10.0.0.0/8'], 'ipHeaders' => ['Forwarded']]);

    expect(Ip::clientIp($request))->toBe('10.0.0.1');
});

it('warns once when forwarding headers arrive unconfigured, naming headers and never values', function() {
    Ip::clientIp(ipRequest('203.0.113.9', ['X-Forwarded-For' => '198.51.100.222'], ['trustedHosts' => ['any']]));
    Ip::clientIp(ipRequest('203.0.113.9', ['X-Forwarded-For' => '198.51.100.222'], ['trustedHosts' => ['any']]));

    $warnings = ipWarnings();

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('X-Forwarded-For')
        ->and($warnings[0])->not->toContain('198.51.100.222');
});

it('stays quiet with no forwarding headers, and a failing cache never breaks resolution', function() {
    expect(Ip::clientIp(ipRequest('203.0.113.9', [], ['trustedHosts' => ['any']])))->toBe('203.0.113.9')
        ->and(ipWarnings())->toHaveCount(0);

    $cache = Craft::$app->getCache();
    $broken = $this->createMock(\yii\caching\CacheInterface::class);
    $broken->method('add')->willThrowException(new RuntimeException('private-backend'));
    Craft::$app->set('cache', $broken);

    try {
        expect(Ip::clientIp(ipRequest('203.0.113.9', ['X-Forwarded-For' => '198.51.100.222'], ['trustedHosts' => ['any']])))->toBe('203.0.113.9');
    } finally {
        Craft::$app->set('cache', $cache);
    }
});

it('normalises addresses so one visitor has one form', function() {
    expect(Ip::normalise('::ffff:203.0.113.5'))->toBe('203.0.113.5')
        ->and(Ip::normalise('2001:DB8:0:0:0:0:0:1'))->toBe('2001:db8::1')
        ->and(Ip::normalise('[2001:db8::1]:8080'))->toBe('2001:db8::1')
        ->and(Ip::normalise('[2001:db8::1]'))->toBe('2001:db8::1')
        ->and(Ip::normalise(' 203.0.113.5:1234 '))->toBe('203.0.113.5')
        ->and(Ip::normalise('unknown'))->toBeNull()
        ->and(Ip::normalise(''))->toBeNull()
        ->and(Ip::normalise('999.1.1.1'))->toBeNull();
});

it('buckets true IPv6 to /64 and leaves IPv4 and mapped addresses distinct', function() {
    expect(Ip::bucket('2001:db8:1:2:3:4:5:6'))->toBe(Ip::bucket('2001:db8:1:2:ffff::1'))
        ->and(Ip::bucket('2001:DB8:1:2::1'))->toBe(Ip::bucket('2001:db8:1:2:0:0:0:9'))
        ->and(Ip::bucket('2001:db8:1:3::1'))->not->toBe(Ip::bucket('2001:db8:1:2::1'))
        ->and(Ip::bucket('203.0.113.5'))->toBe('203.0.113.5')
        ->and(Ip::bucket('::ffff:203.0.113.5'))->toBe('203.0.113.5')
        ->and(Ip::bucket('::ffff:203.0.113.5'))->not->toBe(Ip::bucket('::ffff:203.0.113.6'));
});

it('collects only what fromRequest is asked for', function() {
    $request = ipRequest('203.0.113.9', ['User-Agent' => 'Agent', 'Referer' => 'https://example.test/page'], ['trustedHosts' => ['any']]);

    $default = RequestSignals::fromRequest($request);
    $all = RequestSignals::fromRequest($request, userAgent: true, referrer: true);
    $none = RequestSignals::fromRequest($request, ipAddress: false);

    expect($default->ipAddress)->toBe('203.0.113.9')
        ->and($default->userAgent)->toBeNull()
        ->and($default->referrer)->toBeNull()
        ->and($all->userAgent)->toBe('Agent')
        ->and($all->referrer)->toBe('https://example.test/page')
        ->and($none->ipAddress)->toBeNull();
});

it('returns empty signals for a console request', function() {
    $signals = RequestSignals::fromRequest(new ConsoleRequest(), userAgent: true, referrer: true);

    expect($signals->ipAddress)->toBeNull()
        ->and($signals->userAgent)->toBeNull()
        ->and($signals->referrer)->toBeNull();
});
