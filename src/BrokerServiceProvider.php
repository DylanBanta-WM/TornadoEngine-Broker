<?php

namespace TornadoEngine\Broker;

use App\Administration\Administration;
use App\Harness\HarnessMcp;
use App\Harness\HarnessView;
use Illuminate\Support\ServiceProvider;
use TornadoEngine\Cron\Cron;

class BrokerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Filter::class);
        $this->app->singleton(Broker::class);
    }

    public function boot(Broker $broker, Administration $administration, HarnessView $view, HarnessMcp $mcp): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'broker');
        $this->loadRoutesFrom(__DIR__.'/../routes/broker.php');

        $administration->tab('broker', __('Broker'), order: 40);
        $administration->card('broker::administration-card', tab: 'broker');

        // Pages Claude opens through the harness show pseudonyms, and only once the broker is set up.
        $view->filter(fn (string $text) => $broker->filter($text), ready: fn () => $broker->ready());

        // Claude's tools: GAM (when it's installed) through the broker.
        $mcp->server('tornado-broker', '/mcp/broker');

        $this->callAfterResolving(Cron::class, function (Cron $cron) {
            $cron->job('broker.refresh', __('Refresh the broker\'s directory'), function () {
                $summary = app(Directory::class)->refresh();
                app(Filter::class)->forget();

                return $summary;
            }, schedule: '0 3 * * *', description: __('Updates who is given a pseudonym (by role) and what identifies them.'));
        });
    }
}
