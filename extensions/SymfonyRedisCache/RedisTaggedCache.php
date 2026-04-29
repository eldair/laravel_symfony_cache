<?php

namespace Extensions\SymfonyRedisCache;

use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Cache\Events\KeyWriteFailed;
use Symfony\Contracts\Cache\ItemInterface;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Repository;

/**
 * @property Store $store
 */
class RedisTaggedCache extends Repository
{
    public function __construct(Store $store, protected array $tags = [])
    {
        parent::__construct($store, ['store' => 'redis']);
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param  \DateTimeInterface|\DateInterval|int|null  $ttl
     * @return bool
     */
    #[\Override]
    public function putMany(array $values, $ttl = null)
    {
        if ($ttl === null) {
            return $this->putManyForever($values);
        }

        $seconds = $this->getSeconds($ttl);

        $keys = array_map(fn(string $key): string => $this->store->cleanKey($key), array_keys($values));
        $values = array_combine($keys, array_values($values));

        if ($seconds <= 0) {
            return $this->deleteMultiple($keys);
        }

        $this->event(new WritingManyKeys($this->getName(), $keys, array_values($values), $seconds));

        $manyResult = null;

        foreach ($values as $key => $value) {
            $result = $this->put($key, $value, $seconds);

            $manyResult = is_null($manyResult) ? $result : $result && $manyResult;
        }

        $manyResult = $manyResult ?: false;

        foreach ($values as $key => $value) {
            if ($manyResult) {
                $this->event(new KeyWritten($this->getName(), $key, $value, $seconds));
            } else {
                $this->event(new KeyWriteFailed($this->getName(), $key, $value, $seconds));
            }
        }

        return $manyResult;
    }

    /**
     * Store an item in the cache.
     *
     * @param  array|string  $key
     * @param  mixed  $value
     * @param  \DateTimeInterface|\DateInterval|int|null  $ttl
     */
    #[\Override]
    public function put($key, $value, $ttl = null): bool
    {
        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

        if (is_null($ttl)) {
            return $this->forever($key, $value);
        }

        $key = $this->store->cleanKey($key);

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return $this->forget($key);
        }

        if ($this->store($key, $value, $seconds)) {
            $this->event(new KeyWritten($this->getName(), $key, $value, $seconds));
            return true;
        }

        $this->event(new KeyWriteFailed($this->getName(), $key, $value, $seconds));
        return false;
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param  \UnitEnum|string  $key
     * @param  mixed  $value
     * @return bool
     */
    public function forever($key, $value)
    {
        $key = $this->store->cleanKey($key);

        if ($this->store($key, $value)) {
            $this->event(new KeyWritten($this->getName(), $key, $value));
            return true;
        }

        $this->event(new KeyWriteFailed($this->getName(), $key, $value));
        return false;
    }

    private function store(string $key, mixed $value, ?int $seconds = null): bool
    {
        $client = $this->store->client();
        $value = $this->store->checkForSerializableClasses($value);

        $client->get(
            $key,
            function (ItemInterface $item) use ($value, $seconds) {
                $item->expiresAfter($seconds);
                $item->tag($this->tags);

                return $value;
            },
            \INF,
        );

        $this->event(new WritingKey($this->getName(), $key, $value, $seconds));

        $resultItem = $client->getItem($key);
        return $resultItem->isHit();
    }

    /**
     * Flushes the cache for the given tags
     */
    public function flush(): bool
    {
        return $this->store->client()->invalidateTags($this->tags);
    }
}
