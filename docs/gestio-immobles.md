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

- **Administrador**: Persona responsable de l'immoble
  - Opcional
  - Relació amb la taula `g_persones`

## Base de Dades

### Taula: `g_immobles`

```sql
CREATE TABLE g_immobles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    adreca VARCHAR(200) NOT NULL,
    administrador_id INTEGER NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (administrador_id) REFERENCES g_persones(id) ON DELETE SET NULL
);
```

## Model Eloquent

**Ubicació**: `app/Models/Immoble.php`

### Relacions
- `administrador()`: Relació belongs-to amb Persona
- `lloguers()`: Relació has-many amb Lloguer

## Validació

**Ubicació**: `app/Http/Requests/ImmobleRequest.php`

### Regles de validació
```php
'adreca'          => ['required', 'string', 'max:200'],
'administrador_id' => ['nullable', 'integer', 'exists:g_persones,id'],
```

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
