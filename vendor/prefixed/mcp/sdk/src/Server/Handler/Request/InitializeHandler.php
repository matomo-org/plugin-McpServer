<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request;

use Matomo\Dependencies\McpServer\Mcp\Schema\Enum\ProtocolVersion;
use Matomo\Dependencies\McpServer\Mcp\Schema\Implementation;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\InitializeRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\InitializeResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\ServerCapabilities;
use Matomo\Dependencies\McpServer\Mcp\Server\Configuration;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
/**
 * @implements RequestHandlerInterface<InitializeResult>
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InitializeHandler implements RequestHandlerInterface
{
    public function __construct(public readonly ?Configuration $configuration = null)
    {
    }
    public function supports(Request $request) : bool
    {
        return $request instanceof InitializeRequest;
    }
    /**
     * @return Response<InitializeResult>
     */
    public function handle(Request $request, SessionInterface $session) : Response
    {
        \assert($request instanceof InitializeRequest);
        $session->set('client_info', $request->clientInfo->jsonSerialize());
        $session->set('client_capabilities', $request->capabilities->jsonSerialize());
        $negotiated = $this->negotiate($request->protocolVersion);
        $session->set('protocol_version', $negotiated->value);
        return new Response($request->getId(), new InitializeResult($this->configuration->capabilities ?? new ServerCapabilities(), $this->configuration->serverInfo ?? new Implementation(), $this->configuration?->instructions, null, $negotiated));
    }
    /**
     * Picks the protocol version to answer an `initialize` handshake with.
     *
     * If the client asked for a version this server supports, the spec requires
     * responding with that exact version. Otherwise the server counter-offers
     * the newest version it does support and leaves it to the client to decide
     * whether it can continue on that revision or must disconnect.
     */
    private function negotiate(string $requested) : ProtocolVersion
    {
        $supported = $this->supportedVersions();
        $version = ProtocolVersion::tryFrom($requested);
        if (null !== $version && \in_array($version, $supported, \true)) {
            return $version;
        }
        return $supported[\count($supported) - 1];
    }
    /**
     * Versions this server is willing to negotiate over `initialize`.
     *
     * A version configured on the server pins the handshake to exactly that
     * revision. Modern revisions are never offered here: they have no
     * `initialize` at all, so a client that reached this handler cannot speak
     * one, and answering with it would leave the connection unusable.
     *
     * @return non-empty-list<ProtocolVersion>
     */
    private function supportedVersions() : array
    {
        $configured = $this->configuration?->protocolVersion;
        if (null !== $configured && !$configured->isModern()) {
            return [$configured];
        }
        return ProtocolVersion::handshakeVersions();
    }
}
