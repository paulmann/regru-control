<?php

declare(strict_types=1);

namespace RegRuControl\Support;

final class Arr
{
    /**
     * @param array<array-key, mixed> $array
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        $cursor = $array;

        foreach (explode('.', $key) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return $default;
            }

            $cursor = $cursor[$part];
        }

        return $cursor;
    }

    /**
     * @param array<array-key, mixed> $array
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function only(array $array, array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                $result[$key] = $array[$key];
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $array
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function except(array $array, array $keys): array
    {
        foreach ($keys as $key) {
            unset($array[$key]);
        }

        return $array;
    }

    /**
     * @template T
     * @param iterable<T> $items
     * @param callable(T): (string|int) $keyFn
     * @return array<string|int, list<T>>
     */
    public static function groupBy(iterable $items, callable $keyFn): array
    {
        $groups = [];

        foreach ($items as $item) {
            $groups[$keyFn($item)][] = $item;
        }

        return $groups;
    }

    /**
     * Case-insensitive search over a list of associative arrays.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    public static function search(array $rows, string $needle, array $fields): array
    {
        $needle = mb_strtolower(trim($needle));

        if ($needle === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($needle, $fields): bool {
            foreach ($fields as $field) {
                $value = $row[$field] ?? null;

                if (is_scalar($value) && str_contains(mb_strtolower((string) $value), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
