<?php

/* ============================================================================
 * Copyright 2020 Zindex Software
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * ============================================================================ */
namespace Matomo\Dependencies\McpServer\Opis\JsonSchema\Parsers\Keywords;

use Matomo\Dependencies\McpServer\Opis\JsonSchema\Info\SchemaInfo;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Keyword;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Helper;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Keywords\ConstDataKeyword;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Keywords\ConstKeyword;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Parsers\KeywordParser;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Parsers\DataKeywordTrait;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Parsers\SchemaParser;
class ConstKeywordParser extends KeywordParser
{
    use DataKeywordTrait;
    /**
     * @inheritDoc
     */
    public function type() : string
    {
        return self::TYPE_BEFORE;
    }
    /**
     * @inheritDoc
     */
    public function parse(SchemaInfo $info, SchemaParser $parser, object $shared) : ?Keyword
    {
        $schema = $info->data();
        if (!$this->keywordExists($schema)) {
            return null;
        }
        $value = $this->keywordValue($schema);
        if ($this->isDataKeywordAllowed($parser, $this->keyword)) {
            if ($pointer = $this->getDataKeywordPointer($value)) {
                return new ConstDataKeyword($pointer);
            }
        }
        $type = Helper::getJsonType($value);
        if ($type === null) {
            throw $this->keywordException("{keyword} contains unknown json data type", $info);
        }
        if (isset($shared->types)) {
            if (!Helper::jsonTypeMatches($type, $shared->types)) {
                throw $this->keywordException("{keyword} contains a value that doesn't match the type keyword", $info);
            }
        } else {
            $shared->types = [$type];
        }
        return new ConstKeyword($value);
    }
}
