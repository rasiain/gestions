# Importació de Categories des de KMyMoney

## Descripció

Sistema per importar categories des de fitxers de KMyMoney en format QIF, permetent carregar automàticament l'estructura jeràrquica de categories d'ingressos i despeses per a cada compte corrent.

## Característiques principals

- **Format QIF**: Importació des de fitxers de text exportats per KMyMoney
- **Estructura jeràrquica**: Suport per categories amb múltiples nivells de profunditat
- **Separació per tipus**: Categories d'Ingressos (I) i Despeses (E)
- **Flux en dos passos**: Parse (previsualització) i Import (confirmació)
- **Validació prèvia**: Comprova categories existents i adverteix de duplicats
- **Eliminació selectiva**: Permet eliminar categories importades per compte o globalment

## Funcionalitats

### Selector de Compte Corrent

- Dropdown per seleccionar el compte al qual importar
- Obligatori per poder processar el fitxer
- Les categories s'assignen automàticament al compte seleccionat

### Pujada i Anàlisi de Fitxer

- Suport per TXT, CSV, QIF (màxim 10MB)
- Endpoint `/maintenance/categories/import/parse` per analitzar
- Retorna previsualització amb estadístiques i validacions

### Previsualització de Categories

- Mostra l'arbre jeràrquic d'Ingressos i Despeses
- Indica el nombre total de categories a importar
- Mostra errors i advertències de validació
- Adverteix de categories que ja existeixen (duplicats)

### Importació Final

- Endpoint `/maintenance/categories/import` per executar
- Només crea categories noves (skip de duplicats)
- Retorna estadístiques de creació i categories omeses

### Eliminació de Categories Importades

Nova funcionalitat per eliminar categories importades:

#### Eliminació per Compte Específic
- Elimina totes les categories del compte seleccionat
- **Preserva** les categories arrel "Ingressos" i "Despeses"
- Elimina totes les subcategories importades

#### Eliminació Global (Tots els Comptes)
- Elimina totes les categories de **TOTS** els comptes corrents
- **Preserva** les categories arrel "Ingressos" i "Despeses" de cada compte
- **Reset de l'autoincrement**: La taula `g_categories` reinicia el contador `id` al valor mínim disponible
- Requereix confirmació explícita amb checkbox
- **Advertència de perill**: Mostra missatge en vermell indicant la irreversibilitat de l'acció

## Format de Fitxer KMyMoney (QIF)

### Estructura general

```
!Type:Cat
N[Parent:Child1:Child2]
E
^
```

- **N**: Nom de la categoria amb path jeràrquic separat per `:`
- **E**: Despesa (Expense)
- **I**: Ingrés (Income) - s'indica amb el prefix al path
- **^**: Fi de registre

### Exemples

**Categoria arrel:**
```
!Type:Cat
NIngressos
I
^
```

**Subcategoria:**
```
!Type:Cat
NIngressos:Salari
I
^
```

**Categoria amb múltiples nivells:**
```
!Type:Cat
NDespeses:Casa:Electricitat
E
^
```

## Base de Dades

### Taula: `g_categories`

Les categories importades s'insereixen a la taula estàndard de categories:

```sql
CREATE TABLE g_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    compte_corrent_id INTEGER NOT NULL,
    nom VARCHAR(100) NOT NULL,
    categoria_pare_id INTEGER NULL,
    ordre TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (compte_corrent_id) REFERENCES g_comptes_corrents(id) ON DELETE CASCADE,
    FOREIGN KEY (categoria_pare_id) REFERENCES g_categories(id) ON DELETE CASCADE
);
```

### Reset de l'Autoincrement

Quan s'eliminen totes les categories de tots els comptes, el sistema reinicia l'autoincrement:

```sql
-- Obtenir el màxim ID existent
SELECT MAX(id) FROM g_categories;

-- Esborrar la seqüència
DELETE FROM sqlite_sequence WHERE name='g_categories';

-- Establir seqüència al valor màxim (el proper INSERT obtindrà maxId + 1)
INSERT INTO sqlite_sequence (name, seq) VALUES ('g_categories', ?);
```

Això assegura que els propers IDs seran sempre superiors als existents, evitant conflictes amb les categories arrel preservades ("Ingressos" i "Despeses").

## Models i Serveis

### KMyMoneyCategoryParserService

**Ubicació**: `app/Http/Services/Categories/KMyMoneyCategoryParserService.php`

**Responsabilitats**:
- Parsejar fitxers QIF de KMyMoney
- Convertir paths jeràrquics (`Parent:Child`) en estructura d'array
- **Resoldre referències curtes**: KMyMoney permet referenciar categories amb noms curts (ex: `PARKINGS:APARCAMENT` en lloc de `Serveis:PARKINGS:APARCAMENT`)
- Generar representació jeràrquica per previsualització
- **Suportar jerarquies de profunditat il·limitada**

**Mètodes principals**:

```php
public function parse(string $content): array
{
    // Retorna array plà amb:
    // ['name' => 'CATEGORIA', 'type' => 'I'/'E', 'parent_path' => 'Parent:Path',
    //  'full_path' => 'Full:Path:Category', 'level' => 2]
}

public function toHierarchical(array $categories): array
{
    // Converteix array pla a estructura jeràrquica per previsualització
}

private function addCategoryToTree(array &$categories, string $hierarchy, string $type): void
{
    // Resolució de referències curtes en dos passos:
    // 1. Reconstrueix el path complet resolent referències al nameToFullPathMap
    // 2. Crea totes les categories intermitges del path reconstruït
}
```

**Resolució de referències curtes**:

Quan KMyMoney defineix:
```
NServeis:PARKINGS
E
^
```

I després referencia:
```
NPARKINGS:APARCAMENT HOSPITAL
E
^
```

El parser:
1. Detecta que `PARKINGS` està al mapa com `Serveis:PARKINGS`
2. Reconstrueix el path complet: `Serveis:PARKINGS:APARCAMENT HOSPITAL`
3. Crea totes les categories intermitges: `Serveis`, `Serveis:PARKINGS`, `Serveis:PARKINGS:APARCAMENT HOSPITAL`

### CategoryImportService

**Ubicació**: `app/Http/Services/Categories/CategoryImportService.php`

**Responsabilitats**:
- Validar categories abans d'importar
- Detectar duplicats
- Importar categories creant l'estructura jeràrquica a la base de dades
- **Crear automàticament categories arrel**: Si les categories "Ingressos" i "Despeses" no existeixen per al compte corrent, es creen automàticament

**Mètodes principals**:

```php
public function ensureRootCategories(int $compteCorrentId): void
{
    // Crea automàticament "Ingressos" i "Despeses" si no existeixen
}

public function validate(array $categories, int $compteCorrentId, string $type): array
{
    // Retorna: ['valid' => bool, 'errors' => [], 'warnings' => []]
}

public function import(array $categories, int $compteCorrentId, string $type): array
{
    // Retorna: ['created' => int, 'skipped' => int, 'errors' => []]
}
```

### Lògica de creació jeràrquica

El servei processa cada categoria per nivells:

1. **Assegura existència de categories arrel**: Crea automàticament "Ingressos" i "Despeses" si no existeixen
2. Busca la categoria arrel del tipus (Ingressos/Despeses)
3. Per cada path jeràrquic (ex: `Casa:Electricitat`):
   - Processa nivell per nivell
   - Busca si ja existeix la categoria amb el mateix nom i pare
   - Si existeix, la reutilitza com a pare pel següent nivell
   - Si no existeix, la crea

### CategoryDeletionService

**Ubicació**: `app/Http/Services/Categories/CategoryDeletionService.php`

**Responsabilitats**:
- Eliminar categories importades per compte específic o globalment
- Preservar categories arrel ("Ingressos" i "Despeses")
- Reiniciar l'autoincrement quan s'eliminen totes les categories

**Mètodes públics**:

```php
public function deleteForCompteCorrent(int $compteCorrentId): array
{
    // Elimina categories d'un compte específic, preservant "Ingressos" i "Despeses"
    // Retorna: ['deleted_count' => int]
}

public function deleteAll(): array
{
    // Elimina totes les categories de tots els comptes
    // Preserva "Ingressos" i "Despeses" de cada compte
    // Reinicia l'autoincrement al valor mínim
    // Retorna: ['deleted_count' => int, 'autoincrement_reset_to' => int]
}

public function getPreservedRootCategories(?int $compteCorrentId = null)
{
    // Retorna els IDs de les categories arrel que es preservaran
}

public function countDeletableForCompteCorrent(int $compteCorrentId): int
{
    // Compta quantes categories s'eliminarien per un compte
}

public function countDeletableGlobally(): int
{
    // Compta quantes categories s'eliminarien globalment
}
```

**Mètodes privats**:

```php
private function resetAutoincrement(): int
{
    // Reinicia la seqüència d'autoincrement a SQLite
    // Utilitza MAX(id) en lloc de MIN(id) per evitar conflictes
    // Retorna el nou valor màxim d'autoincrement
}
```

**Fix important (2025-12-08)**:
El mètode `resetAutoincrement()` inicialment utilitzava `MIN(id)` i establia la seqüència a `$minId - 1`, cosa que causava conflictes amb els IDs existents. Ara utilitza `MAX(id)` per assegurar que el proper ID inserit serà sempre superior a tots els existents.

**Avantatges d'aquesta arquitectura**:
- **Reutilitzable**: El servei es pot utilitzar des de diferents controllers o jobs
- **Testable**: La lògica de negoci es pot testejar independentment
- **Mantenible**: Canvis a la lògica d'eliminació es fan en un sol lloc
- **Separació de responsabilitats**: El controller gestiona HTTP, el servei gestiona lògica de negoci

## Controller

### CategoryImportController

**Ubicació**: `app/Http/Controllers/CategoryImportController.php`

**Mètodes**:

#### `index(): Response`
Mostra la pàgina d'importació amb llista de comptes corrents.

#### `parse(Request): JsonResponse`
Analitza el fitxer carregat i retorna previsualització:

**Validació**:
```php
'file' => 'required|file|mimes:txt,csv,qif|max:10240',
'compte_corrent_id' => 'required|integer|exists:g_comptes_corrents,id',
```

**Resposta**:
```json
{
    "success": true,
    "data": {
        "total_categories": 150,
        "total_ingressos": 20,
        "total_despeses": 130,
        "categories_ingressos": [ /* estructura jeràrquica */ ],
        "categories_despeses": [ /* estructura jeràrquica */ ],
        "validation": {
            "valid": true,
            "errors": [],
            "warnings": ["La categoria 'Casa' ja existeix"]
        }
    }
}
```

#### `import(Request): JsonResponse`
Importa les categories a la base de dades:

**Validació**: Mateixa que `parse()`

**Resposta**:
```json
{
    "success": true,
    "message": "Categories importades correctament",
    "data": {
        "stats": {
            "ingressos": {
                "created": 15,
                "skipped": 5,
                "errors": []
            },
            "despeses": {
                "created": 100,
                "skipped": 30,
                "errors": []
            },
            "total_created": 115,
            "total_skipped": 35
        }
    }
}
```

#### `deleteImported(Request): JsonResponse`
Elimina categories importades amb opcions de scope.

**Validació**:
```php
'compte_corrent_id' => 'nullable|integer|exists:g_comptes_corrents,id',
'confirmed' => 'required|boolean|accepted',
```

**Comportament**:

- **Si `compte_corrent_id` està present**: Delega a `CategoryDeletionService::deleteForCompteCorrent()`
- **Si `compte_corrent_id` és NULL**: Delega a `CategoryDeletionService::deleteAll()`

**Flux d'execució**:
```php
DB::beginTransaction();

if ($compteCorrentId) {
    $result = $this->categoryDeletion->deleteForCompteCorrent($compteCorrentId);
} else {
    $result = $this->categoryDeletion->deleteAll();
}

DB::commit();
```

El controller és responsable de:
- Validar la petició HTTP
- Gestionar transaccions de base de dades
- Formatar la resposta JSON
- Gestionar errors i logging

La lògica de negoci (quin eliminar, com preservar arrels, reset autoincrement) està encapsulada en `CategoryDeletionService`.

**Resposta (compte específic)**:
```json
{
    "success": true,
    "message": "S'han eliminat 45 categories del compte seleccionat",
    "data": {
        "deleted_count": 45
    }
}
```

**Resposta (global)**:
```json
{
    "success": true,
    "message": "S'han eliminat 250 categories de tots els comptes i s'ha reiniciat l'autoincrement",
    "data": {
        "deleted_count": 250,
        "autoincrement_reset_to": 3
    }
}
```

## Vista Vue

**Ubicació**: `resources/js/Pages/Maintenance/CategoryImport.vue`

### Interfícies TypeScript

```typescript
interface CompteCorrent {
    id: number;
    compte_corrent: string;
    nom: string | null;
    entitat: string;
    ordre: number;
}

interface CategoryNode {
    name: string;
    type: string;
    level: number;
    children: CategoryNode[];
}

interface ValidationResult {
    valid: boolean;
    errors: string[];
    warnings: string[];
}

interface ParsedData {
    total_categories: number;
    total_ingressos: number;
    total_despeses: number;
    categories_ingressos: CategoryNode[];
    categories_despeses: CategoryNode[];
    validation: ValidationResult;
}
```

### Funcions principals

**`parseFile()`**: Analitza el fitxer sense importar-lo
**`importCategories()`**: Confirma i importa les categories
**`resetForm()`**: Neteja el formulari
**`renderCategoryTree()`**: Renderitza l'arbre jeràrquic com a text pla
**`openDeleteModal()`**: Obre el modal de confirmació d'eliminació
**`deleteImportedCategories()`**: Executa l'eliminació de categories

### Modal d'Eliminació

El modal mostra missatges diferents segons si hi ha un compte seleccionat:

**Amb compte seleccionat**:
> Aquesta acció eliminarà totes les categories importades per al compte corrent seleccionat, excepte les categories arrel "Ingressos" i "Despeses".

**Sense compte (global)**:
> ⚠️ PERILL: Aquesta acció eliminarà totes les categories importades de TOTS els comptes corrents, excepte les categories arrel "Ingressos" i "Despeses". A més, es reiniciarà l'autoincrement de la taula al valor mínim.

**Confirmació requerida**:
- Checkbox: "Confirmo que vull eliminar les categories"
- El botó "Eliminar" només s'activa si el checkbox està marcat

## Rutes

```php
// Visualitzar pàgina d'importació
Route::get('/maintenance/categories/import',
    [CategoryImportController::class, 'index'])
    ->name('maintenance.categories.import');

// Analitzar fitxer (previsualització)
Route::post('/maintenance/categories/import/parse',
    [CategoryImportController::class, 'parse'])
    ->name('maintenance.categories.import.parse');

// Importar categories
Route::post('/maintenance/categories/import',
    [CategoryImportController::class, 'import'])
    ->name('maintenance.categories.import.store');

// Eliminar categories importades
Route::delete('/maintenance/categories/import',
    [CategoryImportController::class, 'deleteImported'])
    ->name('maintenance.categories.import.delete');
```

## Flux d'Ús

### Importació

1. **Anar a importació**: `/maintenance/categories/import`
2. **Seleccionar compte corrent**: Dropdown obligatori
3. **Pujar fitxer**: Format QIF de KMyMoney
4. **Analitzar**: Botó "Analitzar fitxer"
5. **Revisar previsualització**:
   - Estadístiques (total, ingressos, despeses)
   - Arbre jeràrquic de categories
   - Errors de validació (impedeixen importació)
   - Advertències (duplicats, categories existents)
6. **Importar**: Botó "Confirmar i importar categories" (només si validació correcta)
7. **Confirmació**: Missatge de categories creades i omeses

### Eliminació

1. **Seleccionar compte** (opcional):
   - Si seleccionat: elimina només del compte
   - Si no seleccionat: elimina de tots els comptes + reset autoincrement
2. **Clic "Eliminar categories importades"**: Obre modal de confirmació
3. **Revisar advertència**:
   - Missatge específic segons scope (compte o global)
   - Advertència de perill si és global
4. **Marcar checkbox**: "Confirmo que vull eliminar les categories"
5. **Clic "Eliminar"**: Executa l'eliminació
6. **Confirmació**: Missatge amb nombre de categories eliminades

## Consideracions tècniques

### Detecció de duplicats

El servei comprova si ja existeix una categoria amb:
- Mateix `nom`
- Mateix `categoria_pare_id`
- Mateix `compte_corrent_id`

Si existeix, la marca com "skipped" i no la crea.

### Creació incremental

Les categories es processen per nivells per assegurar que els pares existeixen abans de crear els fills.

### Transaccions

Tant la importació com l'eliminació utilitzen transaccions de base de dades:

```php
DB::beginTransaction();
try {
    // Operacions
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    throw $e;
}
```

### Eliminació en cascada

Gràcies al constraint `ON DELETE CASCADE` en `categoria_pare_id`, quan s'elimina una categoria, totes les seves subcategories també s'eliminen automàticament.

### Reset d'autoincrement només per SQLite

El codi de reset d'autoincrement està optimitzat per SQLite:

```php
DB::statement("DELETE FROM sqlite_sequence WHERE name='g_categories'");
DB::statement("INSERT INTO sqlite_sequence (name, seq) VALUES ('g_categories', ?)", [$maxId]);
```

Per altres bases de dades (MySQL, PostgreSQL), caldria adaptar aquestes sentències.

**Nota**: Utilitzem `$maxId` en lloc de `$minId - 1` per evitar conflictes amb IDs existents.

## Errors comuns

### Error: "No s'han trobat categories al fitxer"
- El fitxer no conté el format QIF correcte
- Falta la capçalera `!Type:Cat`
- No hi ha registres de categories (N + tipus)

### Error de validació: "La categoria arrel X no existeix"
- ~~Assegura't que les categories "Ingressos" i "Despeses" existeixen al compte~~
- **Resolt**: Des de la versió 2025-12-08, les categories arrel es creen automàticament durant la importació

### Advertència: "La categoria X ja existeix"
- Informativa, no impedeix la importació
- La categoria duplicada serà omesa (skipped)

## Integració amb altres mòduls

Les categories importades es poden utilitzar immediatament per:

- **Gestió manual de categories**: Modificar, eliminar, afegir subcategories
- **Importació de moviments**: Assignar moviments a categories basant-se en el path jeràrquic
- **Classificació de despeses/ingressos**: Filtrar i agrupar moviments per categoria

## Canvis i millores recents

### 2025-12-08 - Fixes importants i millores de visualització

#### Bug fix: Autoincrement reset
**Problema**: El mètode `resetAutoincrement()` utilitzava `MIN(id)` i establia la seqüència a `$minId - 1`, causant conflictes amb IDs de categories preservades.

**Solució**: Canviat a utilitzar `MAX(id)` per assegurar que els propers IDs inserits seran sempre superiors als existents.

**Fitxer modificat**: `src/app/Http/Services/Categories/CategoryDeletionService.php`

#### Creació automàtica de categories arrel
**Millora**: Les categories "Ingressos" i "Despeses" ara es creen automàticament si no existeixen durant la importació.

**Benefici**: Permet importar categories a comptes corrents nous sense haver de crear manualment les categories arrel primer.

**Fitxer modificat**: `src/app/Http/Services/Categories/CategoryImportService.php`
- Nou mètode: `ensureRootCategories()`
- Cridat automàticament per `validate()` i `import()`

#### Fix: Parsing de jerarquies profundes
**Problema**: El parser no creava totes les categories intermitges quan KMyMoney utilitzava referències curtes (ex: `PARKINGS:APARCAMENT` en lloc de `Serveis:PARKINGS:APARCAMENT`).

**Solució**: Reescrit el mètode `addCategoryToTree()` en dos passos:
1. Primera passada: Reconstrueix el path complet resolent referències
2. Segona passada: Crea totes les categories intermitges del path reconstruït

**Resultat**: Ara suporta jerarquies de profunditat il·limitada amb resolució correcta de referències curtes.

**Fitxer modificat**: `src/app/Http/Services/Categories/KMyMoneyCategoryParserService.php`

#### Visualització de tots els nivells jeràrquics
**Problema**: El frontend només carregava 2 nivells de profunditat amb `with('fills.fills')`, impedint veure categories de nivell 3 o superior.

**Solució**: Implementat mètode recursiu `buildCategoryTree()` que construeix la jerarquia completa de categories sense límit de profunditat.

**Benefici**: La pàgina de gestió de categories ara mostra correctament tota la jerarquia, independentment de la profunditat.

**Fitxer modificat**: `src/app/Http/Controllers/CategoriaController.php`
- Nou mètode privat: `buildCategoryTree()`
- Carrega totes les categories amb una sola consulta plana
- Construeix recursivament l'arbre jeràrquic complet

#### Altres millores
- **Favicon**: Afegit favicon amb emoji 💰 per millor identificació de l'aplicació
  - Fitxers: `src/public/favicon.svg`, `src/resources/views/app.blade.php`
- **Docker**: Actualitzat npm a versió 11.6.4
  - Fitxer: `Dockerfile`
