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
#[Instructions('Tornado Engine privacy broker. People in some roles reach you only as pseudonyms: p-<10 hex>@<role>, for example p-0123456789@student. Their names appear as [name p-…], [given p-…] and [family p-…], ID numbers and other identifying values as [id p-…], and phone numbers as [phone]. Use a pseudonym anywhere a real address would go (gam info user p-0123456789@student): the broker swaps the real address back in before GAM runs. Never pass placeholders like [name p-…] as values, and never try to work out who a pseudonym is. If a person needs to know, they look it up in Tornado Engine themselves.')]
class BrokerServer extends Server
{
    protected array $tools = [
        GamTool::class,
        BrokerInfoTool::class,
    ];
}
