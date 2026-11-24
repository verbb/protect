<?php

use verbb\protect\Protect;
use verbb\protect\checks\RateLimit;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\enums\FindingType;
use verbb\protect\errors\StateException;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionEvidence;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\RateLimitRule;
use verbb\protect\models\RequestSignals;
use verbb\protect\services\State;

use yii\caching\CacheInterface;
use yii\mutex\Mutex;

it('turns backend exceptions into redacted errors and releases acquired locks', function() {
    foreach (['counter', 'replay'] as $kind) {
        foreach (['acquire', 'get', 'write', 'release'] as $failure) {
            $cache = $this->createMock(CacheInterface::class);
            $mutex = $this->getMockBuilder(Mutex::class)->onlyMethods(['acquire', 'release', 'acquireLock', 'releaseLock'])->getMock();
            $error = new RuntimeException('private-backend-host-and-credentials');

            if ($failure === 'acquire') {
                $mutex->method('acquire')->willThrowException($error);
                $mutex->expects($this->never())->method('release');
            } else {
                $mutex->method('acquire')->willReturn(true);
                $release = $mutex->expects($this->once())->method('release');

                if ($failure === 'release') {
                    $release->willThrowException($error);
                } else {
                    $release->willReturn(true);
                }
            }

            if ($failure === 'get') {
                $cache->method('get')->willThrowException($error);
            } else {
                $cache->method('get')->willReturn(false);
            }

            $write = $cache->method($kind === 'counter' ? 'set' : 'add');

            if ($failure === 'write') {
                $write->willThrowException($error);
            } else {
                $write->willReturn(true);
            }

            $protect = Protect::getInstance();
            $protect->set('state', new State(['cache' => $cache, 'mutex' => $mutex]));

            if ($kind === 'counter') {
                $plan = (new ProtectionPlan())->add(new RateLimit(['rules' => [new RateLimitRule()]]));
                $context = new ProtectionContext('outage', request: new RequestSignals('192.0.2.1'));
            } else {
                $plan = (new ProtectionPlan())->add(new ReplayProtection());
                $manifest = $protect->prepare(new ProtectionContext('outage'), $plan)->browserManifest();
                $context = new ProtectionContext('outage', evidence: new ProtectionEvidence($manifest->preparation));
            }

            $result = $protect->check($context, $plan);

            expect($result->hasErrors())->toBeTrue("$kind/$failure")
                ->and($result->hasFinding(FindingType::ProviderUnavailable))->toBeTrue()
                ->and($result->hasAdverseFindings())->toBeFalse()
                ->and(json_encode($result))->not->toContain('private-backend');
        }
    }
});

it('does not hide programming errors', function() {
    $cache = $this->createMock(CacheInterface::class);
    $mutex = $this->getMockBuilder(Mutex::class)->onlyMethods(['acquire', 'release', 'acquireLock', 'releaseLock'])->getMock();
    $mutex->method('acquire')->willReturn(true);
    $mutex->expects($this->once())->method('release')->willReturn(true);
    $cache->method('get')->willThrowException(new TypeError('Programming error'));

    (new State(['cache' => $cache, 'mutex' => $mutex]))->increment('test', 60);
})->throws(TypeError::class);

it('peeks at a counter without charging it, and surfaces peek outages as state errors', function() {
    $state = Protect::getInstance()->getState();
    $key = Protect::getInstance()->stateKey(['peek-test', bin2hex(random_bytes(8))]);

    expect($state->peek($key))->toBe(0)
        ->and($state->peek($key))->toBe(0)
        ->and($state->increment($key, 60))->toBe(1)
        ->and($state->peek($key))->toBe(1)
        ->and($state->peek($key))->toBe(1)
        ->and($state->increment($key, 60))->toBe(2);

    $cache = $this->createMock(CacheInterface::class);
    $cache->method('get')->willThrowException(new RuntimeException('private-backend'));

    (new State(['cache' => $cache]))->peek($key);
})->throws(StateException::class);
