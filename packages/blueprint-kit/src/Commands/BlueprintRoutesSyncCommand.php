<?php

namespace RichardVullings\BlueprintKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RichardVullings\BlueprintKit\Support\BlueprintWorkflow;

class BlueprintRoutesSyncCommand extends Command
{
    protected $signature = 'bp:routes:sync {draft : Path to Blueprint draft yaml}';

    protected $description = 'Append only missing apiResource routes from draft to routes/api.php';

    public function handle(): int
    {
        $draftPath = BlueprintWorkflow::resolveDraftPath((string) $this->argument('draft'));

        if (! File::exists($draftPath)) {
            $this->error("Draft file not found: {$draftPath}");

            return self::FAILURE;
        }

        $result = BlueprintWorkflow::syncApiRoutesFromDraft($draftPath);

        if ($result['added_routes'] === 0) {
            $this->info('No new api routes found. routes/api.php unchanged.');

            return self::SUCCESS;
        }

        $this->info('Added routes: ' . $result['added_routes']);
        $this->line('Added imports: ' . $result['added_imports']);

        return self::SUCCESS;
    }
}
