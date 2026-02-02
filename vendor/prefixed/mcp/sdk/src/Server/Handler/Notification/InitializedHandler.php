<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Matomo\Dependencies\McpServer\Mcp\Server\Handler\Notification;

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Notification;
use Matomo\Dependencies\McpServer\Mcp\Schema\Notification\InitializedNotification;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InitializedHandler implements NotificationHandlerInterface
{
    public function supports(Notification $notification) : bool
    {
        return $notification instanceof InitializedNotification;
    }
    public function handle(Notification $notification, SessionInterface $session) : void
    {
        \assert($notification instanceof InitializedNotification);
        $session->set('initialized', \true);
    }
}
