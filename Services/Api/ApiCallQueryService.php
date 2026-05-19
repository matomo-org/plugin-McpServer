<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Api;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\DataTable\DataTableInterface;
use Piwik\DataTable\Renderer\Json;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiCallQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\CoreApiCallGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiCallRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Errors\CoreApiRequestException;

final class ApiCallQueryService implements ApiCallQueryServiceInterface
{
    private const GENERIC_FAILURE_MESSAGE = 'Matomo API request failed.';
    private const DETAILED_FAILURE_PREFIX = 'Matomo API request failed: ';

    /** @var array<string, true> */
    private const RESERVED_PARAMETER_KEYS = [
        'method' => true,
        'module' => true,
        'action' => true,
        'format' => true,
        'serialize' => true,
        'token_auth' => true,
        'force_api_session' => true,
    ];

    public function __construct(private CoreApiCallGatewayInterface $coreApiCallGateway)
    {
    }

    public function callApi(
        ApiMethodSummaryRecord $resolvedMethod,
        ?array $parameters = null,
    ): ApiCallRecord {
        $sanitizedParameters = $this->sanitizeParameters($parameters);

        try {
            $result = $this->coreApiCallGateway->call($resolvedMethod->method, $sanitizedParameters);
        } catch (AccessDeniedLikeException) {
            throw new ToolCallException('No access to API method.');
        } catch (CoreApiRequestException $e) {
            throw new ToolCallException($this->buildFailureMessage($e));
        }

        return new ApiCallRecord(
            $this->normalizeValue($result, 'API response'),
            $resolvedMethod,
        );
    }

    /**
     * @param array<string, mixed>|null $parameters
     * @return array<string, mixed>
     */
    private function sanitizeParameters(?array $parameters): array
    {
        $parameters ??= [];

        foreach ($parameters as $key => $_value) {
            if (isset(self::RESERVED_PARAMETER_KEYS[strtolower($key)])) {
                throw new ToolCallException("Unsupported parameters key '{$key}'.");
            }
        }

        return $parameters;
    }

    private function normalizeValue(mixed $value, string $context): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof DataTableInterface) {
            try {
                $renderer = new Json();
                $renderer->setTable($value);
                $json = $renderer->render();

                return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new ToolCallException($context . ' is invalid.');
            }
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item, $context);
            }

            return $normalized;
        }

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            return json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ToolCallException($context . ' is invalid.');
        }
    }

    private function buildFailureMessage(CoreApiRequestException $e): string
    {
        $detail = $this->extractFailureDetail($e);
        if ($detail === null) {
            return self::GENERIC_FAILURE_MESSAGE;
        }

        return self::DETAILED_FAILURE_PREFIX . $detail;
    }

    private function extractFailureDetail(CoreApiRequestException $e): ?string
    {
        $previous = $e->getPrevious();
        if (!$previous instanceof \Throwable) {
            return null;
        }

        $message = trim(preg_replace('/\s+/', ' ', $previous->getMessage()) ?? '');
        if ($message === '') {
            return null;
        }

        $message = rtrim($message, ". \t\n\r\0\x0B");
        if ($message === '') {
            return null;
        }

        if ($this->shouldSuppressFailureDetail($message)) {
            return null;
        }

        return $message . '.';
    }

    private function shouldSuppressFailureDetail(string $message): bool
    {
        $normalized = strtolower($message);

        $unsafeFragments = [
            // Secret/session-token wording. Access-denial phrasing is routed
            // via NoAccessLikeErrorDetector before this denylist runs.
            'token_auth',
            'bearer ',
            'session token',
            'session id',
            'phpsessid',
            'force_api_session',

            // SQL internals. Verb+clause SQL is handled by the regex below.
            'sqlstate',
            'create table',
            'drop table',
            'alter table',

            // PHP runtime crash wording. "Uncaught"/"Stack trace" live in
            // getTraceAsString(), not getMessage(), so they are not listed.
            'call to undefined ',

            // Internal invariant/class-name failures.
            'sanity check:',
            'unknown datatable type',
            'unexpected datatable type',

            // Filesystem path leakage.
            "wasn't found in ",
        ];

        foreach ($unsafeFragments as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        // Raw SQL leakage: verb + matching clause keyword. The clause context
        // avoids suppressing prose like "please select a value" or
        // "failed to delete user".
        if (
            preg_match(
                '/\bselect\b.{0,200}\bfrom\b|\binsert\s+into\b|\bupdate\b.{0,200}\bset\b|\bdelete\s+from\b/s',
                $normalized,
            ) === 1
        ) {
            return true;
        }

        // Namespaced class-like tokens ("Piwik\DataTable\Map"). Require two
        // PascalCase segments so stray escapes like "\n" or "\d" do not match.
        if (preg_match('/\b[A-Z][A-Za-z0-9_]+(?:\\\\[A-Z][A-Za-z0-9_]*){1,}/', $message) === 1) {
            return true;
        }

        // Absolute filesystem paths.
        return preg_match('~(?:^|[\s(])/(?:[^/\s]+/)+[^/\s)]+~', $message) === 1;
    }
}
