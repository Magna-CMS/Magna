<?php

declare(strict_types=1);

namespace Magna\Delivery\OpenApi;

/**
 * The OpenAPI fragments every operation repeats.
 *
 * Before this factory existed, `['description' => 'Unauthorized']` appeared
 * 35 times, the bearer security block 36 times and the path-parameter shape
 * 22 times across the generator — each one a chance to typo a public
 * document. The generated spec is pinned byte-for-byte by
 * OpenApiSnapshotTest, so any change here is an intentional, reviewed spec
 * change, never drift.
 */
final class ResponseShapes
{
    /**
     * Every operation in both APIs authenticates with the same bearer token.
     *
     * @return list<array<string, array{}>>
     */
    public static function bearer(): array
    {
        return [['BearerAuth' => []]];
    }

    /**
     * A success shape, then the 401/403 pair every management route shares,
     * then any route-specific tail (404, 422, ...) — in that order, because
     * the emitted document lists responses by likelihood of interest.
     *
     * @param  array<int|string, string>  $success  status code => description
     * @param  array<int|string, string>  $tail  status code => description
     * @return array<int|string, array{description: string}>
     */
    public static function guarded(array $success, array $tail = []): array
    {
        return self::describe($success + ['401' => 'Unauthorized', '403' => 'Forbidden'] + $tail);
    }

    /**
     * @param  array<int|string, string>  $codes  status code => description
     * @return array<int|string, array{description: string}>
     */
    public static function describe(array $codes): array
    {
        $responses = [];

        foreach ($codes as $code => $description) {
            $responses[$code] = ['description' => $description];
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    public static function pathParam(string $name): array
    {
        return ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
    }

    /**
     * @return array<string, mixed>
     */
    public static function perPageParam(): array
    {
        return ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 25]];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function jsonBody(array $schema): array
    {
        return ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function optionalJsonBody(array $schema): array
    {
        return ['content' => ['application/json' => ['schema' => $schema]]];
    }

    /**
     * One operation, keys in the order the emitted document has always used:
     * operationId, summary, tags, security, parameters?, requestBody?,
     * responses. Optional sections are omitted entirely, not emitted null.
     *
     * @param  array<int|string, array{description: string}>  $responses
     * @param  list<array<string, mixed>>|null  $parameters
     * @param  array<string, mixed>|null  $requestBody
     * @return array<string, mixed>
     */
    public static function operation(
        string $operationId,
        string $summary,
        string $tag,
        array $responses,
        ?array $parameters = null,
        ?array $requestBody = null,
    ): array {
        $operation = [
            'operationId' => $operationId,
            'summary' => $summary,
            'tags' => [$tag],
            'security' => self::bearer(),
        ];

        if ($parameters !== null) {
            $operation['parameters'] = $parameters;
        }

        if ($requestBody !== null) {
            $operation['requestBody'] = $requestBody;
        }

        $operation['responses'] = $responses;

        return $operation;
    }
}
