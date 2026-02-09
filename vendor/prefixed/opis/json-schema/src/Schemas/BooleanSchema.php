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
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Info\DataInfo;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Info\SchemaInfo;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Errors\ValidationError;
final class BooleanSchema extends AbstractSchema
{
    private bool $data;
    /**
     * @param SchemaInfo $info
     */
    public function __construct(SchemaInfo $info)
    {
        parent::__construct($info);
        $this->data = $info->data();
    }
    /**
     * @inheritDoc
     */
    public function validate(ValidationContext $context) : ?ValidationError
    {
        if ($this->data) {
            return null;
        }
        return new ValidationError('', $this, DataInfo::fromContext($context), 'Data not allowed');
    }
}
