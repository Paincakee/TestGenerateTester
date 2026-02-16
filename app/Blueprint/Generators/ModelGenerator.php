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
        $generated = str_replace('\\'.Carbon::class, 'Carbon', $generated);

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

                $genericReturn = $relatedClass !== null
                    ? $returnType . '<' . $relatedClass . ', $this>'
                    : $returnType;

                $docblock = $indent . '/**' . PHP_EOL
                    . $indent . ' * @return ' . $genericReturn . PHP_EOL
                    . $indent . ' */';

                return $docblock . PHP_EOL . $matches[0];
            },
            $content
        );
    }
}
