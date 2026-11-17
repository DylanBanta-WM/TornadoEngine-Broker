<?php

namespace TornadoEngine\Broker\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Broker\BrokerException;
use TornadoEngine\Gam\Gam;

#[Name('gam')]
#[Description('Runs a GAM command for the district\'s Google Workspace, through the broker. Give the arguments after "gam" as a list, for example ["info", "user", "s-0123456789@student"] or ["print", "users", "query", "orgUnitPath=\'/Students\'"]. People in the output are replaced with pseudonyms.')]
class GamTool extends Tool
{
    public function __construct(private Broker $broker) {}

    public function handle(Request $request): Response
    {
        if ($reason = $this->broker->notReady()) {
            return Response::error($reason);
        }
        if (! class_exists(Gam::class)) {
            return Response::error('GAM isn\'t installed on this Tornado Engine.');
        }

        $args = $request->array('args');
        if (! $args || ! array_is_list($args) || array_filter($args, fn ($arg) => ! is_string($arg))) {
            return Response::error('args must be a list of strings, for example ["info", "domain"].');
        }

        try {
            $real = $this->broker->reveal($args);
        } catch (BrokerException $e) {
            return Response::error($e->getMessage());
        }

        try {
            $result = app(Gam::class)->run($real);
        } catch (Throwable $e) {
            $this->broker->log('gam', $real, null, false);
            report($e);

            return Response::error($this->broker->filter($e->getMessage()));
        }

        $this->broker->log('gam', $real, $result['exit'], $result['exit'] === 0);

        $text = $this->broker->filter($result['stdout']);
        if (trim($result['stderr']) !== '') {
            $text .= "\n--- GAM messages ---\n".$this->broker->filter($result['stderr']);
        }
        $text .= "\n--- exit code: {$result['exit']} ---";

        return $result['exit'] === 0 ? Response::text($text) : Response::error($text);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'args' => $schema->array()
                ->items($schema->string())
                ->description('The GAM arguments, without the word "gam".')
                ->required(),
        ];
    }
}
