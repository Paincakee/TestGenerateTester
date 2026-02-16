<?php

namespace App\Blueprint\Generators;

use Blueprint\Generators\FactoryGenerator as BaseFactoryGenerator;
use Blueprint\Models\Model;
use Blueprint\Tree;

class FactoryGenerator extends BaseFactoryGenerator
{
    public function output(Tree $tree): array
    {
        $this->tree = $tree;
        $stub = $this->filesystem->stub('factory.stub');

        /** @var Model $model */
        foreach ($tree->models() as $model) {
            $this->addImport($model, 'Illuminate\\Database\\Eloquent\\Factories\\Factory');
            $this->addImport($model, $model->fullyQualifiedNamespace().'\\'.$model->name());

            $path = $this->getPath($model);
            $this->create($path, $this->populateStub($stub, $model));
            $this->output['created'][] = ['Factory', $path];
        }

        return $this->output;
    }
}
