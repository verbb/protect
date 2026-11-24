<?php
namespace verbb\protect\services;

use verbb\protect\errors\StateException;

use Craft;
use craft\base\Component;

use yii\caching\CacheInterface;
use yii\mutex\Mutex;

use Exception;

/**
 * Expiring counters and claims over Craft's cache and mutex. Replace it through module config
 * for another backend. Every operation must stay atomic across the deployment, and any backend
 * failure must surface as a StateException so consumers keep their own availability policy.
 */
class State extends Component
{
    // Constants
    // =========================================================================

    public const CLAIMED = 'claimed';
    public const REPEATED = 'repeated';
    public const DENIED = 'denied';

    // Stored for a strict claim. A hash can't equal this, so strict and keyed claims never match.
    private const STRICT_VALUE = 'strict';

    // Properties
    // =========================================================================

    public ?CacheInterface $cache = null;
    public ?Mutex $mutex = null;

    // Public Methods
    // =========================================================================

    /**
     * Claims a key once. A later claim with the same non-null value reports REPEATED, and
     * anything else, including any later claim without a value, reports DENIED.
     */
    public function claim(string $key, int $ttl, ?string $value = null): string
    {
        return $this->_withLock($key, function(CacheInterface $cache) use ($key, $ttl, $value): string {
            $stored = $cache->get($key);

            if ($stored !== false) {
                return $value !== null && is_string($stored) && hash_equals($stored, $value) ? self::REPEATED : self::DENIED;
            }

            if (!$cache->add($key, $value ?? self::STRICT_VALUE, max(1, $ttl))) {
                throw new StateException('Claim write failed.');
            }

            return self::CLAIMED;
        });
    }

    public function increment(string $key, int $ttl): int
    {
        return $this->_withLock($key, function(CacheInterface $cache) use ($key, $ttl): int {
            $count = (int)$cache->get($key) + 1;

            if (!$cache->set($key, $count, max(1, $ttl))) {
                throw new StateException('Counter write failed.');
            }

            return $count;
        });
    }

    /**
     * Reads a counter without charging it, for budgets that check first and count only once
     * they know the outcome.
     */
    public function peek(string $key): int
    {
        try {
            return (int)($this->cache ?? Craft::$app->getCache())->get($key);
        } catch (Exception $e) {
            throw new StateException('Protection state is unavailable.', 0, $e);
        }
    }

    // Private Methods
    // =========================================================================

    private function _withLock(string $key, callable $operation): mixed
    {
        try {
            $cache = $this->cache ?? Craft::$app->getCache();
            $mutex = $this->mutex ?? Craft::$app->getMutex();

            // Yii's file cache add() is read-then-write, not an atomic compare-and-set, so the mutex
            // guards it too. Cache and mutex must share the application's topology.
            if (!$mutex->acquire($key, 3)) {
                throw new StateException('State lock unavailable.');
            }

            try {
                return $operation($cache);
            } finally {
                $mutex->release($key);
            }
        } catch (StateException $e) {
            throw $e;
        } catch (Exception $e) {
            // Backends may throw instead of returning false; preserve consumer outage policy.
            throw new StateException('Protection state is unavailable.', 0, $e);
        }
    }
}
