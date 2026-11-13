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
#[Instructions('Tornado Engine privacy broker. People never reach you by name: students appear as s-<10 hex>@student, staff as t-<10 hex>@staff, and anyone else (often parents) as c-<10 hex>@contact. Names appear as [name s-…], [given s-…] and [family s-…], ID numbers and other identifying values as [id s-…], and values the broker could not place as [id], [phone] or [number]. Use a pseudonym anywhere a real address would go (gam info user s-0123456789@student): the broker swaps the real address back in before GAM runs. Never pass placeholders like [name s-…] as values, and never try to work out who a pseudonym is. If a person needs to know, they look it up in Tornado Engine themselves.')]
class BrokerServer extends Server
{
    protected array $tools = [
        GamTool::class,
        BrokerInfoTool::class,
    ];
}
