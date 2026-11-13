<?php

namespace TornadoEngine\Broker\Http;

use App\Harness\HarnessView;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The broker's MCP server answers only through nginx-harness.
 */
class OnlyFromHarness
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(HarnessView::requested($request), 404);

        return $next($request);
    }
}
