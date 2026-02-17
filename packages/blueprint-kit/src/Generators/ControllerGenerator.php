<?php

namespace RichardVullings\BlueprintKit\Generators;

use Blueprint\Models\Controller;
use Illuminate\Support\Str;

class ControllerGenerator extends \Blueprint\Generators\ControllerGenerator
{
    protected function populateStub(string $stub, Controller $controller): string
    {
        $generated = parent::populateStub($stub, $controller);

        $generated = str_replace(
            'Illuminate\Http\Resources\Json\ResourceCollection',
            'Illuminate\Http\Resources\Json\AnonymousResourceCollection',
            $generated
        );

        $generated = preg_replace(
            '/(?<!Anonymous)\bResourceCollection\b/',
            'AnonymousResourceCollection',
            $generated
        );

        $generated = $this->ensureImport($generated, 'Illuminate\\Foundation\\Auth\\Access\\AuthorizesRequests');
        $generated = $this->ensureImport($generated, 'Spatie\\QueryBuilder\\QueryBuilder');
        $generated = $this->ensureAuthorizesRequestsTrait($generated);
        $generated = $this->ensureAuthorizeResourceConstructor($generated, $controller);
        $generated = $this->ensureQueryBuilderIndex($generated, $controller);
        $generated = $this->useResourceMakeSyntax($generated);

        return $this->addMethodDocblocks($generated);
    }

    private function useResourceMakeSyntax(string $content): string
    {
        return preg_replace(
            '/return\s+new\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*Resource)\(([^;]+)\);/',
            'return $1::make($2);',
            $content
        );
    }

    private function ensureImport(string $content, string $import): string
    {
        if (str_contains($content, 'use ' . $import . ';')) {
            return $content;
        }

        return preg_replace(
            '/^namespace\s+[^\n]+;\R\R/m',
            '$0use ' . $import . ';' . PHP_EOL,
            $content,
            1
        );
    }

    private function ensureAuthorizesRequestsTrait(string $content): string
    {
        if (str_contains($content, 'use AuthorizesRequests;')) {
            return $content;
        }

        return preg_replace(
            '/(class\s+[^{]+\{\R)/m',
            '$1    use AuthorizesRequests;' . PHP_EOL . PHP_EOL,
            $content,
            1
        );
    }

    private function ensureAuthorizeResourceConstructor(string $content, Controller $controller): string
    {
        if (str_contains($content, '$this->authorizeResource(')) {
            return $content;
        }

        $modelName = Str::studly(Str::singular($controller->prefix()));
        $modelVariable = Str::camel(Str::singular($controller->prefix()));

        $constructor = implode(PHP_EOL, [
            '    public function __construct()',
            '    {',
            '        $this->authorizeResource(' . $modelName . '::class, \'' . $modelVariable . '\');',
            '    }',
            '',
        ]);

        return (string) preg_replace(
            '/(use AuthorizesRequests;\R\R)/',
            '$1' . $constructor,
            $content,
            1
        );
    }

    private function ensureQueryBuilderIndex(string $content, Controller $controller): string
    {
        if (!str_contains($content, 'public function index(Request $request): AnonymousResourceCollection')) {
            return $content;
        }

        if (str_contains($content, 'QueryBuilder::for(')) {
            return $content;
        }

        $modelName = Str::studly(Str::singular($controller->prefix()));
        $resourceName = $modelName . 'Resource';
        $itemsVariable = Str::camel(Str::pluralStudly($modelName));
        $tableName = Str::snake(Str::pluralStudly($modelName));

        $replacement = implode(PHP_EOL, [
            '        $query = QueryBuilder::for(' . $modelName . '::class, $request)',
            '            ->allowedFilters([',
            '            ])',
            '            ->allowedSorts([',
                '                \'created_at\',',
            '            ])',
            '            ->select(\'' . $tableName . '.*\');',
            '',
            '        $query->search($request->string(\'search\'));',
            '',
            '        $' . $itemsVariable . ' = $query->paginate(',
            '            $request->integer(\'itemsPerPage\', 10),',
            '            page: $request->integer(\'page\'),',
            '        );',
            '',
            '        return ' . $resourceName . '::collection($' . $itemsVariable . ');',
        ]);

        return preg_replace(
            '/' . preg_quote('        $' . $itemsVariable . ' = ' . $modelName . '::all();', '/') . '\R\R' .
            preg_quote('        return ' . $resourceName . '::collection($' . $itemsVariable . ');', '/') . '/',
            $replacement,
            $content,
            1
        );
    }

    private function addMethodDocblocks(string $content): string
    {
        return preg_replace_callback(
            '/(?:^[ \t]*\/\*\*[\s\S]*?\*\/\R)?^([ \t]*)public function\s+([a-zA-Z_]\w*)\(([^)]*)\)(?:\s*:\s*([^{\n]+))?\s*\{/m',
            function (array $matches): string {
                $indent = $matches[1];
                $name = $matches[2];
                $params = trim($matches[3]);
                $returnType = isset($matches[4]) ? trim($matches[4]) : '';

                $docLines = [$indent . '/**'];

                if ($params !== '') {
                    foreach ($this->parseParams($params) as $param) {
                        $docLines[] = $indent . ' * @param ' . $param;
                    }
                    $docLines[] = $indent . ' *';
                }

                if ($name === '__construct') {
                    $docLines[] = $indent . ' * @return void';
                } else {
                    $docLines[] = $indent . ' * @return ' . ($returnType !== '' ? $returnType : 'mixed');
                }

                $docLines[] = $indent . ' */';
                $docblock = implode(PHP_EOL, $docLines);

                return $docblock . PHP_EOL
                    . $indent . 'public function ' . $name . '(' . $params . ')'
                    . ($returnType !== '' ? ': ' . $returnType : '')
                    . PHP_EOL
                    . $indent . '{';
            },
            $content
        );
    }

    /**
     * @return array<int, string>
     */
    private function parseParams(string $params): array
    {
        $parts = array_filter(array_map('trim', explode(',', $params)));
        $normalized = [];

        foreach ($parts as $part) {
            $part = preg_replace('/\s*=\s*.+$/', '', $part) ?? $part;
            $part = preg_replace('/\s+/', ' ', trim($part)) ?? trim($part);

            if (str_contains($part, '$')) {
                $normalized[] = $part;
            }
        }

        return $normalized;
    }
}
