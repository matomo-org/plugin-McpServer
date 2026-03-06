<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Api;

use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;

final class JsonRpcRequestIdExtractor
{
    public function extractId(ServerRequestInterface $request): string|int
    {
        return $this->extractRequestMetadata($request)['requestId'];
    }

    /**
     * @return string|int|null Returns null when there is no top-level id.
     */
    public function extractTopLevelId(ServerRequestInterface $request): string|int|null
    {
        return $this->extractRequestMetadata($request)['topLevelRequestId'];
    }

    /**
     * @return array{requestId: string|int, topLevelRequestId: string|int|null}
     */
    public function extractRequestMetadata(ServerRequestInterface $request): array
    {
        $decoded = $this->decodeRequestBody($request);
        if (!is_array($decoded)) {
            return [
                'requestId' => '',
                'topLevelRequestId' => null,
            ];
        }

        if (!$this->isList($decoded)) {
            /** @var array<string, mixed> $message */
            $message = $decoded;
            $topLevelRequestId = $this->extractOptionalIdFromMessage($message);

            return [
                'requestId' => $topLevelRequestId ?? '',
                'topLevelRequestId' => $topLevelRequestId,
            ];
        }

        if (isset($decoded[0]) && is_array($decoded[0])) {
            /** @var array<string, mixed> $message */
            $message = $decoded[0];

            return [
                'requestId' => $this->extractIdFromMessage($message),
                'topLevelRequestId' => null,
            ];
        }

        return [
            'requestId' => '',
            'topLevelRequestId' => null,
        ];
    }

    private function decodeRequestBody(ServerRequestInterface $request): mixed
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $raw = $body->getContents();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if ($raw === '') {
            return null;
        }

        try {
            return json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function extractIdFromMessage(array $message): string|int
    {
        return $this->extractOptionalIdFromMessage($message) ?? '';
    }

    /**
     * @param array<string, mixed> $message
     */
    private function extractOptionalIdFromMessage(array $message): string|int|null
    {
        if (!array_key_exists('id', $message)) {
            return null;
        }

        $id = $message['id'];
        if (is_string($id) || is_int($id)) {
            return $id;
        }

        return null;
    }

    /**
     * @param array<mixed> $array
     */
    private function isList(array $array): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }

        return array_keys($array) === range(0, count($array) - 1);
    }
}
