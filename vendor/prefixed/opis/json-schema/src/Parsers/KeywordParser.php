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
namespace Matomo\Dependencies\McpServer\Opis\JsonSchema\Parsers;

use Matomo\Dependencies\McpServer\Opis\JsonSchema\Info\SchemaInfo;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Keyword;
abstract class KeywordParser
{
    const TYPE_PREPEND = '_prepend';
    const TYPE_BEFORE = '_before';
    const TYPE_AFTER = '_after';
    const TYPE_APPEND = '_append';
    const TYPE_AFTER_REF = '_after_ref';
    const TYPE_STRING = 'string';
    const TYPE_NUMBER = 'number';
    const TYPE_ARRAY = 'array';
    const TYPE_OBJECT = 'object';
    use KeywordParserTrait;
    /**
     * The keyword type, can be one of the TYPE_* const
     * @return string
     */
    public abstract function type() : string;
    /**
     * @param SchemaInfo $info
     * @param SchemaParser $parser
     * @param object $shared
     * @return Keyword|null
     */
    public abstract function parse(SchemaInfo $info, SchemaParser $parser, object $shared) : ?Keyword;
    /**
     * @param SchemaInfo $info
     * @return bool
     */
    protected function trackEvaluated(SchemaInfo $info) : bool
    {
        $draft = $info->draft();
        return $draft !== '06' && $draft !== '07';
    }
}
