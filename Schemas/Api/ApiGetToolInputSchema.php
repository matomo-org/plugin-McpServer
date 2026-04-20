<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Api;

final class ApiGetToolInputSchema
{
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'method' => [
                'type' => 'string',
                'minLength' => 1,
                'description' => 'Exact Matomo API method name as Module.action.',
            ],
            'module' => [
                'type' => 'string',
                'minLength' => 1,
                'description' => 'Exact Matomo API module name.',
            ],
            'action' => [
                'type' => 'string',
                'minLength' => 1,
                'description' => 'Exact Matomo API action name.',
            ],
        ],
        // Selector truth table:
        // valid:   method
        // valid:   module + action
        // invalid: no selector, partial module/action selector, or method combined
        //          with module/action
        'not' => [
            'anyOf' => [
                [
                    'not' => [
                        'anyOf' => [
                            ['required' => ['method']],
                            ['required' => ['module']],
                            ['required' => ['action']],
                        ],
                    ],
                ],
                ['required' => ['method', 'module']],
                ['required' => ['method', 'action']],
                [
                    'required' => ['module'],
                    'not' => ['required' => ['action']],
                ],
                [
                    'required' => ['action'],
                    'not' => ['required' => ['module']],
                ],
            ],
        ],
        'additionalProperties' => false,
    ];
}
