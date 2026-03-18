<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Support;

use ArrayAccess;
use Illuminate\Contracts\Config\Repository;

/**
 * @implements ArrayAccess<string, mixed>
 */
final class ArrayConfigRepository implements Repository, ArrayAccess
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private array $items = [])
    {
    }

    public function has($key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * @param string|array<int, string>|null $key
     * @return ($key is array ? array<string, mixed> : mixed)
     */
    public function get($key, $default = null): mixed
    {
        if (is_array($key)) {
            $result = [];

            foreach ($key as $innerKey) {
                $result[$innerKey] = $this->get($innerKey);
            }

            return $result;
        }

        if (!is_string($key) || $key === '') {
            return $default;
        }

        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @param string|array<string, mixed>|null $key
     */
    public function set($key, $value = null): void
    {
        if (is_array($key)) {
            foreach ($key as $innerKey => $innerValue) {
                $this->set($innerKey, $innerValue);
            }

            return;
        }

        if (!is_string($key) || $key === '') {
            return;
        }

        $segments = explode('.', $key);
        $target = &$this->items;

        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }

    public function prepend($key, $value): void
    {
        $current = $this->get($key, []);
        if (!is_array($current)) {
            $current = [];
        }

        array_unshift($current, $value);
        $this->set($key, $current);
    }

    public function push($key, $value): void
    {
        $current = $this->get($key, []);
        if (!is_array($current)) {
            $current = [];
        }

        $current[] = $value;
        $this->set($key, $current);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_string($offset) ? $this->get($offset) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_string($offset)) {
            $this->set($offset, $value);
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if (!is_string($offset) || $offset === '') {
            return;
        }

        $segments = explode('.', $offset);
        $last = array_pop($segments);
        $target = &$this->items;

        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                return;
            }

            $target = &$target[$segment];
        }

        if ($last !== null && array_key_exists($last, $target)) {
            unset($target[$last]);
        }
    }
}
