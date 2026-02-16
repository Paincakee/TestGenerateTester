# TestGenerateTester

Deze repo is een Laravel 12 testproject om een veilige, herbruikbare setup te hebben voor **Laravel Blueprint** met **Pest**.

## Doel

Een setup die je in andere projecten kunt kopieren zonder:

- dubbele routes
- onveilige migratie-regeneratie
- inconsistente testgeneratie

## Wat staat hier al goed

- `laravel-shift/blueprint` is geinstalleerd (dev dependency)
- `config/blueprint.php` staat op:
  - Pest testgenerator
  - geen resource collection classes
- Blueprint stubs staan in `stubs/blueprint`
- workflow document staat in `BLUEPRINT_WORKFLOW.md`

## Dagelijkse commando's

Gebruik deze Composer scripts:

```bash
composer bp -- drafts/posts.yaml
composer bp:safe -- drafts/posts.yaml
composer bp:test -- drafts/posts.yaml
composer bp:delta -- drafts/posts.yaml
composer bp:smart -- drafts/posts.yaml
```

Betekenis:

- `bp`: normale Blueprint build
- `bp:safe`: build met `--skip=routes` (aanbevolen)
- `bp:test`: alleen tests genereren
- `bp:delta`: kleine add-column migraties maken op basis van draft-diff (met snapshot)
- `bp:smart`: 1 command flow: eerst delta-check, daarna veilige build zonder controller/test overschrijven

## Aanbevolen manier van werken

1. Maak per feature een losse draft in `drafts/`.
2. Genereer met `composer bp:safe -- drafts/<feature>.yaml`.
3. Voeg routes handmatig toe in `routes/api.php`.
4. Draai `php artisan migrate` en daarna `composer test`.

Voor schema-updates op bestaande tabellen:

1. Eerste keer: snapshot initialiseren.
2. Na draft-wijziging: opnieuw draaien voor een delta migration.

```bash
composer bp:delta -- drafts/<feature>.yaml
```

Volledige generatie (inclusief controllers/tests) alleen bewust gebruiken:

```bash
php artisan bp:smart drafts/<feature>.yaml --full
```

Voor details zie `BLUEPRINT_WORKFLOW.md`.

## Blueprint setup overnemen in je eigen project

Gebruik dit als snelle checklist in je doelproject:

1. Installeer Blueprint:

```bash
composer require --dev laravel-shift/blueprint
php artisan vendor:publish --tag=blueprint-config
php artisan vendor:publish --tag=blueprint-stubs
```

2. Overschrijf met deze projectversie (custom config + stubs):

```bash
cp /pad/naar/TestGenerateTester/config/blueprint.php config/blueprint.php
mkdir -p stubs/blueprint
cp -R /pad/naar/TestGenerateTester/stubs/blueprint/. stubs/blueprint/
```

3. Kopieer workflow + voorbeeld drafts:

```bash
cp /pad/naar/TestGenerateTester/BLUEPRINT_WORKFLOW.md BLUEPRINT_WORKFLOW.md
mkdir -p drafts/examples
cp /pad/naar/TestGenerateTester/drafts/examples/post.yaml drafts/examples/post.yaml
```

4. Neem deze scripts over in `composer.json`:

```json
"bp": [
  "@php artisan blueprint:build"
],
"bp:safe": [
  "@php artisan blueprint:build --skip=routes"
],
"bp:test": [
  "@php artisan blueprint:build --skip=routes --only=tests"
]
```

## Let op

- Gebruik geen `blueprint:build -m` op projecten met bestaande migratie-historie.
- Laat route-generatie uit in herhaalbuilds (`--skip=routes`) om duplicaten te voorkomen.
