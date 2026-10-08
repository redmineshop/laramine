<?php

namespace App\Http\Api;

/**
 * One REST response body and status.
 *
 * A null body is an empty 204. Error payloads use an `errors` list except
 * the impersonation failure, which keeps the singular `error` string.
 */
final class ApiResult
{
    /**
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $body,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function ok(array $body, int $status = 200): self
    {
        return new self($status, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function created(array $body): self
    {
        return new self(201, $body);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    public static function fail(int $status, string ...$messages): self
    {
        $lines = $messages === [] ? ['Request failed.'] : array_values($messages);

        return new self($status, ['errors' => $lines]);
    }

    public static function message(int $status, string $key, string $message): self
    {
        return new self($status, [$key => $message]);
    }
}
