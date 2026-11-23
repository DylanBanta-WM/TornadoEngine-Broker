<?php

namespace TornadoEngine\Broker\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use TornadoEngine\Broker\Mcp\Tools\BrokerInfoTool;
use TornadoEngine\Broker\Mcp\Tools\GamTool;

#[Name('Tornado Engine broker')]
#[Version('0.1.0')]
#[Instructions('Tornado Engine privacy broker. Everyone in the district\'s directories reaches you only as a pseudonym: p-<10 hex>@<role>, for example p-0123456789@student (or @person for someone with no role). Their names appear as [name p-…], [given p-…] and [family p-…], ID numbers and other identifying values as [id p-…-…] or [id v-…], and phone numbers as [phone v-…]. People see the real values in place of every placeholder, so you can repeat placeholders in your answers to them. Use a pseudonym anywhere a real address would go (gam info user p-0123456789@student): the broker swaps the real address back in before GAM runs. Never pass placeholders like [name p-…] as values, and never try to work out who a pseudonym is. If a person needs to know, they look it up in Tornado Engine themselves. If you see what looks like a real person\'s name, email or ID that wasn\'t replaced with a placeholder, tell the user it came through unprotected before you continue.')]
class BrokerServer extends Server
{
    protected array $tools = [
        GamTool::class,
        BrokerInfoTool::class,
    ];
}
