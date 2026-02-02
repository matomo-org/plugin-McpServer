<?php

namespace Matomo\Dependencies\McpServer\Http\Discovery\Strategy;

use Matomo\Dependencies\McpServer\Psr\Http\Message\RequestFactoryInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseFactoryInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestFactoryInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\StreamFactoryInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\UploadedFileFactoryInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\UriFactoryInterface;
/**
 * @internal
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 *
 * Don't miss updating src/Composer/Plugin.php when adding a new supported class.
 */
final class CommonPsr17ClassesStrategy implements DiscoveryStrategy
{
    /**
     * @var array
     */
    private static $classes = [RequestFactoryInterface::class => ['Phalcon\\Http\\Message\\RequestFactory', 'Matomo\\Dependencies\\McpServer\\Nyholm\\Psr7\\Factory\\Psr17Factory', 'GuzzleHttp\\Psr7\\HttpFactory', 'Http\\Factory\\Diactoros\\RequestFactory', 'Http\\Factory\\Guzzle\\RequestFactory', 'Http\\Factory\\Slim\\RequestFactory', 'Laminas\\Diactoros\\RequestFactory', 'Slim\\Psr7\\Factory\\RequestFactory', 'HttpSoft\\Message\\RequestFactory'], ResponseFactoryInterface::class => ['Phalcon\\Http\\Message\\ResponseFactory', 'Matomo\\Dependencies\\McpServer\\Nyholm\\Psr7\\Factory\\Psr17Factory', 'GuzzleHttp\\Psr7\\HttpFactory', 'Http\\Factory\\Diactoros\\ResponseFactory', 'Http\\Factory\\Guzzle\\ResponseFactory', 'Http\\Factory\\Slim\\ResponseFactory', 'Laminas\\Diactoros\\ResponseFactory', 'Slim\\Psr7\\Factory\\ResponseFactory', 'HttpSoft\\Message\\ResponseFactory'], ServerRequestFactoryInterface::class => ['Phalcon\\Http\\Message\\ServerRequestFactory', 'Matomo\\Dependencies\\McpServer\\Nyholm\\Psr7\\Factory\\Psr17Factory', 'GuzzleHttp\\Psr7\\HttpFactory', 'Http\\Factory\\Diactoros\\ServerRequestFactory', 'Http\\Factory\\Guzzle\\ServerRequestFactory', 'Http\\Factory\\Slim\\ServerRequestFactory', 'Laminas\\Diactoros\\ServerRequestFactory', 'Slim\\Psr7\\Factory\\ServerRequestFactory', 'HttpSoft\\Message\\ServerRequestFactory'], StreamFactoryInterface::class => ['Phalcon\\Http\\Message\\StreamFactory', 'Matomo\\Dependencies\\McpServer\\Nyholm\\Psr7\\Factory\\Psr17Factory', 'GuzzleHttp\\Psr7\\HttpFactory', 'Http\\Factory\\Diactoros\\StreamFactory', 'Http\\Factory\\Guzzle\\StreamFactory', 'Http\\Factory\\Slim\\StreamFactory', 'Laminas\\Diactoros\\StreamFactory', 'Slim\\Psr7\\Factory\\StreamFactory', 'HttpSoft\\Message\\StreamFactory'], UploadedFileFactoryInterface::class => ['Phalcon\\Http\\Message\\UploadedFileFactory', 'Matomo\\Dependencies\\McpServer\\Nyholm\\Psr7\\Factory\\Psr17Factory', 'GuzzleHttp\\Psr7\\HttpFactory', 'Http\\Factory\\Diactoros\\UploadedFileFactory', 'Http\\Factory\\Guzzle\\UploadedFileFactory', 'Http\\Factory\\Slim\\UploadedFileFactory', 'Laminas\\Diactoros\\UploadedFileFactory', 'Slim\\Psr7\\Factory\\UploadedFileFactory', 'HttpSoft\\Message\\UploadedFileFactory'], UriFactoryInterface::class => ['Phalcon\\Http\\Message\\UriFactory', 'Matomo\\Dependencies\\McpServer\\Nyholm\\Psr7\\Factory\\Psr17Factory', 'GuzzleHttp\\Psr7\\HttpFactory', 'Http\\Factory\\Diactoros\\UriFactory', 'Http\\Factory\\Guzzle\\UriFactory', 'Http\\Factory\\Slim\\UriFactory', 'Laminas\\Diactoros\\UriFactory', 'Slim\\Psr7\\Factory\\UriFactory', 'HttpSoft\\Message\\UriFactory']];
    public static function getCandidates($type)
    {
        $candidates = [];
        if (isset(self::$classes[$type])) {
            foreach (self::$classes[$type] as $class) {
                $candidates[] = ['class' => $class, 'condition' => [$class]];
            }
        }
        return $candidates;
    }
}
