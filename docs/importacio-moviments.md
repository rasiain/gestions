# Importació de Moviments Bancaris

## Descripció

Sistema d'importació de moviments bancaris des de tres formats diferents: fitxers XLS de Caixa d'Enginyers i CaixaBank, i fitxers QIF de KMyMoney. Inclou validació de saldos, detecció de duplicats amb hash, coincidència de categories i flux de previsualització abans de la importació definitiva.

## Característiques principals

- **Tres formats suportats**: Caixa d'Enginyers (XLS), CaixaBank (XLS) i KMyMoney (QIF)
- **Detecció de duplicats**: Hash SHA256 basat en data + concepte + import + compte
- **Validació de saldos**: Compara el saldo calculat amb el del fitxer (error si no coincideix)
- **Coincidència de categories**: Navegació jeràrquica per paths en fitxers QIF
- **Flux en dos passos**: Parse (previsualització) i Import (confirmació)
- **Edició prèvia**: Permet modificar concepte abans d'importar

## Funcionalitats

### Selector de Compte Corrent
- Dropdown per seleccionar el compte al qual importar
- El tipus de banc es dedueix automàticament del nom de l'entitat del compte
- Mostra el tipus de banc deduït

### Pujada i Anàlisi de Fitxer
- Suport per XLS, XLSX, CSV, TXT, QIF (màxim 100MB)
- Endpoint `/maintenance/movements/import/parse` per analitzar
- Retorna previsualització amb estadístiques i validacions
- **Optimització per fitxers grans**: Limita la previsualització als últims 100 moviments (més recents primer)
- **Selector de tipus d'importació**: El front mostra només el tipus corresponent al compte + KMyMoney (QIF)

### Previsualització de Moviments
- Mostra els últims 100 moviments (més recents primer) per rendiment
- Per fitxers grans (>100 moviments), mostra un missatge informatiu indicant que es mostren només els últims 100
- Estadístiques: duplicats, a importar, hash trobat, total de moviments
- Advertència crítica si no es troba hash a la BD
- Errors de validació de saldos (bloqueig d'importació)
- Taula editable amb columna de concepte

### Modes d'Importació
- **Selecció obligatòria**: L'usuari ha de triar el mode abans d'analitzar el fitxer
- **from_beginning**: Importa tots els moviments del fitxer
- **from_last_db**: Continua des de l'última data a la BD
- Flux simplificat en un sol pas: selecciona mode → analitza → importa

### Importació Final
- Endpoint `/maintenance/movements/import` per executar
- Inserció en transaccions amb chunks de 100 registres
- Detecció de duplicats per hash durant la inserció
- Retorna estadístiques (created, skipped)
- Missatge informatiu mostra els duplicats saltats

## Tipus de Banc

El tipus de banc es dedueix automàticament del nom de l'entitat bancària:

- **caixa_enginyers**: Si l'entitat conté "enginyer"
- **caixabank**: Si l'entitat conté "caixabank"
- **kmymoney**: Si l'entitat conté "kmymoney" o "kmoney"

La deducció es fa mitjançant l'accessor `getBankTypeAttribute()` del model `CompteCorrent`.

## Formats de Fitxer

### Caixa d'Enginyers (XLS)

**Columnes**: `[data, concepte, ?, import, saldo]`

- Columna 0: Data (DD/MM/YYYY o DD.MM.YYYY)
- Columna 1: Concepte
- Columna 3: Import (negatius = despeses, positius = ingressos)
- Columna 4: Saldo posterior
- Salta la fila de capçalera (detecta "Data")

### CaixaBank (XLS)

**Columnes**: `[data, ?, concepte, notes, import, saldo]`

- Columna 0: Data (DD/MM/YYYY o DD.MM.YYYY)
- Columna 2: Concepte
- Columna 3: Notes (es combinen amb concepte si ambdós presents)
- Columna 4: Import (negatius = despeses, positius = ingressos)
- Columna 5: Saldo posterior
- Salta la fila de capçalera (detecta "Data")

### KMyMoney (QIF)

**Format QIF** amb camps:
- `D`: Data (DD/MM/YYYY)
- `T`: Import (negatius = despeses, positius = ingressos)
- `P`: Payee (beneficiari/pagador)
- `M`: Memo (notes addicionals)
- `L`: Category (categoria jeràrquica amb `:` com a separador)
- `^`: Final de registre

**Casos especials**:
- Ignora `Opening Balance` amb `T=0` (registres tècnics)
- Ignora categories entre `[claudàtors]`
- No processa el camp `C` (cleared)
- Combina `P` i `M` per formar el concepte

## Detecció de Duplicats

### Hash SHA256

Genera un hash únic per cada moviment:

```php
$hash = hash('sha256',
    $data_moviment . '|' .
    $import . '|' .
    $compte_corrent_id
);
```

### Cerca de Hash

- Busca el hash a la taula `g_moviments_comptes_corrents`
- Si troba: marca com a duplicat, calcula quants nous moviments hi ha després
- Si no troba: advertència crítica, però permet importar

## Validació de Saldos

### Algoritme

1. Obté l'últim saldo de la BD per al compte (`saldo_posterior`)
2. Per cada moviment del fitxer:
   - Saldo esperat = Saldo anterior + Import
   - Compara amb saldo del fitxer
   - Tolerància: ±0.01€ (arrodoniment)
3. Si no coincideix: afegeix error amb detalls

### Error Crític

Si hi ha errors de validació:
- Retorna `balance_validation_failed: true`
- HTTP 422 Unprocessable Entity
- Mostra errors a la UI
- Bloqueja la importació

## Coincidència de Categories

### Paths Jeràrquics (QIF)

Les categories en QIF usen paths separats per `:`:
```
Compres:Compres Alimentació
Sous:Sou Ricard
```

**Important**: KMyMoney exporta categories sense el prefix root (Ingressos/Despeses).

### Algoritme de Navegació amb Estratègies Múltiples

1. **Prova directa**: Busca el path tal com ve del QIF
2. **Prova amb prefix segons signe**:
   - Si `import < 0` (despesa): prova `Despeses:{path}`, després `Ingressos:{path}`
   - Si `import > 0` (ingrés): prova `Ingressos:{path}`, després `Despeses:{path}`
3. **Navegació jeràrquica**:
   - Divideix el path per `:`
   - Comença des de l'arrel (`categoria_pare_id = NULL`)
   - Per cada nivell:
     - Busca categoria amb `nom` coincident (case-insensitive, uppercase)
     - Dins del `categoria_pare_id` actual
     - Navega al següent nivell
4. Retorna l'ID de la categoria final o `NULL`

### Precàrrega i Cache

- **Precàrrega**: Totes les categories del compte es carreguen en memòria (`preloadCategories()`)
- **Cache de paths**: Array en memòria per evitar consultes repetides del mateix path
- Optimització per fitxers grans amb milers de moviments

## Base de Dades

### Taula: `g_comptes_corrents`

El tipus de banc es dedueix automàticament del camp `entitat` mitjançant l'accessor `getBankTypeAttribute()` del model. No hi ha cap columna `bank_type` a la base de dades.

**Migració anterior eliminada**: `2025_11_30_000000_remove_bank_type_from_g_comptes_corrents_table.php`

### Taula: `g_moviments_comptes_corrents`

```sql
CREATE TABLE g_moviments_comptes_corrents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    compte_corrent_id INTEGER NOT NULL,
    data_moviment DATE NOT NULL,
    concepte VARCHAR(255) NOT NULL,
    import DECIMAL(10,2) NOT NULL,
    saldo_posterior DECIMAL(10,2) NULL,
    categoria_id INTEGER NULL,
    hash_moviment VARCHAR(64) NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (compte_corrent_id) REFERENCES g_comptes_corrents(id) ON DELETE CASCADE,
    FOREIGN KEY (categoria_id) REFERENCES g_categories(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX idx_moviments_hash
ON g_moviments_comptes_corrents(hash_moviment);

CREATE INDEX idx_moviments_lookup
ON g_moviments_comptes_corrents(compte_corrent_id, data_moviment DESC);
```

## Models Eloquent

### CompteCorrent

**Ubicació**: `app/Models/CompteCorrent.php`

**Accessors**:
```php
public function getBankTypeAttribute(): ?string
{
    $entitat = strtolower($this->entitat);

    if (str_contains($entitat, 'enginyer')) {
        return 'caixa_enginyers';
    }

    if (str_contains($entitat, 'caixabank')) {
        return 'caixabank';
    }

    if (str_contains($entitat, 'kmymoney') || str_contains($entitat, 'kmoney')) {
        return 'kmymoney';
    }

    return null;
}
```

### MovimentCompteCorrent

**Ubicació**: `app/Models/MovimentCompteCorrent.php`

**Relacions**:
- `compteCorrent()`: Belongs-to amb CompteCorrent
- `categoria()`: Belongs-to amb Categoria

## Serveis

### AbstractMovementParserService

**Ubicació**: `app/Http/Services/ImportFiles/AbstractMovementParserService.php`

**Classe base abstracta** per tots els parsers.

**Mètodes abstractes**:
- `parse($input, int $compteCorrentId): array`
- `supports(string $bankType): bool`

**Mètodes compartits**:
- `normalizeDate(string $date): string` - Converteix DD/MM/YYYY i DD.MM.YYYY a Y-m-d
- `normalizeAmount(string $amount): float` - Processa decimals amb comes/punts
- `trimConcept(string $concept): string` - Majúscules i trim

### CaixaEnginyersParserService

**Ubicació**: `app/Http/Services/ImportFiles/CaixaEnginyersParserService.php`

**Hereta de**: `AbstractMovementParserService`

**Implementa**: Parsing de fitxers XLS de Caixa d'Enginyers

### CaixaBankParserService

**Ubicació**: `app/Http/Services/ImportFiles/CaixaBankParserService.php`

**Hereta de**: `AbstractMovementParserService`

**Implementa**: Parsing de fitxers XLS de CaixaBank

### KMyMoneyMovementParserService

**Ubicació**: `app/Http/Services/ImportFiles/KMyMoneyMovementParserService.php`

**Hereta de**: `AbstractMovementParserService`

**Implementa**: Parsing de fitxers QIF de KMyMoney

**Casos especials**:
```php
// Salta Opening Balance amb T=0
if (str_contains($payee, 'Opening Balance') && abs($amount) < 0.01) {
    return null;
}

// Ignora categories amb [claudàtors]
if (!str_starts_with($category, '[') && !str_ends_with($category, ']')) {
    $categoryPath = $category;
}
```

### MovementImportService

**Ubicació**: `app/Http/Services/MovementImportService.php`

**Orquestrador principal** del procés d'importació.

**Mètodes clau**:

- `processMovements(array $movements, int $compteCorrentId, ?string $mode = null): array`
  - Genera hashes per cada moviment
  - Cerca duplicats a la BD
  - Calcula nous moviments segons mode
  - Valida saldos
  - Fa match de categories
  - Retorna dades per previsualització

- `validateBalances(array $movements, int $compteCorrentId): array`
  - Obté últim saldo de la BD
  - Compara saldo calculat vs saldo del fitxer
  - Tolerància: ±0.01€
  - Retorna array d'errors

- `matchCategoryPath(string $path, int $compteCorrentId, float $import): ?int`
  - Prova múltiples estratègies segons el signe de l'import
  - Navega l'arbre de categories jeràrquicament
  - Cache en memòria per paths repetits
  - Case-insensitive matching (uppercase)
  - Retorna categoria_id o NULL

- `import(array $movements, int $compteCorrentId): array`
  - Inserció en transacció DB
  - Chunks de 100 registres per >500 moviments
  - Comprovació de duplicats per hash abans d'inserir
  - Retorna estadístiques (created, skipped, errors)

- `createMovement(array $movement, int $compteCorrentId): bool`
  - Comprova si el hash ja existeix abans d'inserir
  - Retorna true si s'ha creat, false si s'ha saltat (duplicat)
  - Evita errors de constraint violation

## Validació

### MovementImportRequest

**Ubicació**: `app/Http/Requests/MovementImportRequest.php`

**Regles**:
```php
'file' => ['required', 'file', 'mimes:xls,xlsx,csv,txt,qif', 'max:102400'], // 100MB
'compte_corrent_id' => ['required', 'integer', 'exists:g_comptes_corrents,id'],
'bank_type' => ['required', 'string', 'in:caixa_enginyers,caixabank,kmymoney'],
'import_mode' => ['nullable', 'string', 'in:from_beginning,from_last_db'],
'edited_movements' => ['nullable', 'array'],
'edited_movements.*.data_moviment' => ['nullable', 'date_format:Y-m-d'],
'edited_movements.*.concepte' => ['nullable', 'string', 'max:255'],
'edited_movements.*.categoria_id' => ['nullable', 'integer', 'exists:g_categories,id'],
```

### CompteCorrentRequest (actualitzat)

**Ubicació**: `app/Http/Requests/CompteCorrentRequest.php`

**Nota**: El camp `bank_type` és proporcionat pel frontend però es valida que coincideix amb els tipus suportats.

## Controller

### MovementImportController

**Ubicació**: `app/Http/Controllers/MovementImportController.php`

**Dependències injectades**:
- `FileParserService`
- `CaixaEnginyersParserService`
- `CaixaBankParserService`
- `KMyMoneyMovementParserService`
- `MovementImportService`

**Mètodes**:

#### `index(): Response`
- Renderitza la pàgina d'importació
- Passa tots els comptes corrents ordenats

#### `parse(Request $request): JsonResponse`
- Valida: file, compte_corrent_id, bank_type
- Llegeix fitxer segons format (string per QIF, array per XLS)
- Crida el parser corresponent
- Processa moviments amb `MovementImportService`
- Retorna errors de validació de saldos (HTTP 422) o dades de previsualització

#### `import(MovementImportRequest $request): JsonResponse`
- Mateix parsing que `parse()`
- Aplica edicions de l'usuari (data, concepte, categoria)
- Executa importació amb `MovementImportService::import()`
- Retorna estadístiques de creació

**Gestió de tipus de fitxer**:
```php
if ($bankType === 'kmymoney') {
    $content = file_get_contents($file->getRealPath());
    $parsedMovements = $this->kmymoneyParser->parse($content, $compteCorrentId);
} else {
    $rows = $this->fileParser->parse($file);
    $parsedMovements = $bankType === 'caixa_enginyers'
        ? $this->caixaEnginyersParser->parse($rows, $compteCorrentId)
        : $this->caixaBankParser->parse($rows, $compteCorrentId);
}
```

## Vista Vue

### MovementImport.vue

**Ubicació**: `resources/js/Pages/Maintenance/MovementImport.vue`

**Interfícies TypeScript**:
```typescript
interface CompteCorrent {
    id: number;
    compte_corrent: string;
    nom: string | null;
    entitat: string;
    bank_type: string | null;
}

interface Movement {
    data_moviment: string;
    concepte: string;
    import: number;
    saldo_posterior: number | null;
    categoria_id: number | null;
    hash_moviment: string;
    is_duplicate: boolean;
    new_movements_after?: number;
}

interface ParsedData {
    movements: Movement[];
    stats: {
        total: number;
        duplicates: number;
        to_import: number;
        hash_found: boolean;
    };
    errors: string[];
}
```

**Computed Properties**:
- `selectedCompte`: Obté el compte seleccionat
- `bankTypeOptions`: Mostra només el tipus del compte + KMyMoney
- `displayedMovements`: Limita els moviments mostrats per rendiment

**Funcions principals**:
- `parseFile()`: Envia fitxer a `/maintenance/movements/import/parse`
- `importMovements()`: Envia a `/maintenance/movements/import` amb edicions
- `resetForm()`: Neteja tots els camps i estat

**Estadístiques**:
- Total de moviments
- Duplicats (badge vermell)
- A importar (badge verd)
- Hash trobat (badge taronja si no)

**Advertències**:
- Error crític si `balance_validation_failed`
- Advertència crítica si `!stats.hash_found`

### Index.vue (ComptesCorrents actualitzat)

**Ubicació**: `resources/js/Pages/ComptesCorrents/Index.vue`

**Nota sobre bank_type**:
El camp `bank_type` es rep del backend com un accessor calculat automàticament a partir del nom de l'entitat. No cal definir-lo explícitament a la interfície TypeScript ja que forma part de la resposta JSON.

**Canvis**:
- S'ha eliminat la columna "Tipus Banc" de la taula
- S'ha eliminat el camp select del modal
- El tipus de banc es dedueix automàticament del nom de l'entitat al backend

## Rutes

```php
// Importació de moviments
Route::get('/maintenance/movements/import',
    [MovementImportController::class, 'index'])
    ->name('maintenance.movements.import');

Route::post('/maintenance/movements/import/parse',
    [MovementImportController::class, 'parse'])
    ->name('maintenance.movements.import.parse');

Route::post('/maintenance/movements/import',
    [MovementImportController::class, 'import'])
    ->name('maintenance.movements.import.store');
```

## Flux d'Ús

1. **Anar a importació**: `/maintenance/movements/import`
2. **Seleccionar compte**: El frontend mostra automàticament el tipus deduït del compte
3. **Seleccionar tipus d'importació**: Tria entre el tipus del compte o KMyMoney (QIF)
4. **Pujar fitxer**: Formats XLS, XLSX, CSV, TXT, QIF (màx 100MB)
5. **Seleccionar mode d'importació**: from_beginning o from_last_db (obligatori)
6. **Analitzar**: Botó "Analitzar fitxer" (ara en un sol pas)
7. **Revisar previsualització**:
   - Estadístiques: total, duplicats, a importar
   - Errors de validació de saldos (bloquegen importació)
   - Advertència si no es troba hash
   - Editar conceptes/categories si cal
8. **Importar**: Botó "Confirmar i importar moviments"
9. **Veure resultats**: Missatge amb moviments creats i duplicats saltats

## Consideracions Tècniques

### Rendiment

- **Optimitzacions per fitxers grans** (desembre 2024):
  - Previsualització limitada als últims 100 moviments (més recents primer) per evitar problemes de memòria
  - Inserció en chunks de 100 registres per fitxers grans (>500)
  - Cache de paths de categories per evitar consultes repetides
  - `findLastMovementIndex()`: Una sola consulta amb `whereIn()` en chunks de 1000 hashes en lloc de N consultes individuals
  - `performCategoryLookup()`: Precàrrega de totes les categories en memòria per evitar consultes repetides
  - **Configuració Nginx**: Timeouts augmentats a 300s per permetre processar fitxers grans
  - Aquestes millores permeten processar fitxers amb milers de moviments (>13.000) sense problemes de timeout o memòria

### Transaccions

Tota la importació s'executa dins de `DB::beginTransaction()` amb rollback automàtic en cas d'error.

### Gestió d'Errors

- Errors de parsing: HTTP 500 amb missatge
- Errors de validació de saldos: HTTP 422 amb detalls
- Errors de BD: Rollback i log

### Logging

Tots els errors es registren amb `Log::error()` incloent:
- Missatge d'error
- Nom del fitxer
- Tipus de banc
- Stack trace

## Fitxers de Prova

Els fitxers d'exemple es troben a:
```
src/storage/dades-banc-prova/
```

Aquests fitxers estan ignorats per Git (`.gitignore`).

## Configuració d'Infraestructura

### Nginx

Per permetre la càrrega i processament de fitxers grans, la configuració de Nginx ([docker/nginx/default.conf](../docker/nginx/default.conf)) inclou:

```nginx
# Increase timeouts for large file uploads and processing
client_max_body_size 100M;
client_body_timeout 300s;
client_header_timeout 300s;
send_timeout 300s;
proxy_read_timeout 300s;

location ~ \.php$ {
    # ... altres directives ...

    # Increase FastCGI timeouts for large file processing
    fastcgi_read_timeout 300s;
    fastcgi_send_timeout 300s;
    fastcgi_connect_timeout 300s;
    fastcgi_buffering off;
}
```

Aquests valors permeten:
- Fitxers de fins a 100MB
- Temps de processament de fins a 5 minuts
- Càrrega i resposta sense problemes per fitxers amb milers de moviments

### PHP

La configuració PHP ([docker/php/local.ini](../docker/php/local.ini)) estableix:

```ini
upload_max_filesize=100M
post_max_size=100M
memory_limit=512M
max_execution_time=600
```

## Millores Futures

- Suport per més formats bancaris
- Exportació de moviments
- Estadístiques i gràfics
- Regles automàtiques de categorització
- Detecció de transaccions duplicades entre comptes
