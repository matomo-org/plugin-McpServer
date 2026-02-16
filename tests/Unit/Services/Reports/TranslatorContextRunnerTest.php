<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Reports;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Services\Reports\TranslatorContextRunner;
use Piwik\Translation\Translator;

/**
 * @group McpServer
 * @group Plugins
 */
class TranslatorContextRunnerTest extends TestCase
{
    public function testRunInEnglishSwitchesToEnglishAndRestoresLanguageOnSuccess(): void
    {
        $translator = $this->createMock(Translator::class);
        $setCalls = [];

        $translator->expects(self::once())
            ->method('getCurrentLanguage')
            ->willReturn('de');
        $translator->expects(self::never())
            ->method('getDefaultLanguage');
        $translator->expects(self::exactly(2))
            ->method('setCurrentLanguage')
            ->willReturnCallback(static function (string $language) use (&$setCalls): void {
                $setCalls[] = $language;
            });

        $runner = new TranslatorContextRunner($translator);
        $result = $runner->runInEnglish(static function (): string {
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(['en', 'de'], $setCalls);
    }

    public function testRunInEnglishRestoresLanguageOnException(): void
    {
        $translator = $this->createMock(Translator::class);
        $setCalls = [];

        $translator->expects(self::once())
            ->method('getCurrentLanguage')
            ->willReturn('fr');
        $translator->expects(self::never())
            ->method('getDefaultLanguage');
        $translator->expects(self::exactly(2))
            ->method('setCurrentLanguage')
            ->willReturnCallback(static function (string $language) use (&$setCalls): void {
                $setCalls[] = $language;
            });

        $runner = new TranslatorContextRunner($translator);

        try {
            $runner->runInEnglish(static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(['en', 'fr'], $setCalls);
    }

    public function testRunInEnglishUsesRuntimeTranslatorPerCallNotStaleCapturedInstance(): void
    {
        $translatorOne = $this->createMock(Translator::class);
        $translatorTwo = $this->createMock(Translator::class);
        $setCallsOne = [];
        $setCallsTwo = [];

        $translatorOne->expects(self::once())
            ->method('getCurrentLanguage')
            ->willReturn('de');
        $translatorOne->expects(self::never())
            ->method('getDefaultLanguage');
        $translatorOne->expects(self::exactly(2))
            ->method('setCurrentLanguage')
            ->willReturnCallback(static function (string $language) use (&$setCallsOne): void {
                $setCallsOne[] = $language;
            });

        $translatorTwo->expects(self::once())
            ->method('getCurrentLanguage')
            ->willReturn('it');
        $translatorTwo->expects(self::never())
            ->method('getDefaultLanguage');
        $translatorTwo->expects(self::exactly(2))
            ->method('setCurrentLanguage')
            ->willReturnCallback(static function (string $language) use (&$setCallsTwo): void {
                $setCallsTwo[] = $language;
            });

        $runnerOne = new TranslatorContextRunner($translatorOne);
        $runnerOne->runInEnglish(static function (): void {
        });

        $runnerTwo = new TranslatorContextRunner($translatorTwo);
        $runnerTwo->runInEnglish(static function (): void {
        });

        self::assertSame(['en', 'de'], $setCallsOne);
        self::assertSame(['en', 'it'], $setCallsTwo);
    }

    public function testRunInEnglishHandlesEmptyOriginalLanguageByRestoringDefaultLanguage(): void
    {
        $translator = $this->createMock(Translator::class);
        $setCalls = [];

        $translator->expects(self::once())
            ->method('getCurrentLanguage')
            ->willReturn('');
        $translator->expects(self::once())
            ->method('getDefaultLanguage')
            ->willReturn('es');
        $translator->expects(self::exactly(2))
            ->method('setCurrentLanguage')
            ->willReturnCallback(static function (string $language) use (&$setCalls): void {
                $setCalls[] = $language;
            });

        $runner = new TranslatorContextRunner($translator);
        $runner->runInEnglish(static function (): void {
        });

        self::assertSame(['en', 'es'], $setCalls);
    }
}
