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
        $this->addImport($model, 'Database\\Factories\\' . $model->name() . 'Factory');

        if ($this->needsCarbonImport($model)) {
            $this->addImport($model, Carbon::class);
        }

        $generated = parent::populateStub($stub, $model);
        $generated = $this->forcePivotModelWhenAliased($generated, $model);
        $generated = str_replace('\\'.Carbon::class, 'Carbon', $generated);
        $generated = $this->addRelationshipPropertyReads($generated);

        return $this->addRelationshipDocblocks($generated);
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
}
