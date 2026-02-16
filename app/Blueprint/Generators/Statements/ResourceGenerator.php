<?php

namespace App\Blueprint\Generators\Statements;

use Blueprint\Models\Controller;
use Blueprint\Models\Statements\ResourceStatement;
use Blueprint\Tree;

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
}

