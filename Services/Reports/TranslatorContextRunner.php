<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Reports;

use Piwik\Plugins\McpServer\Contracts\Ports\Reports\TranslatorContextRunnerInterface;
use Piwik\Translation\Translator;

final class TranslatorContextRunner implements TranslatorContextRunnerInterface
{
    public function __construct(private Translator $translator)
    {
    }

    public function runInEnglish(callable $callback): mixed
    {
        $originalLanguage = $this->translator->getCurrentLanguage();
        if ($originalLanguage === '') {
            $originalLanguage = $this->translator->getDefaultLanguage();
        }

        try {
            $this->translator->setCurrentLanguage('en');
            return $callback();
        } finally {
            $this->translator->setCurrentLanguage($originalLanguage);
        }
    }
}
