<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\Services\Reports;

use Piwik\ArchiveProcessor\Rules;
use Piwik\Cache;
use Piwik\Config;
use Piwik\Plugins\McpServer\Services\Reports\StrictSegmentPolicyService;
use Piwik\Plugins\SegmentEditor\API as SegmentEditorApi;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class StrictSegmentPolicyServiceTest extends IntegrationTestCase
{
    private int $idSite = 0;

    public function setUp(): void
    {
        parent::setUp();

        $this->idSite = Fixture::createWebsite(
            '2015-01-01 00:00:00',
            0,
            'MCP Strict Segment Policy Service Test Site',
            'https://strict-segment-policy-service.test',
        );
    }

    public function testReturnsFalseForNullSegment(): void
    {
        $service = new StrictSegmentPolicyService();

        self::assertFalse($service->shouldMapToStrictSegmentGuidance($this->idSite, 'day', 'today', null));
    }

    public function testReturnsFalseForBlankSegment(): void
    {
        $service = new StrictSegmentPolicyService();

        self::assertFalse($service->shouldMapToStrictSegmentGuidance($this->idSite, 'day', 'today', '   '));
    }

    public function testReturnsFalseWhenRuntimeSegmentProcessingIsAvailable(): void
    {
        $service = new StrictSegmentPolicyService();

        $this->runWithSegmentArchivingMode(strictMode: false, callback: function () use ($service): void {
            $segment = 'countryCode==de;browserCode==ff';
            self::assertFalse(
                $service->shouldMapToStrictSegmentGuidance($this->idSite, 'day', 'today', $segment),
            );
        });
    }

    public function testReturnsTrueWhenRuntimeSegmentProcessingUnavailableAndSegmentNotPreprocessed(): void
    {
        $service = new StrictSegmentPolicyService();

        $this->runWithSegmentArchivingMode(strictMode: true, callback: function () use ($service): void {
            $segment = 'countryCode==fr;browserCode==ff';
            self::assertTrue(
                $service->shouldMapToStrictSegmentGuidance($this->idSite, 'day', 'today', $segment),
            );
        });
    }

    public function testReturnsFalseWhenRuntimeSegmentProcessingUnavailableAndSegmentIsPreprocessed(): void
    {
        $service = new StrictSegmentPolicyService();
        $segment = 'countryCode==de;browserCode==ff';

        $this->runWithSegmentArchivingMode(strictMode: true, callback: function () use ($service, $segment): void {
            SegmentEditorApi::getInstance()->add(
                'MCP Strict Segment Policy Service ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
                $segment,
                $this->idSite,
                true,
            );
            Cache::getTransientCache()->flushAll();

            self::assertFalse(
                $service->shouldMapToStrictSegmentGuidance($this->idSite, 'day', 'today', $segment),
            );
        });
    }

    public function testReturnsFalseWhenSegmentPreprocessedEvaluationFails(): void
    {
        $service = new StrictSegmentPolicyService();

        $this->runWithSegmentArchivingMode(strictMode: true, callback: function () use ($service): void {
            self::assertFalse(
                $service->shouldMapToStrictSegmentGuidance($this->idSite, 'invalidPeriod', 'today', 'countryCode==de'),
            );
        });
    }

    private function runWithSegmentArchivingMode(bool $strictMode, callable $callback): void
    {
        $config = Config::getInstance();
        $general = $config->General;
        if (!is_array($general)) {
            throw new \RuntimeException('Invalid Matomo general config state.');
        }

        $originalEnableBrowserArchivingTriggering = (int) ($general['enable_browser_archiving_triggering'] ?? 1);
        $originalBrowserArchivingDisabledEnforce = (int) ($general['browser_archiving_disabled_enforce'] ?? 0);
        $originalBrowserTriggerEnabled = Rules::isBrowserTriggerEnabled();

        try {
            $general['enable_browser_archiving_triggering'] = $strictMode ? 0 : 1;
            $general['browser_archiving_disabled_enforce'] = $strictMode ? 1 : 0;
            $config->General = $general;
            Rules::setBrowserTriggerArchiving(!$strictMode);
            Cache::getTransientCache()->flushAll();

            $callback();
        } finally {
            $general['enable_browser_archiving_triggering'] = $originalEnableBrowserArchivingTriggering;
            $general['browser_archiving_disabled_enforce'] = $originalBrowserArchivingDisabledEnforce;
            $config->General = $general;
            Rules::setBrowserTriggerArchiving((bool) $originalBrowserTriggerEnabled);
            Cache::getTransientCache()->flushAll();
        }
    }
}
