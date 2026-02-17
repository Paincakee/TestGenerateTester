<?php

namespace RichardVullings\BlueprintKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RichardVullings\BlueprintKit\Support\BlueprintWorkflow;

class BlueprintSnapshotCommand extends Command
{
    protected $signature = 'bp:snapshot {draft : Path to Blueprint draft yaml} {--force : Overwrite existing snapshot}';

    protected $description = 'Initialize or refresh draft snapshot used by bp:delta and bp:smart';

    public function handle(): int
    {
        $draft = (string) $this->argument('draft');
        $draftPath = BlueprintWorkflow::resolveDraftPath($draft);

        if (! File::exists($draftPath)) {
            $this->error("Draft file not found: {$draftPath}");

            return self::FAILURE;
        }

        $snapshotFile = BlueprintWorkflow::snapshotFile($draftPath);

        if (File::exists($snapshotFile) && ! (bool) $this->option('force')) {
            $this->warn("Snapshot already exists: {$snapshotFile}");
            $this->line('Use --force to overwrite it.');

            return self::SUCCESS;
        }

        BlueprintWorkflow::writeSnapshot($draftPath);
        $this->info('Snapshot written: ' . $snapshotFile);

        return self::SUCCESS;
    }
}
