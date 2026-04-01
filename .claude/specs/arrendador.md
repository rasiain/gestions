# Modul Arrendador amb relacio polimorfica i ComunitatBens

## Context
Cal modelar l'arrendador d'un lloguer (qui rep les rendes). L'arrendador pot ser una Persona (ja existent) o una ComunitatBens (nou model). S'utilitza una relacio polimorfica (morphTo) a la taula `g_arrendadors` per referenciar qualsevol dels dos tipus. Cada lloguer tindra opcionalment un arrendador vinculat.

## Canvis

### Fitxers nous

#### Backend

- `src/database/migrations/2026_04_01_000001_create_g_comunitats_bens_table.php`
  Taula `g_comunitats_bens`: id, nom (string 255), nif (string 20 unique), timestamps.

- `src/database/migrations/2026_04_01_000002_create_g_arrendadors_table.php`
  Taula `g_arrendadors`: id, arrendadorable_type (string), arrendadorable_id (unsignedBigInteger), adreca (string 255 nullable), timestamps.
  Index morph sobre (arrendadorable_type, arrendadorable_id).

- `src/database/migrations/2026_04_01_000003_add_arrendador_id_to_g_lloguers.php`
  Afegir columna `arrendador_id` (foreignId nullable, constrained a g_arrendadors, nullOnDelete) a `g_lloguers`.

- `src/app/Models/ComunitatBens.php`
  Model amb `$table = 'g_comunitats_bens'`, fillable: nom, nif.
  Relacio `arrendadors(): MorphMany` cap a Arrendador.

- `src/app/Models/Arrendador.php`
  Model amb `$table = 'g_arrendadors'`, fillable: arrendadorable_type, arrendadorable_id, adreca.
  Relacio `arrendadorable(): MorphTo`.
  Relacio `lloguers(): HasMany`.

- `src/app/Http/Controllers/ComunitatBensController.php`
  Controller resource (index, store, update, destroy) seguint el patro de ProveidorController.
  index: renderitza `ComunitatsBens/Index` amb la llista de comunitats.

- `src/app/Http/Requests/ComunitatBensRequest.php`
  Validacio: nom required string max:255, nif nullable string max:20 unique:g_comunitats_bens,nif (ignorant el registre actual en update).
  Missatges en catala.

- `src/resources/js/Pages/ComunitatsBens/Index.vue`
  Pagina CRUD inline amb modal, seguint exactament el patro de `Proveidors/Index.vue`.
  Taula amb columnes: Nom, NIF.
  Color indigo (com proveidors, es un cataleg).

#### Frontend arrendadors (dins Lloguers)

- `src/app/Http/Controllers/ArrendadorController.php`
  Controller amb store, update, destroy. Les rutes seran niuades o independents.
  store: rep arrendadorable_type ('persona' o 'comunitat_bens'), arrendadorable_id, adreca.
  Mapeja el type a la classe completa del model (App\Models\Persona o App\Models\ComunitatBens).
  Valida que el arrendadorable_id existeixi a la taula corresponent.

- `src/app/Http/Requests/ArrendadorRequest.php`
  Validacio: arrendadorable_type required in:persona,comunitat_bens. arrendadorable_id required integer. adreca nullable string max:255.
  Regla custom per validar existencia del arrendadorable_id segons el type.

### Fitxers a modificar

#### Backend

- `src/app/Models/Lloguer.php`
  Afegir `arrendador_id` al $fillable.
  Afegir relacio `arrendador(): BelongsTo` cap a Arrendador.

- `src/app/Models/Persona.php`
  Afegir relacio `arrendadors(): MorphMany` cap a Arrendador.

- `src/app/Http/Controllers/LloguerController.php`
  A `index()`: carregar `arrendador.arrendadorable` amb eager loading.
  Afegir arrendador i arrendadorable al map de cada lloguer.
  Passar les llistes d'arrendadors (amb arrendadorable carregat), persones i comunitats_bens a la vista.

- `src/app/Http/Requests/LloguerRequest.php`
  Afegir regla: `arrendador_id` nullable integer exists:g_arrendadors,id.

- `src/routes/web.php`
  Afegir `Route::resource('comunitats-bens', ComunitatBensController::class)->only(['index', 'store', 'update', 'destroy'])`.
  Afegir rutes per arrendadors: `Route::resource('arrendadors', ArrendadorController::class)->only(['store', 'update', 'destroy'])`.

- `src/resources/js/Pages/Lloguers/Index.vue`
  Afegir interface Arrendador amb id, adreca, arrendadorable_type, arrendadorable (Persona | ComunitatBens).
  Afegir arrendador_id al lloguerForm.
  A les props: rebre arrendadors, persones, comunitats_bens.
  Al modal de lloguer: afegir selector d'arrendador (desplegable amb les opcions existents).
  Afegir un mini-formulari inline (o petit modal) per crear un arrendador nou directament des de la pagina de lloguers:
    - Selector de tipus (Persona / Comunitat de Bens)
    - Selector de la persona o comunitat (segons el tipus triat)
    - Camp d'adreca
    - Boto crear que crida POST /arrendadors
  Mostrar l'arrendador associat a cada lloguer a la taula o al panell de detall.

- `src/resources/js/Pages/Dashboard.vue`
  Afegir enllac a "Comunitats de Bens" al dashboard (seguint el patro dels altres moduls).

## Ordre d'implementacio

1. **Migracio i model ComunitatBens** -- crear taula, model, request, controller, rutes i pagina Vue.
2. **Migracio i model Arrendador** -- crear taula g_arrendadors, model amb morphTo.
3. **Migracio Lloguer** -- afegir arrendador_id a g_lloguers.
4. **Actualitzar model Lloguer** -- afegir fillable i relacio belongsTo.
5. **Actualitzar model Persona** -- afegir relacio morphMany.
6. **Controller i Request ArrendadorController** -- CRUD per arrendadors.
7. **Actualitzar rutes** -- afegir les noves rutes.
8. **Actualitzar LloguerController** -- passar arrendadors, persones, comunitats a la vista.
9. **Actualitzar LloguerRequest** -- validacio arrendador_id.
10. **Actualitzar Lloguers/Index.vue** -- selector d'arrendador al modal de lloguer i mini-formulari per crear-ne un de nou.
11. **Actualitzar Dashboard.vue** -- enllac a Comunitats de Bens.
12. **Compilar frontend** -- `docker compose exec app npm run build`

## Verificacio

- Accedir a `/comunitats-bens` i fer CRUD complet (crear, editar, eliminar).
- Accedir a `/lloguers`, obrir el modal de lloguer i poder seleccionar un arrendador existent o crear-ne un de nou (tant de tipus Persona com ComunitatBens).
- Verificar que l'arrendador es mostra al llistat/detall del lloguer.
- Executar `docker compose exec app php artisan migrate` sense errors.
- Executar `docker compose exec app npm run build` sense errors.
- Comprovar que la relacio polimorfica funciona: un arrendador pot referenciar una Persona o una ComunitatBens.
