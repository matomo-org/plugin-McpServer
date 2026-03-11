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
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\CoreApiCallGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiCallRecord;
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

    public function __construct(
        private ApiMethodSummaryQueryServiceInterface $apiMethodSummaryQueryService,
        private CoreApiCallGatewayInterface $coreApiCallGateway,
    ) {
    }

    public function callApi(
        string $accessMode,
        ?string $method = null,
        ?string $module = null,
        ?string $action = null,
        ?array $parameters = null,
    ): ApiCallRecord {
        $resolvedMethod = $this->apiMethodSummaryQueryService->getApiMethodSummaryBySelector(
            $accessMode,
            $method,
            $module,
            $action,
        );
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
        $detail = $this->extractSafeFailureDetail($e);
        if ($detail === null) {
            return self::GENERIC_FAILURE_MESSAGE;
        }

        return self::DETAILED_FAILURE_PREFIX . $detail;
    }

    private function extractSafeFailureDetail(CoreApiRequestException $e): ?string
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

        if (!$this->isSafeFailureDetail($message)) {
            return null;
        }

        return $message . '.';
    }

    private function isSafeFailureDetail(string $message): bool
    {
        if (strlen($message) > 160) {
            return false;
        }

        $normalized = strtolower($message);
        $unsafeFragments = [
            'sqlstate',
            'select ',
            'insert ',
            'update ',
            'delete ',
            'create table',
            'drop table',
            'alter table',
            'exception',
            'stack trace',
            ' in /',
            ' at /',
            '/var/www/',
            '\\',
            '<',
            '>',
            'token_auth',
            'bearer ',
            'session',
            'permission denied',
            'call to ',
            'uncaught ',
        ];

        foreach ($unsafeFragments as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return false;
            }
        }

        if (preg_match('/#\d+/', $message) === 1) {
            return false;
        }

        $safeSignals = [
            'parameter',
            'missing',
            'invalid',
            'must be',
            'required',
            'expected',
            'unknown value',
            'not supported',
            'out of range',
        ];

        foreach ($safeSignals as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }
}
