# Gestió de Proveïdors

## Descripció

Els proveïdors representen empreses o persones que presten serveis associats als immobles (comunitats de propietaris, empreses de manteniment, etc.).

## Funcionalitats

### Llistat de Proveïdors
- Visualització de tots els proveïdors amb les seves dades
- Ordenació per nom
- Accessible des de: `/proveidors`

### Crear Proveïdor
- Formulari modal per afegir un nou proveïdor
- Camps obligatoris: nom

### Editar Proveïdor
- Modificar les dades d'un proveïdor existent

### Eliminar Proveïdor
- Confirmació abans d'eliminar

## Camps

- **Nom**: Nom del proveïdor
  - Obligatori
  - Màxim 100 caràcters

- **NIF**: Número d'identificació fiscal
  - Opcional
  - Màxim 20 caràcters

- **Telèfon**: Número de telèfon de contacte
  - Opcional
  - Màxim 20 caràcters

- **Email**: Adreça de correu electrònic
  - Opcional
  - Màxim 100 caràcters

## Base de Dades

### Taula: `g_proveidors`

```sql
CREATE TABLE g_proveidors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom VARCHAR(100) NOT NULL,
    nif VARCHAR(20) NULL,
    telefon VARCHAR(20) NULL,
    email VARCHAR(100) NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## Model Eloquent

**Ubicació**: `app/Models/Proveidor.php`

## Validació

**Ubicació**: `app/Http/Requests/ProveidorRequest.php`

### Regles de validació
```php
'nom'     => ['required', 'string', 'max:100'],
'nif'     => ['nullable', 'string', 'max:20'],
'telefon' => ['nullable', 'string', 'max:20'],
'email'   => ['nullable', 'email', 'max:100'],
```

## Controller

**Ubicació**: `app/Http/Controllers/ProveidorController.php`

### Mètodes
- `index()`: Llista tots els proveïdors
- `store(ProveidorRequest)`: Crea un nou proveïdor
- `update(ProveidorRequest, Proveidor)`: Actualitza un proveïdor
- `destroy(Proveidor)`: Elimina un proveïdor

## Vista Vue

**Ubicació**: `resources/js/Pages/Proveidors/Index.vue`

## Rutes

```php
Route::resource('proveidors', ProveidorController::class)->only([
    'index', 'store', 'update', 'destroy'
]);
```

- `GET /proveidors` - Llistat
- `POST /proveidors` - Crear
- `PUT /proveidors/{proveidor}` - Actualitzar
- `DELETE /proveidors/{proveidor}` - Eliminar
