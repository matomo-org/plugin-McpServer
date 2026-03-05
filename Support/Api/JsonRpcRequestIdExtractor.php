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
    /**
     * @return string|int
     */
    public function extractId(ServerRequestInterface $request): string|int
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
            return '';
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return '';
        }

        if (is_array($decoded) && !$this->isList($decoded)) {
            /** @var array<string, mixed> $message */
            $message = $decoded;

            return $this->extractIdFromMessage($message);
        }

        if (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
            /** @var array<string, mixed> $message */
            $message = $decoded[0];

            return $this->extractIdFromMessage($message);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $message
     * @return string|int
     */
    private function extractIdFromMessage(array $message): string|int
    {
        $id = $message['id'] ?? '';
        if (is_string($id) || is_int($id)) {
            return $id;
        }

        return '';
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
