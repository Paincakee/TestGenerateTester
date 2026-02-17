<?php

namespace RichardVullings\BlueprintKit\Generators;

use Blueprint\Models\Controller;
use Blueprint\Tree;
use Illuminate\Support\Str;

class RouteGenerator extends \Blueprint\Generators\RouteGenerator
{
    public function output(Tree $tree): array
    {
        if (empty($tree->controllers())) {
            return [];
        }

        $updated = [];
        $apiControllers = [];
        $webRoutes = '';

        /** @var Controller $controller */
        foreach ($tree->controllers() as $controller) {
            if ($controller->isApiResource()) {
                $apiControllers[] = $controller;
                continue;
            }

            $webRoutes .= PHP_EOL . PHP_EOL . $this->buildRoutes($controller);
        }

        if ($apiControllers !== []) {
            $this->setupApiRouter();
            $apiPath = 'routes/api.php';
            $this->filesystem->put($apiPath, $this->buildApiContent($apiControllers) . PHP_EOL);
            $updated[] = ['Route', $apiPath];
        }

        if (trim($webRoutes) !== '') {
            $webPath = 'routes/web.php';
            $this->filesystem->append($webPath, trim($webRoutes) . PHP_EOL);
            $updated[] = ['Route', $webPath];
        }

        return $updated === [] ? [] : ['updated' => $updated];
    }

    protected function getClassName(Controller $controller): string
    {
        return class_basename($controller->fullyQualifiedClassName()) . '::class';
    }

    /**
     * @param array<int, Controller> $controllers
     */
    private function buildApiContent(array $controllers): string
    {
        $imports = ['Illuminate\\Support\\Facades\\Route'];
        $routeLines = [];

        foreach ($controllers as $controller) {
            $imports[] = $controller->fullyQualifiedClassName();

            $lines = preg_split('/\R/', $this->buildRoutes($controller)) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $routeLines[] = $line;
            }
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        $routeLines = array_values(array_unique($routeLines));
        sort($routeLines);

        $content = "<?php\n\n";
        foreach ($imports as $import) {
            $content .= 'use ' . $import . ';' . PHP_EOL;
        }

        $content .= PHP_EOL;
        $content .= "Route::middleware(['auth:sanctum'])->group(function () {" . PHP_EOL;

        foreach ($routeLines as $line) {
            $content .= '    ' . $line . PHP_EOL;
        }

        $content .= '});';

        return $content;
    }
}

