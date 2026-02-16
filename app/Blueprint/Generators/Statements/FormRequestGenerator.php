<?php

namespace App\Blueprint\Generators\Statements;

use Blueprint\Models\Controller;
use Blueprint\Models\Statements\ValidateStatement;
use Blueprint\Tree;

class FormRequestGenerator extends \Blueprint\Generators\Statements\FormRequestGenerator
{
    public function output(Tree $tree): array
    {
        $this->tree = $tree;

        $stub = $this->filesystem->stub('request.stub');

        /** @var Controller $controller */
        foreach ($tree->controllers() as $controller) {
            foreach ($controller->methods() as $method => $statements) {
                foreach ($statements as $statement) {
                    if (! $statement instanceof ValidateStatement) {
                        continue;
                    }

                    $context = \Illuminate\Support\Str::singular($controller->prefix());
                    $name = $this->getName($context, $method);
                    $path = $this->getStatementPath($controller, $name);

                    $exists = $this->filesystem->exists($path);
                    $this->create($path, $this->populateStub($stub, $name, $context, $statement, $controller));
                    $this->output[$exists ? 'updated' : 'created'][] = ['Form Request', $path];
                }
            }
        }

        return $this->output;
    }
}

