<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Support;

/**
 * Reads the parameters of SmsClient's array methods: known keys only, each of the
 * expected type, so a typo or a wrong value fails before any request is made.
 *
 * @internal
 */
final class Parameters
{
    /**
     * @param array<array-key, mixed> $params
     * @param list<string>            $known  the keys the method accepts
     */
    public function __construct(
        private readonly array $params,
        array $known,
        private readonly string $method,
    ) {
        $unknown = array_diff(array_map('strval', array_keys($params)), $known);

        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('Unknown parameter "%s" for %s(); the accepted ones are: %s.', implode('", "', $unknown), $method, implode(', ', $known)));
        }
    }

    /**
     * The first of the keys that is set (not null), as a string; null when none is.
     */
    public function string(string ...$keys): ?string
    {
        [$key, $value] = $this->first($keys);

        if (null === $value) {
            return null;
        }

        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw $this->invalid($key, 'a string', $value);
    }

    /**
     * The first of the keys that is set, as a non-empty string.
     */
    public function requiredString(string $message, string ...$keys): string
    {
        $value = $this->string(...$keys);

        if (null === $value || '' === $value) {
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }

    /**
     * The first of the keys that is set, as an integer: a string of digits is accepted,
     * as forms and the environment give them.
     */
    public function int(string ...$keys): ?int
    {
        [$key, $value] = $this->first($keys);

        if (null === $value || \is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', trim($value))) {
            return (int) $value;
        }

        throw $this->invalid($key, 'an integer', $value);
    }

    /**
     * The first of the keys that is set, as a boolean: "1", "true", "on" and "yes" are
     * true, "0", "false", "off", "no" and "" false, as forms and the environment give them.
     */
    public function bool(string ...$keys): ?bool
    {
        [$key, $value] = $this->first($keys);

        if (null === $value || \is_bool($value)) {
            return $value;
        }

        if (\is_int($value) || \is_string($value)) {
            $bool = filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);

            if (null !== $bool) {
                return $bool;
            }
        }

        throw $this->invalid($key, 'a boolean', $value);
    }

    /**
     * The first of the keys that is set, as a date-time or a string.
     */
    public function dateTime(string ...$keys): \DateTimeInterface|string|null
    {
        [$key, $value] = $this->first($keys);

        if (null === $value || \is_string($value) || $value instanceof \DateTimeInterface) {
            return $value;
        }

        throw $this->invalid($key, 'a \DateTimeInterface or a "Y-m-d H:i:s" string', $value);
    }

    /**
     * @param array<string> $keys
     *
     * @return array{string, mixed}
     */
    private function first(array $keys): array
    {
        foreach ($keys as $key) {
            if (null !== ($this->params[$key] ?? null)) {
                return [$key, $this->params[$key]];
            }
        }

        return [$keys[0] ?? '', null];
    }

    private function invalid(string $key, string $expected, mixed $value): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf('The "%s" parameter of %s() must be %s, %s given.', $key, $this->method, $expected, get_debug_type($value)));
    }
}
