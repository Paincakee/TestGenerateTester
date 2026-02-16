# Blueprint Workflow (Safe for Existing Databases)

Deze workflow voorkomt kapotte migraties, dubbele routes en onverwachte code-overschrijvingen.

## Quick start

1. Maak of kopieer een draft file onder `drafts/`.
2. Draai:

```bash
composer bp:smart -- drafts/<feature>.yaml
```

3. Voeg routes handmatig toe in `routes/api.php`.
4. Draai:

```bash
php artisan migrate
composer test
```

`bp:smart` doet:

- delta-check op toegevoegde kolommen (en maakt indien nodig kleine `add_*_to_*_table` migration)
- daarna automatisch Blueprint build met veilige flags
- standaard zonder controller/test generatie om custom code te beschermen
- met `--full` kun je expliciet controllers/tests mee laten genereren

## Kernregel

- Gebruik Blueprint voor **nieuwe features/tables**.
- Gebruik handmatige Laravel migrations voor **wijzigingen op bestaande tabellen**.
- Gebruik `blueprint:build -m` **niet** nadat migrations al zijn gedraaid.

## Scenario 1: Nieuwe feature/model toevoegen

1. Maak een kleine draft file per feature, bv. `drafts/category.yaml`.
2. Zet alleen de nieuwe onderdelen in die draft.
3. Build gericht:

```bash
composer bp:smart -- drafts/category.yaml
```

4. Voeg routes handmatig toe in `routes/api.php`.
5. Run:

```bash
php artisan migrate
composer test
```

## Scenario 2: Bestaand veld aanpassen (add/rename/drop/type)

Voorbeeld: `comments.body` hernoemen naar `comments.text`.

1. Maak een delta migration:

```bash
php artisan make:migration rename_body_to_text_on_comments_table --table=comments
```

2. Pas alleen de wijziging toe in de migration (`renameColumn`, `addColumn`, etc.).
3. Pas model/request/resource/controller handmatig aan.
4. Run:

```bash
php artisan migrate
composer test
```

Alternatief voor **alleen nieuwe kolommen toevoegen** vanuit draft-diff:

```bash
composer bp:delta -- drafts/<feature>.yaml
```

Gedrag:

- Eerste run: maakt een snapshot (`storage/app/blueprint-delta/<draft>.json`)
- Volgende run na draft-wijziging: maakt automatisch een `add_*_to_*_table` migration voor toegevoegde kolommen

## Scenario 3: Alleen code genereren, geen routes aanpassen

Gebruik altijd:

```bash
composer bp:smart -- <draft-file>
```

Dit voorkomt dubbele `Route::apiResource(...)` regels.

## Scenario 4: Teststijl (Pest)

In `config/blueprint.php`:

- zet test generator op Pest:
  - `'test' => \Blueprint\Generators\PestTestGenerator::class`

Dan:

```bash
composer bp:test -- <draft-file>
```

## Scenario 5: Resource zonder `*Collection` classes

In `config/blueprint.php`:

- zet `'generate_resource_collection_classes' => false`

Dan gebruikt gegenereerde code `CommentResource::collection(...)` i.p.v. aparte `CommentCollection`.

## Verboden in team workflow

- Geen `php artisan blueprint:build -m` op een project waar migrations al zijn uitgevoerd.
- Geen full `blueprint:build` op een grote draft voor kleine wijzigingen.
- Geen automatische route generatie in herhaalbuilds (`composer bp:smart` gebruiken).

## Aanbevolen mapstructuur

```text
drafts/
  post.yaml
  tag.yaml
  comment.yaml
  like.yaml
```

Eén draft per feature houdt builds klein, voorspelbaar en veilig.
