<?php

namespace App\Blueprint\Generators;

use Blueprint\Models\Column;
use Blueprint\Models\Model;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ModelGenerator extends \Blueprint\Generators\ModelGenerator
{
    protected function populateStub(string $stub, Model $model): string
    {
        $path = $this->getPath($model);
        $existingContent = $this->filesystem->exists($path)
            ? $this->filesystem->get($path)
            : null;

        $this->addImport($model, 'Database\\Factories\\' . $model->name() . 'Factory');

        if ($this->needsCarbonImport($model)) {
            $this->addImport($model, Carbon::class);
        }

        $generated = parent::populateStub($stub, $model);
        $generated = $this->removeBelongsToManyAsAlias($generated);
        $generated = $this->forcePivotModelWhenAliased($generated, $model);
        $generated = str_replace('\\'.Carbon::class, 'Carbon', $generated);
        $generated = $this->addRelationshipPropertyReads($generated);
        $generated = $this->enforceExplicitBelongsToManyArguments($generated, $model);
        $generated = $this->addRelationshipDocblocks($generated);

        if ($existingContent !== null) {
            $generated = $this->preserveExistingModelCustomizations($generated, $existingContent, $model);
        }

        return $generated;
    }

    protected function castableColumns(array $columns): array
    {
        $casts = [];

        foreach ($columns as $name => $column) {
            $cast = $this->customCastForColumn($column);
            if ($cast !== null) {
                $casts[$name] = $cast;
            }
        }

        return $casts;
    }

    private function customCastForColumn(Column $column): ?string
    {
        $dataType = strtolower($column->dataType());

        if ($dataType === 'date') {
            return 'date';
        }

        if (str_contains($dataType, 'datetime')) {
            return 'datetime';
        }

        if (str_contains($dataType, 'timestamp')) {
            return 'timestamp';
        }

        if (in_array($dataType, ['decimal', 'unsigneddecimal'], true)) {
            $attributes = $column->attributes();
            $scale = $attributes[1] ?? 2;

            return 'decimal:' . $scale;
        }

        if (in_array($dataType, ['boolean', 'double', 'float'], true)) {
            return $dataType;
        }

        if ($dataType === 'json') {
            return 'array';
        }

        return null;
    }

    private function needsCarbonImport(Model $model): bool
    {
        if ($model->usesTimestamps() || $model->usesSoftDeletes()) {
            return true;
        }

        foreach ($model->columns() as $column) {
            $type = strtolower($column->dataType());
            if ($type === 'date' || str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
                return true;
            }
        }

        return false;
    }

    private function addRelationshipDocblocks(string $content): string
    {
        return (string) preg_replace_callback(
            '/^( {4})public function (\w+)\(\): ([A-Za-z_\\\\]+)\R\1\{\R([\s\S]*?)\R\1\}/m',
            function (array $matches): string {
                $indent = $matches[1];
                $returnType = Str::afterLast($matches[3], '\\');
                $body = $matches[4];

                if (!preg_match('/return \$this->[a-zA-Z_]\w*\(([^)]*)\)/', $body, $returnMatch)) {
                    return $matches[0];
                }

                $firstArgument = trim(explode(',', $returnMatch[1])[0] ?? '');
                $relatedClass = null;

                if (str_contains($firstArgument, '::class')) {
                    $relatedClass = Str::afterLast(Str::before($firstArgument, '::class'), '\\');
                }

                $pivotClass = null;
                if ($returnType === 'BelongsToMany' && preg_match('/->using\(([^)]+)::class\)/', $body, $pivotMatch)) {
                    $pivotClass = Str::afterLast(trim($pivotMatch[1]), '\\');
                }

                if ($relatedClass !== null) {
                    $genericReturn = $returnType . '<' . $relatedClass . ', $this' . ($pivotClass !== null ? ', ' . $pivotClass : '') . '>';
                } else {
                    $genericReturn = $returnType;
                }

                $docblock = $indent . '/**' . PHP_EOL
                    . $indent . ' * @return ' . $genericReturn . PHP_EOL
                    . $indent . ' */';

                return $docblock . PHP_EOL . $matches[0];
            },
            $content
        );
    }

    private function addRelationshipPropertyReads(string $content): string
    {
        preg_match('/\/\*\*[\s\S]*?\*\//', $content, $docMatch);
        if (! isset($docMatch[0])) {
            return $content;
        }

        $classDoc = $docMatch[0];
        $propertyLines = [];
        $usesToMany = false;

        preg_match_all(
            '/public function (\w+)\(\): ([A-Za-z_\\\\]+)\R\s*\{\R\s*return \$this->([a-zA-Z_]\w*)\(([^)]*)\)/m',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $property = $match[1];
            $relationType = $match[3];
            $args = $match[4] ?? '';

            $relatedClass = 'mixed';
            if (preg_match('/([A-Za-z_\\\\]+)::class/', $args, $relatedMatch)) {
                $relatedClass = Str::afterLast($relatedMatch[1], '\\');
            }

            if (in_array($relationType, ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany', 'morphedByMany'], true)) {
                $type = "Collection<int, {$relatedClass}>";
                $usesToMany = true;
            } elseif (in_array($relationType, ['belongsTo', 'hasOne', 'morphOne', 'morphTo'], true)) {
                $type = "{$relatedClass}|null";
            } else {
                $type = $relatedClass;
            }

            $line = " * @property-read {$type} \${$property}";
            if (! str_contains($classDoc, $line)) {
                $propertyLines[] = $line;
            }
        }

        if ($propertyLines === []) {
            return $content;
        }

        if ($usesToMany && ! str_contains($content, 'use Illuminate\\Database\\Eloquent\\Collection;')) {
            $content = preg_replace(
                '/((?:^use [^;]+;\R)+)/m',
                "$1use Illuminate\\Database\\Eloquent\\Collection;\n",
                $content,
                1
            ) ?? $content;
        }

        $updatedDoc = rtrim(substr($classDoc, 0, -3));
        foreach ($propertyLines as $line) {
            $updatedDoc .= PHP_EOL.$line;
        }
        $updatedDoc .= PHP_EOL.' */';

        return str_replace($classDoc, $updatedDoc, $content);
    }

    private function forcePivotModelWhenAliased(string $content, Model $model): string
    {
        if (! $this->isPivotAliasModel($model)) {
            return $content;
        }

        $content = str_replace(
            'use Illuminate\\Database\\Eloquent\\Model;',
            'use Illuminate\\Database\\Eloquent\\Relations\\Pivot;',
            $content
        );

        $content = str_replace(
            'class '.$model->name().' extends Model',
            'class '.$model->name().' extends Pivot',
            $content
        );

        if (! str_contains($content, 'protected $table =')) {
            $tableStub = str_replace('{{ name }}', $model->tableName(), $this->filesystem->stub('model.table.stub'));
            $content = str_replace('use HasFactory;' . PHP_EOL, 'use HasFactory;' . PHP_EOL . PHP_EOL . $tableStub . PHP_EOL, $content);
        }

        return $content;
    }

    private function removeBelongsToManyAsAlias(string $content): string
    {
        return preg_replace(
            "/\\R\\s*->as\\('[^']+'\\)/",
            '',
            $content
        );
    }

    private function isPivotAliasModel(Model $targetModel): bool
    {
        $target = strtolower($targetModel->name());

        foreach ($this->tree->models() as $model) {
            foreach (($model->relationships()['belongsToMany'] ?? []) as $reference) {
                if (! str_contains($reference, ':&')) {
                    continue;
                }

                $alias = Str::after($reference, ':&');
                $alias = Str::afterLast($alias, '\\');

                if (strtolower($alias) === $target) {
                    return true;
                }
            }
        }

        return false;
    }

    private function preserveExistingModelCustomizations(string $generated, string $existing, Model $model): string
    {
        $generated = $this->preserveExtendsClass($generated, $existing, $model);
        $generated = $this->preserveTraitUses($generated, $existing);

        return $this->preserveSearchableMethod($generated, $existing);
    }

    private function preserveExtendsClass(string $generated, string $existing, Model $model): string
    {
        $pattern = '/class\s+'.preg_quote($model->name(), '/').'\s+extends\s+([^\s{]+)/';
        if (! preg_match($pattern, $existing, $match)) {
            return $generated;
        }

        $existingParentClass = $match[1];

        return (string) preg_replace(
            '/class\s+'.preg_quote($model->name(), '/').'\s+extends\s+[^\s{]+/',
            'class '.$model->name().' extends '.$existingParentClass,
            $generated,
            1
        );
    }

    private function preserveTraitUses(string $generated, string $existing): string
    {
        preg_match_all('/^use\s+[^;]+;/m', $existing, $existingImports);
        preg_match_all('/^use\s+[^;]+;/m', $generated, $generatedImports);

        $missingImports = array_values(array_diff($existingImports[0] ?? [], $generatedImports[0] ?? []));
        if ($missingImports !== []) {
            $generated = $this->addImportsToGeneratedContent($generated, $missingImports);
        }

        preg_match_all('/^[ \t]{4}use\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*;/m', $existing, $existingTraitMatches);
        preg_match_all('/^[ \t]{4}use\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*;/m', $generated, $generatedTraitMatches);

        $existingTraits = array_unique($existingTraitMatches[1] ?? []);
        $generatedTraits = array_unique($generatedTraitMatches[1] ?? []);
        $missingTraits = array_values(array_diff($existingTraits, $generatedTraits));

        foreach ($missingTraits as $missingTrait) {
            $generated = $this->addTraitUseToGeneratedContent($generated, $missingTrait);
        }

        return $generated;
    }

    /**
     * @param list<string> $imports
     */
    private function addImportsToGeneratedContent(string $generated, array $imports): string
    {
        $importsBlock = implode(PHP_EOL, $imports);

        if (preg_match('/^use\s+[^;]+;\R/m', $generated) === 1) {
            return (string) preg_replace(
                '/((?:^use\s+[^;]+;\R)+)/m',
                '$1'.$importsBlock.PHP_EOL,
                $generated,
                1
            );
        }

        return (string) preg_replace(
            '/^(namespace\s+[^\n]+;\R\R)/m',
            '$1'.$importsBlock.PHP_EOL.PHP_EOL,
            $generated,
            1
        );
    }

    private function addTraitUseToGeneratedContent(string $generated, string $trait): string
    {
        if (preg_match('/^[ \t]{4}use\s+[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s*;/m', $generated) === 1) {
            return (string) preg_replace(
                '/((?:^[ \t]{4}use\s+[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s*;\R)+)/m',
                '$1    use '.$trait.';'.PHP_EOL,
                $generated,
                1
            );
        }

        return (string) preg_replace(
            '/(class\s+[^{]+\{\R)/m',
            '$1    use '.$trait.';'.PHP_EOL.PHP_EOL,
            $generated,
            1
        );
    }

    private function preserveSearchableMethod(string $generated, string $existing): string
    {
        if (! preg_match('/^[ \t]{4}public function searchable\(\): array\s*\{[\s\S]*?^[ \t]{4}\}/m', $existing, $match)) {
            return $generated;
        }

        $existingSearchableMethod = $match[0];

        if (preg_match('/^[ \t]{4}public function searchable\(\): array\s*\{[\s\S]*?^[ \t]{4}\}/m', $generated) === 1) {
            return (string) preg_replace(
                '/^[ \t]{4}public function searchable\(\): array\s*\{[\s\S]*?^[ \t]{4}\}/m',
                $existingSearchableMethod,
                $generated,
                1
            );
        }

        return (string) preg_replace('/\R\}\s*$/', PHP_EOL.PHP_EOL.$existingSearchableMethod.PHP_EOL.'}', $generated, 1);
    }

    private function enforceExplicitBelongsToManyArguments(string $content, Model $model): string
    {
        return (string) preg_replace_callback(
            '/^(\s*)public function\s+([A-Za-z_]\w*)\(\):\s*BelongsToMany\s*\R\1\{\R\1\s{4}return \$this->belongsToMany\(([^)]*)\)([\s\S]*?)\;\R\1\}/m',
            function (array $matches) use ($model): string {
                $indent = $matches[1];
                $rawArguments = trim($matches[3]);
                $chain = $matches[4] ?? '';

                if (str_contains($rawArguments, ',')) {
                    return $matches[0];
                }

                if (! str_ends_with($rawArguments, '::class')) {
                    return $matches[0];
                }

                $relatedClass = Str::afterLast(Str::before($rawArguments, '::class'), '\\');
                if ($relatedClass === '') {
                    return $matches[0];
                }

                $currentKey = Str::snake(Str::singular($model->name())).'_id';
                $relatedKey = Str::snake(Str::singular($relatedClass)).'_id';

                $pivotTable = null;
                if (preg_match('/->using\(([^)]+)::class\)/', $chain, $pivotMatch)) {
                    $pivotClass = Str::afterLast(trim($pivotMatch[1]), '\\');
                    $pivotTable = Str::snake(Str::pluralStudly($pivotClass));
                }

                if ($pivotTable === null) {
                    $tables = [
                        Str::snake(Str::singular($model->name())),
                        Str::snake(Str::singular($relatedClass)),
                    ];
                    sort($tables);
                    $pivotTable = implode('_', $tables);
                }

                $relationship = '$this->belongsToMany('
                    . PHP_EOL . $indent . '            ' . $rawArguments . ','
                    . PHP_EOL . $indent . "            '{$pivotTable}',"
                    . PHP_EOL . $indent . "            '{$currentKey}',"
                    . PHP_EOL . $indent . "            '{$relatedKey}',"
                    . PHP_EOL . $indent . '        )';

                return $indent.'public function '.$matches[2].'(): BelongsToMany'.PHP_EOL
                    .$indent.'{'.PHP_EOL
                    .$indent.'    return '.$relationship.$chain.';'.PHP_EOL
                    .$indent.'}';
            },
            $content
        );
    }
}
