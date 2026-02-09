<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Capability\Attribute\McpTool;

class HelloMatomo
{
    /**
     * @return array{hello: 'Matomo'}
     */
    #[McpTool(name: 'matomo_hello')]
    public function hello(): array
    {
        return ['hello' => 'Matomo'];
    }
}
