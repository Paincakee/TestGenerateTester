<?php

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tests:generate-routes {controller? : Controller class/basename} {--force : Overwrite existing generated files} {--from-methods : Generate from public controller methods when no route exists}', function (?string $controller = null) {
    $controllerFilter = $controller ? Str::lower($controller) : null;
    $groupedRoutes = [];

    /** @var Route $route */
    foreach (app('router')->getRoutes() as $route) {
        $actionName = $route->getActionName();

        if ($actionName === 'Closure' || ! str_contains($actionName, '@')) {
            continue;
        }

        [$controllerClass, $action] = explode('@', $actionName);
        $controllerBaseName = class_basename($controllerClass);

        if ($controllerFilter !== null) {
            $matches = in_array($controllerFilter, [
                Str::lower($controllerClass),
                Str::lower($controllerBaseName),
                Str::lower(Str::before($controllerBaseName, 'Controller')),
            ], true);

            if (! $matches) {
                continue;
            }
        }

        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        foreach ($methods as $httpMethod) {
            $groupedRoutes[$controllerClass][] = [
                'http_method' => $httpMethod,
                'uri' => '/'.$route->uri(),
                'route_name' => $route->getName() ?? '-',
                'action' => $action,
            ];
        }
    }

    if ($groupedRoutes === [] && $this->option('from-methods')) {
        $controllerFiles = File::allFiles(app_path('Http/Controllers'));

        foreach ($controllerFiles as $controllerFile) {
            $relativePath = str_replace('/', '\\', Str::before($controllerFile->getRelativePathname(), '.php'));
            $controllerClass = 'App\\Http\\Controllers\\'.$relativePath;

            if (! class_exists($controllerClass) || $controllerClass === Controller::class) {
                continue;
            }

            $controllerBaseName = class_basename($controllerClass);

            if ($controllerFilter !== null) {
                $matches = in_array($controllerFilter, [
                    Str::lower($controllerClass),
                    Str::lower($controllerBaseName),
                    Str::lower(Str::before($controllerBaseName, 'Controller')),
                ], true);

                if (! $matches) {
                    continue;
                }
            }

            $resourceSegment = Str::of($controllerBaseName)
                ->before('Controller')
                ->kebab()
                ->plural()
                ->toString();

            $reflectionClass = new ReflectionClass($controllerClass);

            foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
                if ($reflectionMethod->getDeclaringClass()->getName() !== $controllerClass) {
                    continue;
                }

                $methodName = $reflectionMethod->getName();

                if (str_starts_with($methodName, '__')) {
                    continue;
                }

                $httpMethod = 'GET';
                $uri = "/{$resourceSegment}";

                if ($methodName === 'create') {
                    $uri = "/{$resourceSegment}/create";
                } elseif ($methodName === 'store') {
                    $httpMethod = 'POST';
                } elseif ($methodName === 'show') {
                    $uri = "/{$resourceSegment}/{id}";
                } elseif ($methodName === 'edit') {
                    $uri = "/{$resourceSegment}/{id}/edit";
                } elseif ($methodName === 'update') {
                    $httpMethod = 'PUT';
                    $uri = "/{$resourceSegment}/{id}";
                } elseif ($methodName === 'destroy') {
                    $httpMethod = 'DELETE';
                    $uri = "/{$resourceSegment}/{id}";
                } elseif ($methodName !== 'index') {
                    $uri = "/{$resourceSegment}/{$methodName}";
                }

                $groupedRoutes[$controllerClass][] = [
                    'http_method' => $httpMethod,
                    'uri' => $uri,
                    'route_name' => '-',
                    'action' => $methodName,
                ];
            }
        }
    }

    if ($groupedRoutes === []) {
        $this->warn('No controller routes/methods found for the given filter.');

        return self::SUCCESS;
    }

    $generatedCount = 0;
    $skippedCount = 0;

    foreach ($groupedRoutes as $controllerClass => $routes) {
        $controllerBaseName = class_basename($controllerClass);
        $testPath = base_path("tests/Feature/{$controllerBaseName}Test.php");

        if (File::exists($testPath) && ! $this->option('force')) {
            $this->warn("Skipped existing file: {$testPath}");
            $skippedCount++;

            continue;
        }

        usort($routes, fn (array $a, array $b) => [$a['uri'], $a['http_method']] <=> [$b['uri'], $b['http_method']]);

        $content = "<?php\n\n";
        $content .= "use {$controllerClass};\n\n";

        foreach ($routes as $route) {
            $title = "[{$route['http_method']}] {$route['uri']} uses {$controllerBaseName}::{$route['action']}";
            $incompleteMessage = "Generated stub for route {$route['http_method']} {$route['uri']} (name: {$route['route_name']}). Replace with real request and assertions.";

            $content .= 'it('.var_export($title, true).", function () {\n";
            $content .= '    $this->markTestIncomplete('.var_export($incompleteMessage, true).");\n";
            $content .= "})->covers([{$controllerBaseName}::class, '{$route['action']}']);\n\n";
        }

        File::ensureDirectoryExists(dirname($testPath));
        File::put($testPath, $content);

        $this->info("Generated {$testPath}");
        $generatedCount++;
    }

    $this->newLine();
    $this->info("Done. Generated: {$generatedCount}, skipped: {$skippedCount}");

    return self::SUCCESS;
})->purpose('Generate Pest Feature test stubs from controller routes');

Artisan::command('bp:delta {draft : Path to Blueprint draft yaml}', function (string $draft) {
    $draftPath = Str::startsWith($draft, '/')
        ? $draft
        : base_path($draft);

    if (! File::exists($draftPath)) {
        $this->error("Draft file not found: {$draftPath}");

        return self::FAILURE;
    }

    $result = bpDeltaGenerateMigrations($draftPath);

    if ($result['status'] === 'no_models') {
        $this->warn('No models found in draft. Nothing to do.');

        return self::SUCCESS;
    }

    if ($result['status'] === 'initialized') {
        $this->info('Snapshot initialized. Run the command again after draft changes to generate delta migrations.');
        $this->line('Snapshot: '.$result['snapshot_file']);

        return self::SUCCESS;
    }

    foreach ($result['migrations'] as $migration) {
        $this->info("Created migration: {$migration}");
    }

    $this->line('Snapshot updated: '.$result['snapshot_file']);

    if ($result['created'] === 0) {
        $this->info('No added columns detected. No migration generated.');
    }

    return self::SUCCESS;
})->purpose('Generate add-column delta migrations by comparing a draft against its previous snapshot');

Artisan::command('bp:smart {draft : Path to Blueprint draft yaml} {--full : Include controller and test generation}', function (string $draft) {
    $draftPath = Str::startsWith($draft, '/')
        ? $draft
        : base_path($draft);

    if (! File::exists($draftPath)) {
        $this->error("Draft file not found: {$draftPath}");

        return self::FAILURE;
    }

    $result = bpDeltaGenerateMigrations($draftPath);

    if ($result['status'] === 'no_models') {
        $this->warn('No models found in draft. Nothing to do.');

        return self::SUCCESS;
    }

    if ($result['status'] === 'initialized') {
        $this->line('Snapshot initialized: '.$result['snapshot_file']);
        $this->line('Running blueprint safe build (first run)...');
        $exitCode = bpRunBlueprintBuild(
            $draftPath,
            bpSmartOnlySet(
                false,
                (bool) $this->option('full'),
                bpDraftNeedsCreateMigrations($draftPath)
            )
        );
        $this->line(Artisan::output());

        return $exitCode;
    }

    if ($result['created'] > 0) {
        foreach ($result['migrations'] as $migration) {
            $this->info("Created migration: {$migration}");
        }
        $this->line('Snapshot updated: '.$result['snapshot_file']);
        $this->line('Running blueprint safe build without migration generation...');

        $exitCode = bpRunBlueprintBuild(
            $draftPath,
            bpSmartOnlySet(true, (bool) $this->option('full'), false)
        );
        $this->line(Artisan::output());

        return $exitCode;
    }

    $this->line('No added columns detected. Running normal safe build...');
    $exitCode = bpRunBlueprintBuild(
        $draftPath,
        bpSmartOnlySet(
            false,
            (bool) $this->option('full'),
            bpDraftNeedsCreateMigrations($draftPath)
        )
    );
    $this->line(Artisan::output());

    return $exitCode;
})->purpose('Run delta migration generation first, then execute the appropriate safe Blueprint build');

Artisan::command('bp:routes:sync {draft : Path to Blueprint draft yaml}', function (string $draft) {
    $draftPath = Str::startsWith($draft, '/')
        ? $draft
        : base_path($draft);

    if (! File::exists($draftPath)) {
        $this->error("Draft file not found: {$draftPath}");

        return self::FAILURE;
    }

    $result = bpSyncApiRoutesFromDraft($draftPath);

    if ($result['added_routes'] === 0) {
        $this->info('No new api routes found. routes/api.php unchanged.');

        return self::SUCCESS;
    }

    $this->info('Added routes: '.$result['added_routes']);
    $this->line('Added imports: '.$result['added_imports']);

    return self::SUCCESS;
})->purpose('Append only missing apiResource routes from draft to routes/api.php');

/**
 * @param array<string, mixed> $models
 *
 * @return array<string, array<string, string>>
 */
function bpDeltaNormalizeModels(array $models): array
{
    $normalized = [];

    foreach ($models as $modelName => $definition) {
        if (! is_array($definition)) {
            continue;
        }

        $columns = [];
        foreach ($definition as $key => $value) {
            if (in_array((string) $key, ['relationships', 'indexes'], true)) {
                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            $columns[(string) $key] = trim($value);
        }

        $normalized[(string) $modelName] = $columns;
    }

    ksort($normalized);

    return $normalized;
}

/**
 * @param string $draftPath
 * @return array{
 *     status: 'initialized'|'processed'|'no_models',
 *     created: int,
 *     migrations: array<int, string>,
 *     snapshot_file: string
 * }
 * @throws FileNotFoundException
 */
function bpDeltaGenerateMigrations(string $draftPath): array
{
    $parsed = Yaml::parseFile($draftPath);
    $models = is_array($parsed['models'] ?? null) ? $parsed['models'] : [];

    $snapshotDir = storage_path('app/blueprint-delta');
    File::ensureDirectoryExists($snapshotDir);
    $snapshotFile = $snapshotDir.'/'.pathinfo($draftPath, PATHINFO_FILENAME).'.json';

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
        ? json_decode((string) File::get($snapshotFile), true)
        : null;

    if (! is_array($previousSnapshot)) {
        File::put($snapshotFile, json_encode($currentSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
        $migrationName = 'add_'.implode('_and_', array_keys($added))."_to_{$table}_table";
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

        File::put($filePath, $migration.PHP_EOL);
        $created++;
        $migrations[] = $fileName;
    }

    File::put($snapshotFile, json_encode($currentSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return [
        'status' => 'processed',
        'created' => $created,
        'migrations' => $migrations,
        'snapshot_file' => $snapshotFile,
    ];
}

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

function bpDraftNeedsCreateMigrations(string $draftPath): bool
{
    $parsed = Yaml::parseFile($draftPath);
    $models = is_array($parsed['models'] ?? null) ? array_keys($parsed['models']) : [];

    foreach ($models as $modelName) {
        $table = Str::snake(Str::pluralStudly((string) $modelName));
        $pattern = database_path("migrations/*_create_{$table}_table.php");
        $matches = glob($pattern);

        if ($matches === false || $matches === []) {
            return true;
        }
    }

    return false;
}

/**
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

    if (! File::exists($apiPath)) {
        File::put($apiPath, <<<PHP
<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
});
PHP
        );
    }

    $content = (string) File::get($apiPath);

    preg_match_all("/Route::apiResource\\('([^']+)'\\s*,\\s*[A-Za-z0-9_\\\\\\\\]+::class\\);/", $content, $existingMatches);
    $existingSlugs = $existingMatches[1] ?? [];
    $existingSlugs = array_map(static fn (string $slug) => strtolower($slug), $existingSlugs);

    $missing = [];
    foreach ($controllers as $slug => $controllerClass) {
        if (! in_array(strtolower($slug), $existingSlugs, true)) {
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
        $import = 'App\\Http\\Controllers\\'.$controllerClass;
        if (! in_array($import, $existingUses, true)) {
            $insertPos = strpos($content, 'Route::middleware(');
            if ($insertPos === false) {
                $insertPos = strlen($content);
            }

            $content = substr($content, 0, $insertPos)
                .'use '.$import.';'.PHP_EOL
                .substr($content, $insertPos);
            $existingUses[] = $import;
            $addedImports++;
        }
    }

    if (! str_contains($content, "Route::middleware(['auth:sanctum'])->group(function () {")) {
        $content = rtrim($content).PHP_EOL.PHP_EOL."Route::middleware(['auth:sanctum'])->group(function () {".PHP_EOL.'});'.PHP_EOL;
    }

    $routeLines = array_map(
        static fn (string $slug, string $controllerClass): string => "    Route::apiResource('{$slug}', {$controllerClass}::class);",
        array_keys($missing),
        array_values($missing)
    );

    $groupPattern = "/Route::middleware\\(\\['auth:sanctum'\\]\\)->group\\(function\\s*\\(\\)\\s*\\{([\\s\\S]*?)\\}\\);/";
    $content = (string) preg_replace_callback(
        $groupPattern,
        static function (array $matches) use ($routeLines): string {
            $currentBody = trim($matches[1]);
            $newBody = $currentBody === ''
                ? implode(PHP_EOL, $routeLines)
                : $currentBody.PHP_EOL.implode(PHP_EOL, $routeLines);

            return "Route::middleware(['auth:sanctum'])->group(function () {".PHP_EOL
                .$newBody.PHP_EOL
                .'});';
        },
        $content,
        1
    );

    File::put($apiPath, rtrim($content).PHP_EOL);

    return [
        'added_routes' => count($missing),
        'added_imports' => $addedImports,
    ];
}

/**
 * @return array<string, string> slug => controller class basename
 */
function bpDraftApiControllers(string $draftPath): array
{
    $parsed = Yaml::parseFile($draftPath);
    $controllers = is_array($parsed['controllers'] ?? null) ? $parsed['controllers'] : [];

    $result = [];

    foreach ($controllers as $controllerName => $definition) {
        if (! is_array($definition)) {
            continue;
        }

        if (($definition['resource'] ?? null) !== 'api') {
            continue;
        }

        $base = (string) Str::of((string) $controllerName)->afterLast('\\')->afterLast('/')->trim();
        if ($base === '') {
            continue;
        }

        $slug = Str::plural(Str::kebab($base));
        $result[$slug] = $base.'Controller';
    }

    return $result;
}

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
            $quoted = array_map(fn (string $value) => "'".str_replace("'", "\\'", $value)."'", $attributes);
            $line .= ', ['.implode(', ', $quoted).']';
        } elseif ($attributes !== []) {
            $line .= ', '.implode(', ', $attributes);
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
            $value = (string) Str::after($modifier, 'default:');
            $line .= '->default('.bpDeltaRenderDefaultValue($value).')';
            continue;
        }

        if (Str::startsWith($modifier, 'foreign:')) {
            continue;
        }
    }

    return $line.';';
}

function bpDeltaExtractModifierValue(array $modifiers, string $name): ?string
{
    foreach ($modifiers as $modifier) {
        if (Str::startsWith($modifier, $name.':')) {
            return (string) Str::after($modifier, $name.':');
        }
    }

    return null;
}

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

    return "'".str_replace("'", "\\'", $raw)."'";
}
