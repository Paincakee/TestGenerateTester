<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Blueprint Delta Command
|--------------------------------------------------------------------------
|
| Generates additive (delta) migrations by comparing the current draft with
| a stored snapshot in storage/app/blueprint-delta. This avoids rewriting
| existing create-table migrations for mature projects.
|
*/
Artisan::command('bp:delta {draft : Path to Blueprint draft yaml}', function (string $draft) {
    $draftPath = Str::startsWith($draft, '/')
        ? $draft
        : base_path($draft);

    if (!File::exists($draftPath)) {
        $this->error("Draft file not found: {$draftPath}");

        return self::FAILURE;
    }

    $result = bpDeltaGenerateMigrations($draftPath);

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
})->purpose('Generate add-column delta migrations by comparing a draft against its previous snapshot');

/*
|--------------------------------------------------------------------------
| Blueprint Snapshot Command
|--------------------------------------------------------------------------
|
| Initializes or refreshes the draft snapshot used by bp:delta / bp:smart.
| Run this once when onboarding an existing project state.
|
*/
Artisan::command('bp:snapshot {draft : Path to Blueprint draft yaml} {--force : Overwrite existing snapshot}', function (string $draft) {
    $draftPath = Str::startsWith($draft, '/')
        ? $draft
        : base_path($draft);

    if (!File::exists($draftPath)) {
        $this->error("Draft file not found: {$draftPath}");

        return self::FAILURE;
    }

    $snapshotFile = bpDeltaSnapshotFile($draftPath);

    if (File::exists($snapshotFile) && ! $this->option('force')) {
        $this->warn("Snapshot already exists: {$snapshotFile}");
        $this->line('Use --force to overwrite it.');

        return self::SUCCESS;
    }

    bpDeltaWriteSnapshot($draftPath);
    $this->info('Snapshot written: '.$snapshotFile);

    return self::SUCCESS;
})->purpose('Initialize or refresh draft snapshot used by bp:delta and bp:smart');

/*
|--------------------------------------------------------------------------
| Blueprint Smart Command
|--------------------------------------------------------------------------
|
| One-command workflow for daily development:
| 1) Run delta detection to generate add-column migrations when needed.
| 2) Run Blueprint build with a safe generator set.
| 3) Optionally include controller/test generation via --full.
|
*/
Artisan::command('bp:smart {draft : Path to Blueprint draft yaml} {--full : Include controller and test generation}', function (string $draft) {
    $draftPath = Str::startsWith($draft, '/')
        ? $draft
        : base_path($draft);

    if (!File::exists($draftPath)) {
        $this->error("Draft file not found: {$draftPath}");

        return self::FAILURE;
    }

    $result = bpDeltaGenerateMigrations($draftPath);

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

        $exitCode = bpRunBlueprintBuild(
            $draftPath,
            bpSmartOnlySet(true, (bool)$this->option('full'), false)
        );
        $this->line(Artisan::output());

        return $exitCode;
    }

    $this->line('No added columns detected. Running normal safe build...');
    $exitCode = bpRunBlueprintBuild(
        $draftPath,
        bpSmartOnlySet(
            false,
            (bool)$this->option('full'),
            bpDraftNeedsCreateMigrations($draftPath)
        )
    );
    $this->line(Artisan::output());

    return $exitCode;
})->purpose('Run delta migration generation first, then execute the appropriate safe Blueprint build');

/*
|--------------------------------------------------------------------------
| Blueprint Route Sync Command
|--------------------------------------------------------------------------
|
| Appends only missing apiResource routes from a draft into routes/api.php.
| Existing routes are kept as-is, so this command is safe for projects with
| custom route logic.
|
*/
Artisan::command('bp:routes:sync {draft : Path to Blueprint draft yaml}', function (string $draft) {
    $draftPath = Str::startsWith($draft, '/')
        ? $draft
        : base_path($draft);

    if (!File::exists($draftPath)) {
        $this->error("Draft file not found: {$draftPath}");

        return self::FAILURE;
    }

    $result = bpSyncApiRoutesFromDraft($draftPath);

    if ($result['added_routes'] === 0) {
        $this->info('No new api routes found. routes/api.php unchanged.');

        return self::SUCCESS;
    }

    $this->info('Added routes: ' . $result['added_routes']);
    $this->line('Added imports: ' . $result['added_imports']);

    return self::SUCCESS;
})->purpose('Append only missing apiResource routes from draft to routes/api.php');

/**
 * Normalize draft model definitions to a comparable array shape.
 *
 * @param array<string, mixed> $models
 *
 * @return array<string, array<string, string>>
 */
function bpDeltaNormalizeModels(array $models): array
{
    $normalized = [];

    foreach ($models as $modelName => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $columns = [];
        foreach ($definition as $key => $value) {
            if (in_array((string)$key, ['relationships', 'indexes'], true)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $columns[(string)$key] = trim($value);
        }

        $normalized[(string)$modelName] = $columns;
    }

    ksort($normalized);

    return $normalized;
}

/**
 * Generate delta migrations by comparing the current draft with its snapshot.
 *
 * First run only initializes snapshot state. Next runs create additive
 * migrations for newly added columns.
 *
 * @param string $draftPath
 *
 * @return array{
 *     status: 'initialized'|'processed'|'no_models',
 *     created: int,
 *     migrations: array<int, string>,
 *     snapshot_file: string
 * }
 */
function bpDeltaGenerateMigrations(string $draftPath): array
{
    $parsed = Yaml::parseFile($draftPath);
    $models = is_array($parsed['models'] ?? null) ? $parsed['models'] : [];

    $snapshotFile = bpDeltaSnapshotFile($draftPath);

    if ($models === []) {
        return [
            'status' => 'no_models',
            'created' => 0,
            'migrations' => [],
            'snapshot_file' => $snapshotFile,
        ];
    }

    $currentSnapshot = bpDeltaNormalizeModels($models);
    $previousSnapshot = File::exists($snapshotFile)
        ? json_decode((string)File::get($snapshotFile), true)
        : null;

    if (!is_array($previousSnapshot)) {
        return [
            'status' => 'initialized',
            'created' => 0,
            'migrations' => [],
            'snapshot_file' => $snapshotFile,
        ];
    }

    $created = 0;
    $migrations = [];

    foreach ($currentSnapshot as $modelName => $columns) {
        $oldColumns = $previousSnapshot[$modelName] ?? [];
        $added = array_diff_key($columns, $oldColumns);

        if ($added === []) {
            continue;
        }

        $table = Str::snake(Str::pluralStudly($modelName));
        $migrationName = 'add_' . implode('_and_', array_keys($added)) . "_to_{$table}_table";
        $timestamp = now()->format('Y_m_d_His');
        $fileName = "{$timestamp}_{$migrationName}.php";
        $filePath = database_path("migrations/{$fileName}");

        $counter = 0;
        while (File::exists($filePath)) {
            $counter++;
            $fileName = "{$timestamp}_{$migrationName}_{$counter}.php";
            $filePath = database_path("migrations/{$fileName}");
        }

        $addLines = [];
        foreach ($added as $column => $definition) {
            $addLines[] = bpDeltaBuildColumnLine($column, $definition);
        }

        $dropColumns = implode("', '", array_keys($added));

        $migration = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('{$table}', function (Blueprint \$table) {
{$addLines[0]}
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('{$table}', function (Blueprint \$table) {
            \$table->dropColumn(['{$dropColumns}']);
        });
    }
};
PHP;

        if (count($addLines) > 1) {
            $migration = str_replace($addLines[0], implode(PHP_EOL, $addLines), $migration);
        }

        File::put($filePath, $migration . PHP_EOL);
        $created++;
        $migrations[] = $fileName;
    }

    bpDeltaWriteSnapshot($draftPath, $currentSnapshot);

    return [
        'status' => 'processed',
        'created' => $created,
        'migrations' => $migrations,
        'snapshot_file' => $snapshotFile,
    ];
}

function bpDeltaSnapshotFile(string $draftPath): string
{
    $snapshotDir = storage_path('app/blueprint-delta');
    File::ensureDirectoryExists($snapshotDir);

    return $snapshotDir . '/' . pathinfo($draftPath, PATHINFO_FILENAME) . '.json';
}

/**
 * @param array<string, array<string, string>>|null $normalizedModels
 */
function bpDeltaWriteSnapshot(string $draftPath, ?array $normalizedModels = null): void
{
    if ($normalizedModels === null) {
        $parsed = Yaml::parseFile($draftPath);
        $models = is_array($parsed['models'] ?? null) ? $parsed['models'] : [];
        $normalizedModels = bpDeltaNormalizeModels($models);
    }

    File::put(
        bpDeltaSnapshotFile($draftPath),
        json_encode($normalizedModels, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

/**
 * Execute Blueprint build with safe defaults used by bp:smart.
 *
 * @param string $draftPath
 * @param string|null $only
 */
function bpRunBlueprintBuild(string $draftPath, ?string $only = null): int
{
    $params = [
        'draft' => $draftPath,
        '--skip' => 'routes',
    ];

    if ($only !== null) {
        $params['--only'] = $only;
    }

    return Artisan::call('blueprint:build', $params);
}

/**
 * Resolve the --only generator set used by bp:smart.
 *
 * @param bool $deltaCreated Whether a delta migration was generated.
 * @param bool $full Whether to include controllers/tests.
 * @param bool $includeMigrations Whether create migrations should run.
 */
function bpSmartOnlySet(bool $deltaCreated, bool $full, bool $includeMigrations): string
{
    if ($full) {
        return $deltaCreated
            ? 'models,factories,controllers,requests,resources,tests'
            : ($includeMigrations
                ? 'models,migrations,factories,controllers,requests,resources,tests'
                : 'models,factories,controllers,requests,resources,tests');
    }

    // Default safe set: keep custom controllers/tests untouched.
    return $deltaCreated
        ? 'models,factories,requests,resources'
        : ($includeMigrations
            ? 'models,migrations,factories,requests,resources'
            : 'models,factories,requests,resources');
}

/**
 * Determine whether base create-table migrations are still needed for draft models.
 *
 * @param string $draftPath
 */
function bpDraftNeedsCreateMigrations(string $draftPath): bool
{
    $parsed = Yaml::parseFile($draftPath);
    $models = is_array($parsed['models'] ?? null) ? array_keys($parsed['models']) : [];

    foreach ($models as $modelName) {
        $table = Str::snake(Str::pluralStudly((string)$modelName));
        $pattern = database_path("migrations/*_create_{$table}_table.php");
        $matches = glob($pattern);

        if ($matches === false || $matches === []) {
            return true;
        }
    }

    return false;
}

/**
 * Sync only missing apiResource routes from draft controllers into routes/api.php.
 *
 * @return array{added_routes: int, added_imports: int}
 */
function bpSyncApiRoutesFromDraft(string $draftPath): array
{
    $controllers = bpDraftApiControllers($draftPath);

    if ($controllers === []) {
        return [
            'added_routes' => 0,
            'added_imports' => 0,
        ];
    }

    $apiPath = base_path('routes/api.php');

    if (!File::exists($apiPath)) {
        File::put($apiPath, <<<PHP
<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
});
PHP
        );
    }

    $content = (string)File::get($apiPath);

    preg_match_all("/Route::apiResource\\('([^']+)'\\s*,\\s*[A-Za-z0-9_\\\\\\\\]+::class\\);/", $content, $existingMatches);
    $existingSlugs = $existingMatches[1] ?? [];
    $existingSlugs = array_map(static fn(string $slug) => strtolower($slug), $existingSlugs);

    $missing = [];
    foreach ($controllers as $slug => $controllerClass) {
        if (!in_array(strtolower($slug), $existingSlugs, true)) {
            $missing[$slug] = $controllerClass;
        }
    }

    if ($missing === []) {
        return [
            'added_routes' => 0,
            'added_imports' => 0,
        ];
    }

    ksort($missing);

    preg_match_all('/^use\s+([^;]+);$/m', $content, $useMatches);
    $existingUses = $useMatches[1] ?? [];

    $addedImports = 0;
    foreach ($missing as $controllerClass) {
        $import = 'App\\Http\\Controllers\\' . $controllerClass;
        if (!in_array($import, $existingUses, true)) {
            $insertPos = strpos($content, 'Route::middleware(');
            if ($insertPos === false) {
                $insertPos = strlen($content);
            }

            $content = substr($content, 0, $insertPos)
                . 'use ' . $import . ';' . PHP_EOL
                . substr($content, $insertPos);
            $existingUses[] = $import;
            $addedImports++;
        }
    }

    if (!str_contains($content, "Route::middleware(['auth:sanctum'])->group(function () {")) {
        $content = rtrim($content) . PHP_EOL . PHP_EOL . "Route::middleware(['auth:sanctum'])->group(function () {" . PHP_EOL . '});' . PHP_EOL;
    }

    $routeLines = array_map(
        static fn(string $slug, string $controllerClass): string => "    Route::apiResource('{$slug}', {$controllerClass}::class);",
        array_keys($missing),
        array_values($missing)
    );

    $groupPattern = "/Route::middleware\\(\\['auth:sanctum'\\]\\)->group\\(function\\s*\\(\\)\\s*\\{([\\s\\S]*?)\\}\\);/";
    $content = (string)preg_replace_callback(
        $groupPattern,
        static function (array $matches) use ($routeLines): string {
            $currentBody = trim($matches[1]);
            $newBody = $currentBody === ''
                ? implode(PHP_EOL, $routeLines)
                : $currentBody . PHP_EOL . implode(PHP_EOL, $routeLines);

            return "Route::middleware(['auth:sanctum'])->group(function () {" . PHP_EOL
                . $newBody . PHP_EOL
                . '});';
        },
        $content,
        1
    );

    File::put($apiPath, rtrim($content) . PHP_EOL);

    return [
        'added_routes' => count($missing),
        'added_imports' => $addedImports,
    ];
}

/**
 * Extract API resource controllers from draft controllers definition.
 *
 * @return array<string, string> slug => controller class basename
 */
function bpDraftApiControllers(string $draftPath): array
{
    $parsed = Yaml::parseFile($draftPath);
    $controllers = is_array($parsed['controllers'] ?? null) ? $parsed['controllers'] : [];

    $result = [];

    foreach ($controllers as $controllerName => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        if (($definition['resource'] ?? null) !== 'api') {
            continue;
        }

        $base = (string)Str::of((string)$controllerName)->afterLast('\\')->afterLast('/')->trim();
        if ($base === '') {
            continue;
        }

        $slug = Str::plural(Str::kebab($base));
        $result[$slug] = $base . 'Controller';
    }

    return $result;
}

/**
 * Build one schema line for an additive migration based on Blueprint column syntax.
 */
function bpDeltaBuildColumnLine(string $column, string $definition): string
{
    $tokens = preg_split('/\s+/', trim($definition)) ?: [];
    $typeToken = array_shift($tokens) ?? 'string';
    [$dataType, $rawAttributes] = array_pad(explode(':', $typeToken, 2), 2, null);
    $dataType = strtolower(trim($dataType));
    $attributes = $rawAttributes ? array_map('trim', explode(',', $rawAttributes)) : [];

    if ($dataType === 'id') {
        $line = "            \$table->foreignId('{$column}')";
        $foreign = bpDeltaExtractModifierValue($tokens, 'foreign');

        if (is_string($foreign) && $foreign !== '') {
            $line .= "->constrained('{$foreign}')";
        } else {
            $line .= '->constrained()';
        }
    } else {
        $line = "            \$table->{$dataType}('{$column}'";

        if ($dataType === 'enum') {
            $quoted = array_map(fn(string $value) => "'" . str_replace("'", "\\'", $value) . "'", $attributes);
            $line .= ', [' . implode(', ', $quoted) . ']';
        } elseif ($attributes !== []) {
            $line .= ', ' . implode(', ', $attributes);
        }

        $line .= ')';
    }

    foreach ($tokens as $modifier) {
        if ($modifier === 'nullable') {
            $line .= '->nullable()';
            continue;
        }

        if ($modifier === 'unique') {
            $line .= '->unique()';
            continue;
        }

        if ($modifier === 'index') {
            $line .= '->index()';
            continue;
        }

        if (Str::startsWith($modifier, 'default:')) {
            $value = (string)Str::after($modifier, 'default:');
            $line .= '->default(' . bpDeltaRenderDefaultValue($value) . ')';
            continue;
        }

        if (Str::startsWith($modifier, 'foreign:')) {
            continue;
        }
    }

    return $line . ';';
}

/**
 * Extract modifier value by key (e.g. foreign:users => users).
 */
function bpDeltaExtractModifierValue(array $modifiers, string $name): ?string
{
    foreach ($modifiers as $modifier) {
        if (Str::startsWith($modifier, $name . ':')) {
            return (string)Str::after($modifier, $name . ':');
        }
    }

    return null;
}

/**
 * Convert Blueprint default modifier values to valid PHP literals.
 */
function bpDeltaRenderDefaultValue(string $raw): string
{
    $lower = strtolower($raw);

    if (is_numeric($raw)) {
        return $raw;
    }

    if ($lower === 'true' || $lower === 'false') {
        return $lower;
    }

    if ($lower === 'null') {
        return 'null';
    }

    return "'" . str_replace("'", "\\'", $raw) . "'";
}
