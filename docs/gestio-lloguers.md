# Gestió de Lloguers

## Descripció

El mòdul de lloguers permet gestionar propietats en règim de lloguer. Cada lloguer agrupa:
- L'immoble físic
- El compte corrent bancari on es cobren les rendes
- El contracte vigent (dates i llogaters signants)
- El seguiment dels moviments bancaris associats

## Entitats del mòdul

### Lloguer
Unitat principal. Representa una relació de lloguer d'un immoble concret.

### Llogater
Persona física que signa un contracte de lloguer. Diferent de `Persona` (que actua com a titular de compte corrent); els llogaters tenen els seus propis camps (nom, cognoms, identificador).

### Contracte
Vincula un lloguer amb un o més llogaters durant un període determinat. Un lloguer pot tenir múltiples contractes successius (renovacions), però normalment només un d'actiu (sense data de fi o amb data de fi futura).

---

## Lloguer

### Camps

- **Nom**: Nom identificatiu del lloguer (p.ex. "Pis Gràcia")
  - Obligatori, màxim 100 caràcters

- **Acrònim**: Codi curt del lloguer (p.ex. "PG")
  - Opcional, màxim 20 caràcters

- **Immoble**: Immoble associat
  - Obligatori, FK a `g_immobles`

- **Compte Corrent**: Compte bancari on es reben les rendes
  - Obligatori, FK a `g_comptes_corrents`

- **Base euros**: Import mensual base de la renda
  - Opcional, decimal (2 decimals)

### Base de Dades

#### Taula: `g_lloguers`

```sql
CREATE TABLE g_lloguers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom VARCHAR(100) NOT NULL,
    acronim VARCHAR(20) NULL,
    immoble_id INTEGER NOT NULL,
    compte_corrent_id INTEGER NOT NULL,
    base_euros DECIMAL(10,2) NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (immoble_id) REFERENCES g_immobles(id),
    FOREIGN KEY (compte_corrent_id) REFERENCES g_comptes_corrents(id)
);
```

### Model Eloquent

**Ubicació**: `app/Models/Lloguer.php`

#### Relacions
- `immoble()`: belongs-to Immoble
- `compteCorrent()`: belongs-to CompteCorrent
- `contractes()`: has-many Contracte

### Validació

**Ubicació**: `app/Http/Requests/LloguerRequest.php`

```php
'nom'               => ['required', 'string', 'max:100'],
'acronim'           => ['nullable', 'string', 'max:20'],
'immoble_id'        => ['required', 'integer', 'exists:g_immobles,id'],
'compte_corrent_id' => ['required', 'integer', 'exists:g_comptes_corrents,id'],
'base_euros'        => ['nullable', 'numeric', 'min:0'],
```

### Controller

**Ubicació**: `app/Http/Controllers/LloguerController.php`

#### Mètodes
- `index()`: Llista tots els lloguers amb immoble, compte corrent i contracte actiu (eager loaded)
- `store(LloguerRequest)`: Crea un nou lloguer
- `update(LloguerRequest, Lloguer)`: Actualitza un lloguer
- `destroy(Lloguer)`: Elimina un lloguer
- `moviments(Lloguer, Request): JsonResponse`: Retorna paginació JSON dels moviments del compte corrent del lloguer (30 per pàgina)

#### API endpoint de moviments

```
GET /lloguers/{lloguer}/moviments?page=1
```

Resposta JSON:
```json
{
    "data": [
        {
            "id": 123,
            "data_moviment": "2025-03-01",
            "concepte": "REBUT LLOGUER",
            "import": "850.00",
            "saldo_posterior": "1250.00",
            "exclou_lloguer": false
        }
    ],
    "total": 87,
    "page": 1,
    "per_page": 30,
    "has_more": true
}
```

### Rutes

```php
Route::resource('lloguers', LloguerController::class)->only([
    'index', 'store', 'update', 'destroy'
]);
Route::get('/lloguers/{lloguer}/moviments', [LloguerController::class, 'moviments'])
    ->name('lloguers.moviments');
```

---

## Llogater

### Camps

- **Nom**: Nom del llogater
  - Obligatori, màxim 50 caràcters

- **Cognoms**: Cognoms del llogater
  - Obligatori, màxim 100 caràcters

- **Identificador**: DNI, NIE o passaport
  - Opcional, màxim 20 caràcters

### Base de Dades

#### Taula: `g_llogaters`

```sql
CREATE TABLE g_llogaters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom VARCHAR(50) NOT NULL,
    cognoms VARCHAR(100) NOT NULL,
    identificador VARCHAR(20) NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Model Eloquent

**Ubicació**: `app/Models/Llogater.php`

#### Relacions
- `contractes()`: belongs-to-many Contracte via `g_contracte_llogater`

### Validació

**Ubicació**: `app/Http/Requests/LlogaterRequest.php`

```php
'nom'           => ['required', 'string', 'max:50'],
'cognoms'       => ['required', 'string', 'max:100'],
'identificador' => ['nullable', 'string', 'max:20'],
```

### Controller

**Ubicació**: `app/Http/Controllers/LlogaterController.php`

#### Mètodes
- `index()`: Llista tots els llogaters
- `store(LlogaterRequest)`: Crea un nou llogater
- `update(LlogaterRequest, Llogater)`: Actualitza un llogater
- `destroy(Llogater)`: Elimina un llogater

### Rutes

```php
Route::resource('llogaters', LlogaterController::class)->only([
    'index', 'store', 'update', 'destroy'
]);
```

---

## Contracte

### Camps

- **Lloguer**: Lloguer al qual fa referència
  - Obligatori, FK a `g_lloguers`

- **Data inici**: Data d'inici del contracte
  - Obligatori

- **Data fi**: Data de finalització del contracte
  - Opcional. Si és NULL o futura, el contracte es considera actiu

- **Llogaters**: Llogaters signants del contracte
  - Opcional, relació many-to-many via taula pivot `g_contracte_llogater`

### Base de Dades

#### Taula: `g_contractes`

```sql
CREATE TABLE g_contractes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lloguer_id INTEGER NOT NULL,
    data_inici DATE NOT NULL,
    data_fi DATE NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (lloguer_id) REFERENCES g_lloguers(id) ON DELETE CASCADE
);
```

#### Taula pivot: `g_contracte_llogater`

```sql
CREATE TABLE g_contracte_llogater (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contracte_id INTEGER NOT NULL,
    llogater_id INTEGER NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (contracte_id) REFERENCES g_contractes(id) ON DELETE CASCADE,
    FOREIGN KEY (llogater_id) REFERENCES g_llogaters(id) ON DELETE CASCADE
);
```

### Model Eloquent

**Ubicació**: `app/Models/Contracte.php`

#### Relacions
- `lloguer()`: belongs-to Lloguer
- `llogaters()`: belongs-to-many Llogater via `g_contracte_llogater`

### Validació

**Ubicació**: `app/Http/Requests/ContracteRequest.php`

```php
'lloguer_id'   => ['required', 'integer', 'exists:g_lloguers,id'],
'data_inici'   => ['required', 'date'],
'data_fi'      => ['nullable', 'date', 'after_or_equal:data_inici'],
'llogater_ids' => ['nullable', 'array'],
'llogater_ids.*' => ['integer', 'exists:g_llogaters,id'],
```

### Controller

**Ubicació**: `app/Http/Controllers/ContracteController.php`

#### Mètodes
- `store(ContracteRequest)`: Crea un contracte i sincronitza llogaters
- `update(ContracteRequest, Contracte)`: Actualitza un contracte i sincronitza llogaters
- `destroy(Contracte)`: Desvincula llogaters i elimina el contracte

### Rutes

```php
Route::resource('contractes', ContracteController::class)->only([
    'store', 'update', 'destroy'
]);
```

> No hi ha ruta `index` per a contractes: es carreguen com a relació dins del lloguer.

---

## Vista principal de Lloguers

**Ubicació**: `resources/js/Pages/Lloguers/Index.vue`

### Interfície d'usuari

La vista de lloguers segueix el patró "seleccionar fila per expandir":

1. **Taula de lloguers**: Llista tots els lloguers. En fer clic a una fila, es selecciona (fons ambre) i s'expandeix el panell de detall.

2. **Panell de contracte** (apareix quan hi ha un lloguer seleccionat):
   - Camps data inici i data fi
   - Selector múltiple de llogaters (dropdown + etiquetes eliminables)
   - Botons per crear, actualitzar o eliminar el contracte

3. **Panell de moviments bancaris** (apareix quan hi ha un lloguer seleccionat):
   - Moviments del compte corrent associat al lloguer
   - 30 moviments per pàgina, botó "Mostrar-ne més" per carregar els següents
   - Checkbox per marcar `exclou_lloguer`: els moviments exclosos es mostren amb opacitat reduïda
   - Les crides al backend (`/lloguers/{id}/moviments` i `/moviments/{id}/exclou-lloguer`) usen `fetch` directe amb token CSRF

### Token CSRF per crides fetch

Atès que els panells de moviments no usen `useForm` d'Inertia, el token CSRF s'obté del meta tag:

```typescript
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
```

---

## Camp `exclou_lloguer` als moviments

El camp `exclou_lloguer` (boolean, per defecte `false`) a la taula `g_moviments_comptes_corrents` permet marcar si un moviment bancari **no** s'ha de considerar ingrés del lloguer (p.ex. devolució de dipòsit, pagament d'avaria).

### Endpoint de toggle

```
PATCH /moviments/{moviment}/exclou-lloguer
```

Resposta JSON:
```json
{ "exclou_lloguer": true }
```

Ruta:
```php
Route::patch('/moviments/{moviment}/exclou-lloguer', [MovimentCompteCorrentController::class, 'toggleExclou'])
    ->name('moviments.toggle-exclou');
```
