<?php

declare (strict_types=1);
namespace Matomo\Dependencies\McpServer\Laminas\HttpHandlerRunner\Emitter;

use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
/** @final */
class SapiEmitter implements EmitterInterface
{
    use SapiEmitterTrait;
    /**
     * Emits a response for a PHP SAPI environment.
     *
     * Emits the status line and headers via the header() function, and the
     * body content via the output buffer.
     */
    public function emit(ResponseInterface $response) : bool
    {
        $this->assertNoPreviousOutput();
        $this->emitHeaders($response);
        $this->emitStatusLine($response);
        $this->emitBody($response);
        return \true;
    }
    /**
     * Emit the message body.
     */
    private function emitBody(ResponseInterface $response) : void
    {
        echo $response->getBody();
    }
}
