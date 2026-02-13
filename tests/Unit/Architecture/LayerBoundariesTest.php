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
    private const CONTRACTS_NAMESPACE = self::PLUGIN_NAMESPACE . 'Contracts\\';
    private const SERVICES_NAMESPACE = self::PLUGIN_NAMESPACE . 'Services\\';
    private const SUPPORT_TOOLING_NAMESPACE = self::PLUGIN_NAMESPACE . 'Support\\Tooling\\';
    private const STATIC_CONTAINER_NAMESPACE = 'Piwik\\Container\\StaticContainer';

    /**
     * @var list<string>
     */
    private const MCPTOOLS_NEW_CLASS_ALLOW_PREFIXES = [self::SUPPORT_TOOLING_NAMESPACE];

    /**
     * @var list<string>
     */
    private const MCPTOOLS_NEW_CLASS_ALLOW_EXACT = [
        'Matomo\\Dependencies\\McpServer\\Mcp\\Schema\\ToolAnnotations',
        'Matomo\\Dependencies\\McpServer\\Mcp\\Exception\\ToolCallException',
    ];

    public function testServicesDoNotDependOnApiWrappers(): void
    {
        $violations = [];
        foreach ($this->listPhpFiles('Services') as $relativePath => $absolutePath) {
            $contents = file_get_contents($absolutePath);
            self::assertNotFalse($contents);

            if (strpos($contents, self::API_WRAPPERS_NAMESPACE) === false) {
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

    public function testServicesAndMcpToolsOnlyReferenceContractsPortsAndRecordsNamespaces(): void
    {
        $violations = array_merge(
            $this->findContractNamespaceViolations('Services'),
            $this->findContractNamespaceViolations('McpTools')
        );

        self::assertSame(
            [],
            $violations,
            "Legacy/invalid Contracts namespace references:\n" . implode("\n", $violations)
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

                if (str_starts_with($resolved, self::SERVICES_NAMESPACE)) {
                    $violations[] = $relativePath . ' -> ' . $resolved;
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

    public function testNoNewNullableServiceConstructorDependenciesInRuntimeLayers(): void
    {
        $violations = [];

        foreach (['McpTools', 'Services', 'Support'] as $directory) {
            foreach ($this->listPhpFiles($directory) as $relativePath => $absolutePath) {
                $contents = file_get_contents($absolutePath);
                self::assertNotFalse($contents);

                foreach ($this->parseConstructorParameters($contents) as $parameter) {
                    if (!$parameter['nullable']) {
                        continue;
                    }

                    if ($parameter['resolvedType'] === null || $this->isBuiltinType($parameter['resolvedType'])) {
                        continue;
                    }

                    $entry = $relativePath . ' -> ' . $parameter['resolvedType'];
                    $violations[] = $entry;
                }
            }
        }

        sort($violations);

        self::assertSame(
            [],
            $violations,
            "New nullable service constructor dependencies detected:\n" . implode("\n", $violations)
        );
    }

    public function testNoNewFallbackServiceInstantiationPatternsInRuntimeLayers(): void
    {
        $violations = [];

        foreach (['McpTools', 'Services', 'Support'] as $directory) {
            foreach ($this->listPhpFiles($directory) as $relativePath => $absolutePath) {
                $contents = file_get_contents($absolutePath);
                self::assertNotFalse($contents);

                $imports = $this->parseImports($contents);
                $namespace = $this->parseNamespace($contents);
                $resolvedClasses = $this->parseFallbackInstantiationClassNames($contents, $imports, $namespace);

                foreach ($resolvedClasses as $resolvedClass) {
                    $entry = $relativePath . ' -> ' . $resolvedClass;
                    $violations[] = $entry;
                }
            }
        }

        sort($violations);

        self::assertSame(
            [],
            $violations,
            "New fallback instantiation patterns detected:\n" . implode("\n", $violations)
        );
    }

    public function testRuntimeLayersDoNotUseServiceLocator(): void
    {
        $violations = [];

        foreach (['McpTools', 'Services'] as $directory) {
            foreach ($this->listPhpFiles($directory) as $relativePath => $absolutePath) {
                $contents = file_get_contents($absolutePath);
                self::assertNotFalse($contents);

                foreach ($this->findServiceLocatorUsages($contents) as $pattern) {
                    $violations[] = $relativePath . ' -> ' . $pattern;
                }
            }
        }

        sort($violations);

        self::assertSame(
            [],
            $violations,
            "Runtime layer service-locator usage detected:\n" . implode("\n", $violations)
        );
    }

    public function testRuntimeLayersDoNotInstantiatePluginServiceCollaborators(): void
    {
        $violations = [];

        foreach (['McpTools', 'Services'] as $directory) {
            foreach ($this->listPhpFiles($directory) as $relativePath => $absolutePath) {
                $contents = file_get_contents($absolutePath);
                self::assertNotFalse($contents);

                $imports = $this->parseImports($contents);
                $namespace = $this->parseNamespace($contents);
                foreach ($this->parseNewClassNames($contents) as $className) {
                    $resolved = $this->resolveClassName($className, $imports, $namespace);
                    if ($resolved === null || !str_starts_with($resolved, self::SERVICES_NAMESPACE)) {
                        continue;
                    }

                    $violations[] = $relativePath . ' -> ' . $resolved;
                }
            }
        }

        sort($violations);

        self::assertSame(
            [],
            $violations,
            "Runtime layer direct service collaborator instantiation detected:\n" . implode("\n", $violations)
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
    private function resolveClassName(string $className, array $imports, ?string $namespace = null): ?string
    {
        if (str_starts_with($className, '\\')) {
            return ltrim($className, '\\');
        }

        if (str_contains($className, '\\')) {
            if ($namespace === null) {
                return ltrim($className, '\\');
            }

            return $namespace . '\\' . ltrim($className, '\\');
        }

        if (isset($imports[$className])) {
            return $imports[$className];
        }

        if ($namespace !== null) {
            return $namespace . '\\' . $className;
        }

        return null;
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

    /**
     * @return list<string>
     */
    private function findContractNamespaceViolations(string $relativeDirectory): array
    {
        $violations = [];

        foreach ($this->listPhpFiles($relativeDirectory) as $relativePath => $absolutePath) {
            $contents = file_get_contents($absolutePath);
            self::assertNotFalse($contents);

            if (strpos($contents, self::CONTRACTS_NAMESPACE) === false) {
                continue;
            }

            $unknownContractsNamespacePattern = '/'
                . preg_quote(self::CONTRACTS_NAMESPACE, '/')
                . '(?!Ports\\\\|Records\\\\)/';

            if (preg_match_all($unknownContractsNamespacePattern, $contents) > 0) {
                $violations[] = $relativePath . ' -> unknown Contracts namespace';
            }
        }

        return $violations;
    }

    private function parseNamespace(string $contents): ?string
    {
        if (preg_match('/\bnamespace\s+([^;]+);/', $contents, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * @return list<string>
     */
    private function findServiceLocatorUsages(string $contents): array
    {
        $patterns = [];

        if (str_contains($contents, 'StaticContainer::get(')) {
            $patterns[] = 'StaticContainer::get';
        }

        if (str_contains($contents, 'StaticContainer::getContainer(')) {
            $patterns[] = 'StaticContainer::getContainer';
        }

        if (str_contains($contents, self::STATIC_CONTAINER_NAMESPACE)) {
            $patterns[] = self::STATIC_CONTAINER_NAMESPACE;
        }

        return $patterns;
    }

    /**
     * @return list<array{nullable: bool, resolvedType: string|null}>
     */
    private function parseConstructorParameters(string $contents): array
    {
        if (preg_match('/function\s+__construct\s*\((.*?)\)\s*[{]/s', $contents, $matches) !== 1) {
            return [];
        }

        $parameterList = trim($matches[1]);
        if ($parameterList === '') {
            return [];
        }

        $imports = $this->parseImports($contents);
        $namespace = $this->parseNamespace($contents);
        $chunks = preg_split('/,(?![^()]*\))/', $parameterList);
        if (!is_array($chunks)) {
            return [];
        }

        $parameters = [];
        foreach ($chunks as $chunk) {
            $parameter = trim($chunk);
            if ($parameter === '') {
                continue;
            }

            $parameter = preg_replace('/\s+/', ' ', $parameter);
            if (!is_string($parameter)) {
                continue;
            }

            $parameter = preg_replace('/^(public|protected|private)\s+/', '', $parameter);
            if (!is_string($parameter)) {
                continue;
            }

            $parameter = preg_replace('/^readonly\s+/', '', $parameter);
            if (!is_string($parameter)) {
                continue;
            }

            if (preg_match('/^([^\$]+)\s+\$[A-Za-z_][A-Za-z0-9_]*/', $parameter, $typeMatches) !== 1) {
                $parameters[] = ['nullable' => false, 'resolvedType' => null];
                continue;
            }

            $typeDeclaration = trim($typeMatches[1]);
            $nullable = str_contains($typeDeclaration, '?') || str_contains(strtolower($typeDeclaration), '|null');
            $resolved = $this->resolveDeclaredType($typeDeclaration, $imports, $namespace);

            $parameters[] = ['nullable' => $nullable, 'resolvedType' => $resolved];
        }

        return $parameters;
    }

    /**
     * @param array<string, string> $imports
     * @return list<string>
     */
    private function parseFallbackInstantiationClassNames(string $contents, array $imports, ?string $namespace): array
    {
        if (preg_match_all('/\?\?=\s*new\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*\(/', $contents, $matches) < 1) {
            return [];
        }

        $resolved = [];
        foreach ($matches[1] as $className) {
            $resolvedClass = $this->resolveClassName($className, $imports, $namespace);
            if ($resolvedClass !== null) {
                $resolved[] = $resolvedClass;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $imports
     */
    private function resolveDeclaredType(string $typeDeclaration, array $imports, ?string $namespace): ?string
    {
        $typeDeclaration = trim($typeDeclaration);
        if ($typeDeclaration === '') {
            return null;
        }

        $candidates = explode('|', str_replace('?', '', $typeDeclaration));
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || strtolower($candidate) === 'null') {
                continue;
            }

            if ($this->isBuiltinType($candidate)) {
                return $candidate;
            }

            return $this->resolveClassName($candidate, $imports, $namespace);
        }

        return null;
    }

    private function isBuiltinType(string $type): bool
    {
        $normalized = strtolower(trim($type, '\\'));
        return in_array(
            $normalized,
            ['int', 'float', 'string', 'bool', 'array', 'object', 'mixed', 'callable', 'iterable', 'void', 'never'],
            true
        );
    }
}
