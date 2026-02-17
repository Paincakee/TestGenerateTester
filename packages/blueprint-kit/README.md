# Blueprint Kit

Reusable Blueprint setup for Laravel API projects.

## Provides

- Custom generators (controller/model/factory/route/request/resource)
- Commands:
  - `bp:snapshot`
  - `bp:delta`
  - `bp:smart`
  - `bp:routes:sync`
- Publishable config: `config/blueprint.php`
- Publishable stubs: `stubs/blueprint`

## Install in another project

Add a local path repository and require dev dependencies:

```json
"repositories": [
  {
    "type": "path",
    "url": "../TestGenerateTester/packages/blueprint-kit",
    "options": { "symlink": true }
  }
],
"require-dev": {
  "laravel-shift/blueprint": "^2.13",
  "richardvullings/blueprint-kit": "*"
}
```

Then run:

```bash
composer update
php artisan vendor:publish --tag=blueprint-kit-config
php artisan vendor:publish --tag=blueprint-kit-stubs
```
