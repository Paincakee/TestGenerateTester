<?php

namespace RichardVullings\BlueprintKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RichardVullings\BlueprintKit\Support\BlueprintWorkflow;

class BlueprintDeltaCommand extends Command
{
    protected $signature = 'bp:delta {draft : Path to Blueprint draft yaml}';

    protected $description = 'Generate add-column delta migrations by comparing a draft against its previous snapshot';

    public function handle(): int
    {
        $draft = (string) $this->argument('draft');
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

        foreach ($result['migrations'] as $migration) {
            $this->info("Created migration: {$migration}");
        }

        $this->line('Snapshot updated: ' . $result['snapshot_file']);

        if ($result['created'] === 0) {
            $this->info('No added columns detected. No migration generated.');
        }

        return self::SUCCESS;
    }
}
