# Gestió d'Immobles

## Descripció

Els immobles representen les propietats físiques que poden ser assignades a lloguers. Cada immoble pot tenir un administrador (persona) associat.

## Funcionalitats

### Llistat d'Immobles
- Visualització de tots els immobles amb les seves dades
- Ordenació per adreça
- Accessible des de: `/immobles`

### Crear Immoble
- Formulari modal per afegir un nou immoble
- Camps obligatoris: adreça

### Editar Immoble
- Modificar les dades d'un immoble existent

### Eliminar Immoble
- Confirmació abans d'eliminar
- No es pot eliminar si està assignat a un lloguer actiu

## Camps

- **Adreça**: Adreça completa de l'immoble
  - Obligatori
  - Màxim 200 caràcters

- **Administració**: l'empresa (proveïdor) que administra l'immoble, amb històric
  - Opcional; un tram per període, a la taula `g_administracions_immobles`
  - Cada tram porta l'identificador que l'empresa fa servir per a l'immoble i la comissió
  - És també la gestoria dels seus lloguers

## Base de Dades

### Taula: `g_immobles`

```sql
CREATE TABLE g_immobles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    adreca VARCHAR(200) NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Taula: `g_administracions_immobles`

```sql
CREATE TABLE g_administracions_immobles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    immoble_id INTEGER NOT NULL,   -- ON DELETE CASCADE
    proveidor_id INTEGER NOT NULL, -- ON DELETE RESTRICT: no es perd l'històric
    referencia VARCHAR(50) NULL,   -- com identifica l'immoble l'empresa
    percentatge DECIMAL(5,2) NULL, -- comissió sobre la renda, sense IVA
    data_inici DATE NULL,          -- buida: des de sempre
    data_fi DATE NULL,             -- buida: vigent
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## Model Eloquent

**Ubicació**: `app/Models/Immoble.php`

### Relacions
- `administracions()`: Relació has-many amb AdministracioImmoble (trams, del més antic al més recent)
- `administracioA($data)`: el tram vigent a una data (avui, per defecte)
- `lloguers()`: Relació has-many amb Lloguer

## Validació

**Ubicació**: `app/Http/Requests/ImmobleRequest.php`

### Regles de validació
```php
'adreca'          => ['required', 'string', 'max:200'],
'administracions.*.proveidor_id' => ['required', 'integer', 'exists:g_proveidors,id'],
```

A més, `after()` rebutja els trams d'administració que s'encavalquen.

## Controller

**Ubicació**: `app/Http/Controllers/ImmobleController.php`

### Mètodes
- `index()`: Llista tots els immobles
- `store(ImmobleRequest)`: Crea un nou immoble
- `update(ImmobleRequest, Immoble)`: Actualitza un immoble
- `destroy(Immoble)`: Elimina un immoble

## Vista Vue

**Ubicació**: `resources/js/Pages/Immobles/Index.vue`

## Rutes

```php
Route::resource('immobles', ImmobleController::class)->only([
    'index', 'store', 'update', 'destroy'
]);
```

- `GET /immobles` - Llistat
- `POST /immobles` - Crear
- `PUT /immobles/{immoble}` - Actualitzar
- `DELETE /immobles/{immoble}` - Eliminar
