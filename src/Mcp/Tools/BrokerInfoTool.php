<?php

namespace TornadoEngine\Broker\Mcp\Tools;

use App\Models\Setting;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Broker\Models\Person;

#[Name('broker_info')]
#[Description('Whether the broker is ready, how many people its directory holds, and how pseudonyms look.')]
class BrokerInfoTool extends Tool
{
    public function __construct(private Broker $broker) {}

    public function handle(Request $request): Response
    {
        $counts = Person::query()->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');

        return Response::text(implode("\n", [
            'Ready: '.($this->broker->notReady() ?? 'yes'),
            'Directory refreshed: '.(Setting::get('broker.refreshed_at') ?? 'never'),
            'Students: '.($counts['student'] ?? 0).', staff: '.($counts['staff'] ?? 0).', contacts: '.($counts['contact'] ?? 0),
            'Pseudonyms: s-<10 hex>@student, t-<10 hex>@staff, c-<10 hex>@contact.',
        ]));
    }
}
