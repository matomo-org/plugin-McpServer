<?php

namespace Matomo\Dependencies\McpServer\Http\Discovery;

use Matomo\Dependencies\McpServer\Psr\Http\Client\ClientInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\RequestFactoryInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\RequestInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseFactoryInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestFactoryInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\StreamFactoryInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\UploadedFileFactoryInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\UriFactoryInterface;
/**
 * A generic PSR-18 and PSR-17 implementation.
 *
 * You can create this class with concrete client and factory instances
 * or let it use discovery to find suitable implementations as needed.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Psr18Client extends Psr17Factory implements ClientInterface
{
    private $client;
    public function __construct(?ClientInterface $client = null, ?RequestFactoryInterface $requestFactory = null, ?ResponseFactoryInterface $responseFactory = null, ?ServerRequestFactoryInterface $serverRequestFactory = null, ?StreamFactoryInterface $streamFactory = null, ?UploadedFileFactoryInterface $uploadedFileFactory = null, ?UriFactoryInterface $uriFactory = null)
    {
        $requestFactory ?? ($requestFactory = $client instanceof RequestFactoryInterface ? $client : null);
        $responseFactory ?? ($responseFactory = $client instanceof ResponseFactoryInterface ? $client : null);
        $serverRequestFactory ?? ($serverRequestFactory = $client instanceof ServerRequestFactoryInterface ? $client : null);
        $streamFactory ?? ($streamFactory = $client instanceof StreamFactoryInterface ? $client : null);
        $uploadedFileFactory ?? ($uploadedFileFactory = $client instanceof UploadedFileFactoryInterface ? $client : null);
        $uriFactory ?? ($uriFactory = $client instanceof UriFactoryInterface ? $client : null);
        parent::__construct($requestFactory, $responseFactory, $serverRequestFactory, $streamFactory, $uploadedFileFactory, $uriFactory);
        $this->client = $client ?? Psr18ClientDiscovery::find();
    }
    public function sendRequest(RequestInterface $request) : ResponseInterface
    {
        return $this->client->sendRequest($request);
    }
}
