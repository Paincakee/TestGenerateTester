<?php

namespace RichardVullings\BlueprintKit;

use Illuminate\Support\ServiceProvider;
use RichardVullings\BlueprintKit\Commands\BlueprintDeltaCommand;
use RichardVullings\BlueprintKit\Commands\BlueprintRoutesSyncCommand;
use RichardVullings\BlueprintKit\Commands\BlueprintSmartCommand;
use RichardVullings\BlueprintKit\Commands\BlueprintSnapshotCommand;

class BlueprintKitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/blueprint.php' => config_path('blueprint.php'),
        ], 'blueprint-kit-config');

        $this->publishes([
            __DIR__ . '/../stubs/blueprint' => base_path('stubs/blueprint'),
        ], 'blueprint-kit-stubs');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BlueprintDeltaCommand::class,
                BlueprintSnapshotCommand::class,
                BlueprintSmartCommand::class,
                BlueprintRoutesSyncCommand::class,
            ]);
        }
    }
}
