<?php

namespace App\Blueprint\Generators\Statements;

use Blueprint\Models\Controller;
use Blueprint\Models\Model;
use Blueprint\Models\Statements\ResourceStatement;
use Blueprint\Tree;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ResourceGenerator extends \Blueprint\Generators\Statements\ResourceGenerator
{
    public function output(Tree $tree): array
    {
        $this->tree = $tree;

        $stub = $this->filesystem->stub('resource.stub');

        /** @var Controller $controller */
        foreach ($tree->controllers() as $controller) {
            foreach ($controller->methods() as $statements) {
                foreach ($statements as $statement) {
                    if (! $statement instanceof ResourceStatement) {
                        continue;
                    }

                    $path = $this->getStatementPath(($controller->namespace() ? $controller->namespace() . '/' : '') . $statement->name());
                    $exists = $this->filesystem->exists($path);

                    $this->create($path, $this->populateStub($stub, $controller, $statement));
                    $this->output[$exists ? 'updated' : 'created'][] = ['Resource', $path];
                }
            }
        }

        return $this->output;
    }

    protected function populateStub(string $stub, Controller $controller, ResourceStatement $resource): string
    {
        $namespace = config('blueprint.namespace')
            . '\\Http\\Resources'
            . ($controller->namespace() ? '\\' . $controller->namespace() : '');

        $imports = ['use Illuminate\\Http\\Request;'];
        $imports[] = $resource->collection() && $resource->generateCollectionClass()
            ? 'use Illuminate\\Http\\Resources\\Json\\ResourceCollection;'
            : 'use Illuminate\\Http\\Resources\\Json\\JsonResource;';

        $mixin = '';
        $context = Str::singular($resource->reference());
        $model = $this->tree->modelForContext($context, true);
        if ($model !== null) {
            $modelClass = $model->name();
            $imports[] = 'use ' . $model->fullyQualifiedClassName() . ';';
            $mixin = '/** @mixin ' . $modelClass . ' */';
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        $stub = str_replace('{{ namespace }}', $namespace, $stub);
        $stub = str_replace('{{ imports }}', implode(PHP_EOL, $imports), $stub);
        $stub = str_replace('{{ parentClass }}', $resource->collection() && $resource->generateCollectionClass() ? 'ResourceCollection' : 'JsonResource', $stub);
        $stub = str_replace('{{ class }}', $resource->name(), $stub);
        $stub = str_replace('{{ resource }}', $resource->collection() && $resource->generateCollectionClass() ? 'resource collection' : 'resource', $stub);
        $stub = str_replace('{{ body }}', $this->buildData($resource), $stub);
        $stub = str_replace('{{ mixin }}', $mixin, $stub);

        return $stub;
    }

    protected function buildData(ResourceStatement $resource): string
    {
        $context = Str::singular($resource->reference());

        /** @var Model $model */
        $model = $this->tree->modelForContext($context, true);

        $data = [];
        if ($resource->collection() && $resource->generateCollectionClass()) {
            $data[] = 'return [';
            $data[] = self::INDENT . '\'data\' => $this->collection,';
            $data[] = '        ];';

            return implode(PHP_EOL, $data);
        }

        $data[] = 'return [';
        foreach ($this->visibleColumns($model) as $column) {
            $data[] = self::INDENT . '\'' . $column . '\' => $this->' . $column . ',';
        }

        foreach ($model->relationships() as $type => $relationship) {
            $methodName = lcfirst(Str::afterLast(Arr::last($relationship), '\\'));
            $relationModel = $this->tree->modelForContext($methodName);

            if ($relationModel === null) {
                continue;
            }

            if (in_array($type, ['hasMany', 'belongsToMany', 'morphMany'], true)) {
                $methodName = Str::plural($methodName);
                if (config('blueprint.generate_resource_collection_classes')) {
                    $relationResourceName = $relationModel->name() . 'Collection';
                    $data[] = self::INDENT . '\'' . $methodName . '\' => ' . $relationResourceName . '::make($this->whenLoaded(\'' . $methodName . '\')),';
                } else {
                    $relationResourceName = $relationModel->name() . 'Resource';
                    $data[] = self::INDENT . '\'' . $methodName . '\' => ' . $relationResourceName . '::collection($this->whenLoaded(\'' . $methodName . '\')),';
                }
            } else {
                $relationResourceName = $relationModel->name() . 'Resource';
                $data[] = self::INDENT . '\'' . $methodName . '\' => new ' . $relationResourceName . '($this->whenLoaded(\'' . $methodName . '\')),';
            }
        }

        $data[] = '        ];';

        return implode(PHP_EOL, $data);
    }
}
