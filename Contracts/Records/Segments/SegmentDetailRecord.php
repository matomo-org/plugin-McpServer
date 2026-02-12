<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Records\Segments;

/**
 * @phpstan-type SegmentDetailArray array{
 *     idsegment: int,
 *     name: string,
 *     definition: string,
 *     idsite: int|null,
 *     auto_archive: bool,
 *     enabled_all_users: bool,
 *     login: string,
 * }
 */
final class SegmentDetailRecord
{
    public function __construct(
        public readonly int $idSegment,
        public readonly string $name,
        public readonly string $definition,
        public readonly ?int $idSite,
        public readonly bool $autoArchive,
        public readonly bool $enabledAllUsers,
        public readonly string $login
    ) {
    }

    /**
     * @return SegmentDetailArray
     */
    public function toArray(): array
    {
        return [
            'idsegment' => $this->idSegment,
            'name' => $this->name,
            'definition' => $this->definition,
            'idsite' => $this->idSite,
            'auto_archive' => $this->autoArchive,
            'enabled_all_users' => $this->enabledAllUsers,
            'login' => $this->login,
        ];
    }
}
