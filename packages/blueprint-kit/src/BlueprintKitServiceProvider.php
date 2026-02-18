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
