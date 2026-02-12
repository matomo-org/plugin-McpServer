<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class LayerBoundariesTest extends TestCase
{
    private const PLUGIN_NAMESPACE = 'Piwik\\Plugins\\McpServer\\';
    private const API_WRAPPERS_NAMESPACE = self::PLUGIN_NAMESPACE . 'ApiWrappers\\';

    /**
     * Keep this list explicit so additional exceptions fail fast.
     *
     * @var list<string>
     */
    private const SERVICE_TO_WRAPPER_EXCEPTION_FILES = [
        'Services/Reports/ReportProcessedQueryService.php',
    ];

    /**
     * @var list<string>
     */
    private const MCPTOOLS_NEW_CLASS_ALLOW_PREFIXES = [
        self::API_WRAPPERS_NAMESPACE,
        self::PLUGIN_NAMESPACE . 'Support\\Tooling\\',
    ];

    /**
     * @var list<string>
     */
    private const MCPTOOLS_NEW_CLASS_ALLOW_EXACT = [
        'Matomo\\Dependencies\\McpServer\\Mcp\\Schema\\ToolAnnotations',
        'Matomo\\Dependencies\\McpServer\\Mcp\\Exception\\ToolCallException',
    ];

    public function testServicesDoNotDependOnApiWrappersExceptAllowlistedException(): void
    {
        $violations = [];
        foreach ($this->listPhpFiles('Services') as $relativePath => $absolutePath) {
            $contents = file_get_contents($absolutePath);
            self::assertNotFalse($contents);

            if (strpos($contents, self::API_WRAPPERS_NAMESPACE) === false) {
                continue;
            }

            if (in_array($relativePath, self::SERVICE_TO_WRAPPER_EXCEPTION_FILES, true)) {
                continue;
            }

            $violations[] = $relativePath;
        }

        self::assertSame(
            [],
            $violations,
            "Service -> ApiWrappers dependency violations:\n" . implode("\n", $violations)
        );
    }

    public function testServiceWrapperExceptionRegistryContainsOnlyKnownPhaseOneException(): void
    {
        self::assertSame(
            ['Services/Reports/ReportProcessedQueryService.php'],
            self::SERVICE_TO_WRAPPER_EXCEPTION_FILES,
            'Phase 1 exception registry changed. Additions are not allowed in this phase.'
        );
    }

    public function testMcpToolsOnlyInstantiateApprovedClasses(): void
    {
        $violations = [];

        foreach ($this->listPhpFiles('McpTools') as $relativePath => $absolutePath) {
            $contents = file_get_contents($absolutePath);
            self::assertNotFalse($contents);

            $imports = $this->parseImports($contents);
            foreach ($this->parseNewClassNames($contents) as $className) {
                $resolved = $this->resolveClassName($className, $imports);

                if ($resolved === null) {
                    $violations[] = $relativePath . ' -> unresolved: ' . $className;
                    continue;
                }

                if ($this->isApprovedMcpToolNewTarget($resolved)) {
                    continue;
                }

                $violations[] = $relativePath . ' -> ' . $resolved;
            }
        }

        self::assertSame(
            [],
            $violations,
            "McpTools instantiate forbidden concrete classes:\n" . implode("\n", $violations)
        );
    }

    /**
     * @return array<string, string>
     */
    private function listPhpFiles(string $relativeDirectory): array
    {
        $directory = __DIR__ . '/../../../' . $relativeDirectory;
        self::assertDirectoryExists($directory);

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $absolutePath = $fileInfo->getPathname();
            if (!str_ends_with($absolutePath, '.php')) {
                continue;
            }

            $relativePath = str_replace(
                __DIR__ . '/../../../',
                '',
                str_replace('\\', '/', $absolutePath)
            );
            $files[$relativePath] = $absolutePath;
        }

        ksort($files);

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function parseImports(string $contents): array
    {
        $tokens = token_get_all($contents);
        $imports = [];

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            $name = '';
            $alias = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_string($next) && ($next === ';' || $next === ',')) {
                    break;
                }

                if (!is_array($next)) {
                    continue;
                }

                if (in_array($next[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $name .= $next[1];
                    continue;
                }

                if ($next[0] === T_AS) {
                    $aliasName = '';
                    for ($k = $j + 1; $k < $count; $k++) {
                        $aliasToken = $tokens[$k];
                        if (is_string($aliasToken) && ($aliasToken === ';' || $aliasToken === ',')) {
                            break;
                        }

                        if (is_array($aliasToken) && $aliasToken[0] === T_STRING) {
                            $aliasName .= $aliasToken[1];
                        }
                    }
                    $alias = $aliasName;
                }
            }

            $normalized = ltrim($name, '\\');
            if ($normalized === '') {
                continue;
            }

            $shortName = $alias ?? basename(str_replace('\\', '/', $normalized));
            $imports[$shortName] = $normalized;
        }

        return $imports;
    }

    /**
     * @return list<string>
     */
    private function parseNewClassNames(string $contents): array
    {
        $tokens = token_get_all($contents);
        $count = count($tokens);
        $classNames = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            $name = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if (is_array($next) && $next[0] === T_CLASS) {
                    // Anonymous class.
                    $name = '';
                    break;
                }

                if (
                    is_array($next) && in_array(
                        $next[0],
                        [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED],
                        true
                    )
                ) {
                    $name .= $next[1];
                    continue;
                }

                break;
            }

            $name = trim($name);
            if ($name !== '') {
                $classNames[] = $name;
            }
        }

        return $classNames;
    }

    /**
     * @param array<string, string> $imports
     */
    private function resolveClassName(string $className, array $imports): ?string
    {
        if (str_starts_with($className, '\\')) {
            return ltrim($className, '\\');
        }

        if (str_contains($className, '\\')) {
            return ltrim($className, '\\');
        }

        return $imports[$className] ?? null;
    }

    private function isApprovedMcpToolNewTarget(string $className): bool
    {
        if (in_array($className, self::MCPTOOLS_NEW_CLASS_ALLOW_EXACT, true)) {
            return true;
        }

        foreach (self::MCPTOOLS_NEW_CLASS_ALLOW_PREFIXES as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
