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

use Matomo\Dependencies\McpServer\Opis\JsonSchema\Keyword;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Info\SchemaInfo;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Keywords\AnyOfKeyword;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Parsers\KeywordParser;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Parsers\SchemaParser;
class AnyOfKeywordParser extends KeywordParser
{
    /**
     * @inheritDoc
     */
    public function type() : string
    {
        return self::TYPE_AFTER;
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
        if (!is_array($value)) {
            throw $this->keywordException("{keyword} should be an array of json schemas", $info);
        }
        if (!$value) {
            throw $this->keywordException("{keyword} must have at least one element", $info);
        }
        $alwaysValid = \false;
        foreach ($value as $index => $item) {
            if ($item === \true) {
                $alwaysValid = \true;
                continue;
            }
            if ($item === \false) {
                continue;
            }
            if (!is_object($item)) {
                throw $this->keywordException("{keyword}[{$index}] must be a json schema", $info);
            } elseif (!count(get_object_vars($item))) {
                $alwaysValid = \true;
            }
        }
        return new AnyOfKeyword($value, $alwaysValid);
    }
}
