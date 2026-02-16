# TestGenerateTester

Laravel 12 testproject met een herbruikbare Blueprint setup voor API-projecten.

## Setup

### 1. Blueprint installeren in een project

```bash
composer require --dev laravel-shift/blueprint
php artisan vendor:publish --tag=blueprint-config
php artisan vendor:publish --tag=blueprint-stubs
```

### 2. In dit project (eerste keer)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

## Commands

### Composer commands

```bash
composer bp:snapshot -- drafts/<feature>.yaml
composer bp:delta -- drafts/<feature>.yaml
composer bp:smart -- drafts/<feature>.yaml
composer bp:smart:full -- drafts/<feature>.yaml
composer bp:routes -- drafts/<feature>.yaml
composer bp:test -- drafts/<feature>.yaml
```

### Uitleg

- `bp:snapshot`: maakt of ververst de snapshot voor een draft (`storage/app/blueprint-delta`).
- `bp:delta`: vergelijkt draft met snapshot en maakt alleen add-column migraties waar nodig.
- `bp:smart`: veilige standaardflow.
  - draait eerst delta-check
  - genereert daarna model/factory/request/resource
  - slaat routes over om dubbele route-regels te voorkomen
- `bp:smart:full`: als `bp:smart`, maar inclusief controller + tests.
- `bp:routes`: voegt alleen ontbrekende `Route::apiResource(...)` regels toe in `routes/api.php`.
- `bp:test`: genereert alleen tests vanuit de draft.

## Aanbevolen workflow

1. Start per nieuwe draft met snapshot:

```bash
composer bp:snapshot -- drafts/<feature>.yaml
```

2. Dagelijks werken met:

```bash
composer bp:smart -- drafts/<feature>.yaml
```

3. Alleen als je controllers/tests bewust wilt regenereren (eerste run):

```bash
composer bp:smart:full -- drafts/<feature>.yaml
```

4. Routes synchroniseren:

```bash
composer bp:routes -- drafts/<feature>.yaml
```

## Blueprint setup overnemen in je eigen project

### Basis (vendor publish)

```bash
composer require --dev laravel-shift/blueprint
php artisan vendor:publish --tag=blueprint-config
php artisan vendor:publish --tag=blueprint-stubs
```

### Deze projectsetup kopieren

Kopieer deze onderdelen uit `TestGenerateTester` naar je doelproject:

```bash
cp /pad/naar/TestGenerateTester/config/blueprint.php config/blueprint.php
mkdir -p stubs/blueprint
cp -R /pad/naar/TestGenerateTester/stubs/blueprint/. stubs/blueprint/

mkdir -p app/Blueprint/Generators
cp -R /pad/naar/TestGenerateTester/app/Blueprint/Generators/. app/Blueprint/Generators/

cp /pad/naar/TestGenerateTester/app/Support/BlueprintWorkflow.php app/Support/BlueprintWorkflow.php
cp /pad/naar/TestGenerateTester/routes/console.php routes/console.php
```

### Composer scripts overnemen

Voeg dit toe aan `composer.json` onder `scripts`:

```json
"bp:delta": [
  "@php artisan bp:delta"
],
"bp:snapshot": [
  "@php artisan bp:snapshot"
],
"bp:routes": [
  "@php artisan bp:routes:sync"
],
"bp:smart": [
  "@php artisan bp:smart"
],
"bp:smart:full": [
  "@php artisan bp:smart --full"
]
"bp:test": [
"@php artisan blueprint:build --skip=routes --only=tests"
],
```

## Demo drafts

Voor demo/presentatie staan voorbeelden in:

- `drafts/examples/Member.yaml`
- `drafts/examples/Team.yaml`
- `drafts/examples/Skill.yaml`
- `drafts/examples/TimeEntry.yaml`

## Belangrijk

- Gebruik `bp:smart` als default command.
- Gebruik `bp:smart:full` als 1ste keer ipv `bp:smart`.
- Gebruik `bp:snapshot` minimaal 1 keer per draft voordat je delta-gedrag verwacht.
- Vermijd volledige route-regeneratie; gebruik `bp:routes` om alleen ontbrekende routes toe te voegen.
