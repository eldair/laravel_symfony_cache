<?php

namespace Extensions\SymfonyRedisCache;

use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Contracts\Cache\CanFlushLocks;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Redis\Connections\Connection;
use Symfony\Contracts\Cache\ItemInterface;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Cache\TaggableStore;
use Illuminate\Cache\PhpRedisLock;
use Illuminate\Cache\RedisLock;
use RuntimeException;
use Closure;

use function Illuminate\Support\enum_value;

class Store extends TaggableStore implements CanFlushLocks, LockProvider
{
    use InteractsWithTime;

    /**
     * The name of the connection that should be used for locks.
     */
    protected ?string $lockConnection = '';

    /**
     * Create a new Redis store.
     */
    public function __construct(
        protected Redis $redis,
        protected string $prefix = '',
        protected string $connection = 'default',
        protected array|bool|null $serializableClasses = null,
    ) {}

    public function cleanKey(string $key): string
    {
        return str_replace(str_split(ItemInterface::RESERVED_CHARACTERS), '.', enum_value($key));
    }

    public function setConnection(string $connection): void
    {
        $this->connection = $connection;
    }

    public function connection(): Connection
    {
        return $this->redis->connection($this->connection);
    }

    public function setLockConnection(string $connection): self
    {
        $this->lockConnection = $connection;
        return $this;
    }

    public function lockConnection(): Connection
    {
        return $this->redis->connection($this->lockConnection ?? $this->connection);
    }

    /**
     * Get the Redis connection instance.
     */
    public function client(): RedisTagAwareAdapter
    {
        return new RedisTagAwareAdapter($this->connection()->client(), $this->getPrefix());
    }

    /**
     *
     * @throws InvalidArgumentException
     */
    public function get($key): mixed
    {
        return $this->client()->getItem($this->cleanKey($key))->get();
    }

    /**
     *
     * @throws InvalidArgumentException
     */
    public function many(array $keys): array
    {
        if (count($keys) === 0) {
            return [];
        }

        $results = $this->client()->getItems(array_map($this->cleanKey(...), $keys));

        return collect(iterator_to_array($results))->map(fn(ItemInterface $item): mixed => $item->get())->toArray();
    }

    /**
     *
     * @throws InvalidArgumentException
     */
    public function add(string $key, mixed $value, ?int $seconds): bool
    {
        return $this->put($this->cleanKey($key), $value, $seconds);
    }

    public function checkForSerializableClasses(mixed $value): mixed
    {
        if (!is_object($value)) {
            return $value;
        }

        if (!is_array($this->serializableClasses) || count($this->serializableClasses) === 0) {
            throw new InvalidArgumentException('Provided object cannot be serialized per config.');
        }

        foreach ($this->serializableClasses as $serializableClasse) {
            if ($value instanceof $serializableClasse) {
                return $value;
            }
        }

        throw new InvalidArgumentException('Provided object cannot be serialized per config.');
    }

    /**
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $seconds
     * @throws InvalidArgumentException
     */
    public function put($key, $value, $seconds): bool
    {
        $item = $this->client()->getItem($this->cleanKey($key));

        $item->set($this->checkForSerializableClasses($value));
        $item->expiresAfter($seconds !== null ? max(1, $seconds) : $seconds);

        return $this->client()->save($item);
    }

    /**
     *
     * @param int|null $seconds
     * @throws InvalidArgumentException
     */
    public function putMany(array $values, $seconds): bool
    {
        $manyResult = null;

        foreach ($values as $key => $value) {
            $result = $this->put($key, $value, $seconds);

            $manyResult = is_null($manyResult) ? $result : $result && $manyResult;
        }

        return $manyResult ?: false;
    }

    /**
     *
     * @throws InvalidArgumentException
     */
    private function getExpiration(string $key): ?float
    {
        $item = $this->client()->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        $meta = $item->getMetadata();
        $expiresAt = $meta[ItemInterface::METADATA_EXPIRY] ?? 0;

        return $expiresAt !== null ? round($expiresAt - $this->currentTime()) : null;
    }

    protected function incrementOrDecrement(string $key, int $value, Closure $callback): int|bool
    {
        $key = $this->cleanKey($key);
        $currentValue = $this->get($key);

        if ($currentValue === null) {
            return false;
        }

        $newValue = $callback($currentValue, $value);

        if ($this->put($key, $newValue, $this->getExpiration($key))) {
            return $newValue;
        }

        return false;
    }

    /**
     *
     * @param string $key
     * @param int $value
     * @throws InvalidArgumentException
     */
    public function increment($key, $value = 1): int|bool
    {
        return $this->incrementOrDecrement($key, $value, fn($current, $value): float|int|array => $current + $value);
    }

    /**
     *
     * @param string $key
     * @param int $value
     * @throws InvalidArgumentException
     */
    public function decrement($key, $value = 1): int|bool
    {
        return $this->incrementOrDecrement($key, $value, fn($current, $value): int|float => $current - $value);
    }

    /**
     *
     * @param string $key
     * @param mixed $value
     * @throws InvalidArgumentException
     */
    public function forever($key, $value): bool
    {
        return $this->put($key, $value, null);
    }

    /**
     * Get a lock instance.
     *
     * @param  string  $name
     * @param  int  $seconds
     * @param  string|null  $owner
     */
    public function lock($name, $seconds = 0, $owner = null): PhpRedisLock|RedisLock
    {
        $lockName = $this->getPrefix() . $name;

        $lockConnection = $this->lockConnection();

        if ($lockConnection instanceof PhpRedisConnection) {
            return new PhpRedisLock($lockConnection, $lockName, $seconds, $owner);
        }

        return new RedisLock($lockConnection, $lockName, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     *
     * @param  string  $name
     * @param  string  $owner
     */
    public function restoreLock($name, $owner): PhpRedisLock|RedisLock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Adjust the expiration time of a cached item.
     *
     * @param  string  $key
     * @param  int  $seconds
     */
    public function touch($key, $seconds): bool
    {
        $item = $this->client()->getItem($this->cleanKey($key));
        $item->expiresAfter((int) max(1, $seconds));

        return $this->client()->save($item);
    }

    /**
     *
     * @param string $key
     * @throws InvalidArgumentException
     */
    public function forget($key): bool
    {
        return $this->client()->deleteItem($this->cleanKey($key));
    }

    public function flush(): bool
    {
        return $this->client()->clear();
    }

    /**
     * Determine if the lock store is separate from the cache store.
     */
    public function hasSeparateLockStore(): bool
    {
        return $this->lockConnection !== $this->connection;
    }

    /**
     * Remove all locks from the store.
     * @throws \RuntimeException
     */
    public function flushLocks(): bool
    {
        if (!$this->hasSeparateLockStore()) {
            throw new RuntimeException(
                'Flushing locks is only supported when the lock store is separate from the cache store.',
            );
        }

        $this->lockConnection()->flushdb();

        return true;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Sets the tags to be used
     *
     * @param array $tags
     */
    #[\Override]
    public function tags($tags): RedisTaggedCache
    {
        return new RedisTaggedCache($this, $tags);
    }
}
