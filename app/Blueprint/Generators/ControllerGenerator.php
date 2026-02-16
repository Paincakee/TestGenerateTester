<?php

namespace App\Blueprint\Generators;

use Blueprint\Models\Controller;

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

        return $this->addMethodDocblocks($generated);
    }

    private function addMethodDocblocks(string $content): string
    {
        return (string) preg_replace_callback(
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
