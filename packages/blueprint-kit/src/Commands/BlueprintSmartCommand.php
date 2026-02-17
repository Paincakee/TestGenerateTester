<?php

namespace RichardVullings\BlueprintKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RichardVullings\BlueprintKit\Support\BlueprintWorkflow;

class BlueprintSmartCommand extends Command
{
    protected $signature = 'bp:smart {draft : Path to Blueprint draft yaml} {--full : Include controller and test generation}';

    protected $description = 'Run delta migration generation first, then execute the appropriate safe Blueprint build';

    public function handle(): int
    {
        $draft = (string) $this->argument('draft');
        $full = (bool) $this->option('full');
        $draftPath = BlueprintWorkflow::resolveDraftPath($draft);

        if (! File::exists($draftPath)) {
            $this->error("Draft file not found: {$draftPath}");

            return self::FAILURE;
        }

        $result = BlueprintWorkflow::generateDeltaMigrations($draftPath);

        if ($result['status'] === 'no_models') {
            $this->warn('No models found in draft. Nothing to do.');

            return self::SUCCESS;
        }

        if ($result['status'] === 'initialized') {
            $this->warn('No snapshot found for this draft.');
            $this->line('Initialize it first with:');
            $this->line("php artisan bp:snapshot {$draft}");

            return self::FAILURE;
        }

        if ($result['created'] > 0) {
            foreach ($result['migrations'] as $migration) {
                $this->info("Created migration: {$migration}");
            }
            $this->line('Snapshot updated: ' . $result['snapshot_file']);
            $this->line('Running blueprint safe build without migration generation...');

            $exitCode = BlueprintWorkflow::runBlueprintBuild(
                $draftPath,
                BlueprintWorkflow::smartOnlySet(true, $full, false)
            );
            $this->line(Artisan::output());

            return $exitCode;
        }

        $this->line('No added columns detected. Running normal safe build...');
        $exitCode = BlueprintWorkflow::runBlueprintBuild(
            $draftPath,
            BlueprintWorkflow::smartOnlySet(
                false,
                $full,
                BlueprintWorkflow::draftNeedsCreateMigrations($draftPath)
            )
        );

        $this->line(Artisan::output());

        return $exitCode;
    }
}
