<?php

use App\Http\Middleware\AuthenticateHarness;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use TornadoEngine\Broker\Http\BrokerController;
use TornadoEngine\Broker\Http\OnlyFromHarness;
use TornadoEngine\Broker\Mcp\BrokerServer;

// Claude's tools, for the harness only.
Mcp::web('/mcp/broker', BrokerServer::class)->middleware([OnlyFromHarness::class, AuthenticateHarness::class]);

Route::middleware(['web', 'auth', 'can:administration'])
    ->prefix('administration/broker')
    ->name('broker.')
    ->group(function () {
        Route::post('/roles', [BrokerController::class, 'roles'])->name('roles');
        Route::post('/refresh', [BrokerController::class, 'refresh'])->name('refresh');
        Route::get('/log', [BrokerController::class, 'log'])->name('log');
    });
