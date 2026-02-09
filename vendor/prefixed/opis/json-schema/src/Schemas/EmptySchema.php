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
namespace Matomo\Dependencies\McpServer\Opis\JsonSchema\Schemas;

use Matomo\Dependencies\McpServer\Opis\JsonSchema\ValidationContext;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Errors\ValidationError;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Info\SchemaInfo;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\KeywordValidator;
final class EmptySchema extends AbstractSchema
{
    protected ?KeywordValidator $keywordValidator;
    /**
     * @inheritDoc
     */
    public function __construct(SchemaInfo $info, ?KeywordValidator $keywordValidator = null)
    {
        parent::__construct($info);
        $this->keywordValidator = $keywordValidator;
    }
    /**
     * @inheritDoc
     */
    public function validate(ValidationContext $context) : ?ValidationError
    {
        if (!$this->keywordValidator) {
            return null;
        }
        $context->pushSharedObject($this);
        $error = $this->keywordValidator->validate($context);
        $context->popSharedObject();
        return $error;
    }
}
