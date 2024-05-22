<?php

namespace Extensions\SymfonyRedisCache;

use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Redis\Connections\Connection;
use Symfony\Contracts\Cache\ItemInterface;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Cache\TaggableStore;
use Illuminate\Cache\PhpRedisLock;
use Illuminate\Cache\RedisLock;
use Closure;

class Store extends TaggableStore implements LockProvider
{
    use InteractsWithTime;

    protected ?string $lockConnection = null;

    /**
     * Create a new Redis store.
     *
     * @param  \Illuminate\Contracts\Redis\Factory  $redis
     * @param  string  $prefix
     * @param  string  $connection
     * @return void
     */
    public function __construct(
        protected Redis $redis,
        protected string $prefix = '',
        protected string $connection = 'default',
    ) {
    }

    public function setConnection(string $connection): void
    {
        $this->connection = $connection;
    }

    public function connection(): Connection
    {
        return $this->redis->connection($this->connection);
    }

    /**
     * Get the Redis connection instance.
     *
     * @return RedisTagAwareAdapter
     */
    public function client(): RedisTagAwareAdapter
    {
        return new RedisTagAwareAdapter($this->connection()->client(), $this->getPrefix());
    }

    /**
     *
     * @param string $key
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function get($key)
    {
        return $this->client()->getItem($key)->get();
    }

    /**
     *
     * @param array $keys
     * @return array
     * @throws InvalidArgumentException
     */
    public function many(array $keys)
    {
        $results = $this->client()->getItems($keys);
        return collect(iterator_to_array($results))->map(fn(ItemInterface $item) => $item->get())->toArray();
    }

    /**
     *
     * @param string $key
     * @param mixed $value
     * @param int $seconds
     * @return bool
     * @throws InvalidArgumentException
     */
    public function add($key, $value, $seconds)
    {
        return $this->put($key, $value, $seconds);
    }

    /**
     *
     * @param string $key
     * @param mixed $value
     * @param int $seconds
     * @return bool
     * @throws InvalidArgumentException
     */
    public function put($key, $value, $seconds)
    {
        $item = $this->client()->getItem($key);

        $item->set($value);
        $item->expiresAfter((int) max(1, $seconds));

        return $this->client()->save($item);
    }

    /**
     *
     * @param array $values
     * @param int $seconds
     * @return bool
     * @throws InvalidArgumentException
     */
    public function putMany(array $values, $seconds)
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
     * @param string $key
     * @return null|float|int
     * @throws InvalidArgumentException
     */
    private function getExpiration($key): null|float|int
    {
        $item = $this->client()->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        $meta = $item->getMetadata();
        $expiresAt = $meta[ItemInterface::METADATA_EXPIRY];

        return round($expiresAt - $this->currentTime());
    }

    protected function incrementOrDecrement(string $key, int $value, Closure $callback): int|bool
    {
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
     * @return int|bool
     * @throws InvalidArgumentException
     */
    public function increment($key, $value = 1)
    {
        return $this->incrementOrDecrement($key, $value, fn($current, $value) => $current + $value);
    }

    /**
     *
     * @param string $key
     * @param int $value
     * @return int|bool
     * @throws InvalidArgumentException
     */
    public function decrement($key, $value = 1)
    {
        return $this->incrementOrDecrement($key, $value, fn($current, $value) => $current - $value);
    }

    /**
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     * @throws InvalidArgumentException
     */
    public function forever($key, $value)
    {
        return $this->put($key, $value, 315_360_000);
    }

    /**
     *
     * @param string $key
     * @return bool
     * @throws InvalidArgumentException
     */
    public function forget($key)
    {
        return $this->client()->deleteItem($key);
    }

    /**
     *
     * @return bool
     */
    public function flush()
    {
        return $this->client()->clear();
    }

    /**
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Sets the tags to be used
     *
     * @param array $tags
     * @return RedisTaggedCache
     */
    public function tags($tags)
    {
        return new RedisTaggedCache($this, $tags);
    }

    /**
     *
     * @param string $connection
     * @return Store
     */
    public function setLockConnection(string $connection): self
    {
        $this->lockConnection = $connection;
        return $this;
    }

    /**
     *
     * @return Connection
     */
    public function lockConnection(): Connection
    {
        return $this->redis->connection($this->lockConnection ?? $this->connection);
    }

    /**
     * Get a lock instance.
     *
     * @param  string  $name
     * @param  int  $seconds
     * @param  string|null  $owner
     * @return \Illuminate\Contracts\Cache\Lock
     */
    public function lock($name, $seconds = 0, $owner = null)
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
     * @return \Illuminate\Contracts\Cache\Lock
     */
    public function restoreLock($name, $owner)
    {
        return $this->lock($name, 0, $owner);
    }
}
