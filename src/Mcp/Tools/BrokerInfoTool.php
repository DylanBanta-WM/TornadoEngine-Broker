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
#[Description('Whether the broker is ready, how many people are given pseudonyms (by role), and how pseudonyms look.')]
class BrokerInfoTool extends Tool
{
    public function __construct(private Broker $broker) {}

    public function handle(Request $request): Response
    {
        $counts = Person::query()->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');

        return Response::text(implode("\n", [
            'Ready: '.($this->broker->notReady() ?? 'yes'),
            'Directory refreshed: '.(Setting::get('broker.refreshed_at') ?? 'never'),
            'People given pseudonyms: '.($counts->isEmpty() ? 'none' : $counts->map(fn ($total, $kind) => "{$total} @{$kind}")->implode(', ')),
            'Pseudonyms look like p-<10 hex>@<role>.',
        ]));
    }
}
