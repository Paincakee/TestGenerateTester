<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class BlueprintWorkflow
{
    public static function resolveDraftPath(string $draft): string
    {
        return Str::startsWith($draft, '/') ? $draft : base_path($draft);
    }

    public static function snapshotFile(string $draftPath): string
    {
        $snapshotDir = storage_path('app/blueprint-delta');
        File::ensureDirectoryExists($snapshotDir);

        return $snapshotDir.'/'.pathinfo($draftPath, PATHINFO_FILENAME).'.json';
    }

    /**
     * @param array<string, array<string, string>>|null $normalizedModels
     */
    public static function writeSnapshot(string $draftPath, ?array $normalizedModels = null): void
    {
        if ($normalizedModels === null) {
            $parsed = Yaml::parseFile($draftPath);
            $models = is_array($parsed['models'] ?? null) ? $parsed['models'] : [];
            $normalizedModels = self::normalizeModels($models);
        }

        File::put(
            self::snapshotFile($draftPath),
            json_encode($normalizedModels, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param array<string, mixed> $models
     * @return array<string, array<string, string>>
     */
    public static function normalizeModels(array $models): array
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
     * @return array{
     *     status: 'initialized'|'processed'|'no_models',
     *     created: int,
     *     migrations: array<int, string>,
     *     snapshot_file: string
     * }
     */
    public static function generateDeltaMigrations(string $draftPath): array
    {
        $parsed = Yaml::parseFile($draftPath);
        $models = is_array($parsed['models'] ?? null) ? $parsed['models'] : [];
        $snapshotFile = self::snapshotFile($draftPath);

        if ($models === []) {
            return [
                'status' => 'no_models',
                'created' => 0,
                'migrations' => [],
                'snapshot_file' => $snapshotFile,
            ];
        }

        $currentSnapshot = self::normalizeModels($models);
        $previousSnapshot = File::exists($snapshotFile)
            ? json_decode((string) File::get($snapshotFile), true)
            : null;

        if (! is_array($previousSnapshot)) {
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
                $addLines[] = self::buildColumnLine($column, $definition);
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

        self::writeSnapshot($draftPath, $currentSnapshot);

        return [
            'status' => 'processed',
            'created' => $created,
            'migrations' => $migrations,
            'snapshot_file' => $snapshotFile,
        ];
    }

    public static function runBlueprintBuild(string $draftPath, ?string $only = null): int
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

    public static function smartOnlySet(bool $deltaCreated, bool $full, bool $includeMigrations): string
    {
        if ($full) {
            return $deltaCreated
                ? 'models,factories,controllers,requests,resources,tests'
                : ($includeMigrations
                    ? 'models,migrations,factories,controllers,requests,resources,tests'
                    : 'models,factories,controllers,requests,resources,tests');
        }

        return $deltaCreated
            ? 'models,factories,requests,resources'
            : ($includeMigrations
                ? 'models,migrations,factories,requests,resources'
                : 'models,factories,requests,resources');
    }

    public static function draftNeedsCreateMigrations(string $draftPath): bool
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
    public static function syncApiRoutesFromDraft(string $draftPath): array
    {
        $controllers = self::draftApiControllers($draftPath);

        if ($controllers === []) {
            return ['added_routes' => 0, 'added_imports' => 0];
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
        $existingSlugs = array_map(static fn (string $slug): string => strtolower($slug), $existingMatches[1] ?? []);

        $missing = [];
        foreach ($controllers as $slug => $controllerClass) {
            if (! in_array(strtolower($slug), $existingSlugs, true)) {
                $missing[$slug] = $controllerClass;
            }
        }

        if ($missing === []) {
            return ['added_routes' => 0, 'added_imports' => 0];
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
    public static function draftApiControllers(string $draftPath): array
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

    private static function buildColumnLine(string $column, string $definition): string
    {
        $tokens = preg_split('/\s+/', trim($definition)) ?: [];
        $typeToken = array_shift($tokens) ?? 'string';
        [$dataType, $rawAttributes] = array_pad(explode(':', $typeToken, 2), 2, null);
        $dataType = strtolower(trim($dataType));
        $attributes = $rawAttributes ? array_map('trim', explode(',', $rawAttributes)) : [];

        if ($dataType === 'id') {
            $line = "            \$table->foreignId('{$column}')";
            $foreign = self::extractModifierValue($tokens, 'foreign');

            $line .= is_string($foreign) && $foreign !== ''
                ? "->constrained('{$foreign}')"
                : '->constrained()';
        } else {
            $line = "            \$table->{$dataType}('{$column}'";

            if ($dataType === 'enum') {
                $quoted = array_map(static fn (string $value): string => "'".str_replace("'", "\\'", $value)."'", $attributes);
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
                $line .= '->default('.self::renderDefaultValue($value).')';
            }
        }

        return $line.';';
    }

    /**
     * @param array<int, string> $modifiers
     */
    private static function extractModifierValue(array $modifiers, string $name): ?string
    {
        foreach ($modifiers as $modifier) {
            if (Str::startsWith($modifier, $name.':')) {
                return (string) Str::after($modifier, $name.':');
            }
        }

        return null;
    }

    private static function renderDefaultValue(string $raw): string
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
}

