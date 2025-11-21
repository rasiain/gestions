# Gestió de Comptes Corrents

## Descripció

Els comptes corrents són els comptes bancaris gestionats per l'aplicació. Cada compte corrent pot tenir múltiples titulars associats i les seves pròpies categories d'ingressos i despeses.

## Funcionalitats

### Llistat de Comptes Corrents
- Visualització de tots els comptes amb les seves dades
- Ordenació per camp `ordre` i després per `entitat`
- Accessible des de: `/comptes-corrents`

### Crear Compte Corrent
- Formulari modal per afegir un nou compte
- Camps obligatoris: número de compte corrent, entitat
- Camps opcionals: nom identificatiu, ordre, titulars

### Editar Compte Corrent
- Modificar les dades d'un compte existent
- Gestionar els titulars associats
- Mateix formulari que la creació

### Eliminar Compte Corrent
- Confirmació abans d'eliminar
- Elimina en cascada les categories associades

## Camps

- **Compte Corrent**: Número de compte corrent
  - Obligatori
  - Màxim 24 caràcters
  - Únic

- **Nom**: Nom identificatiu del compte (opcional)
  - Màxim 100 caràcters
  - Facilita la identificació del compte

- **Entitat**: Nom de l'entitat bancària
  - Obligatori
  - Màxim 200 caràcters

- **Ordre**: Ordre de visualització
  - Opcional
  - Valor entre 0 i 255
  - Per defecte: 0

- **Titulars**: Titulars associats al compte
  - Relació many-to-many amb la taula `g_titulars`
  - Es poden seleccionar múltiples titulars via checkboxes

## Base de Dades

### Taula: `g_comptes_corrents`

```sql
CREATE TABLE g_comptes_corrents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    compte_corrent VARCHAR(24) NOT NULL UNIQUE,
    nom VARCHAR(100) NULL,
    entitat VARCHAR(200) NOT NULL,
    ordre TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Taula pivot: `g_compte_corrent_titular`

```sql
CREATE TABLE g_compte_corrent_titular (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    compte_corrent_id INTEGER NOT NULL,
    titular_id INTEGER NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (compte_corrent_id) REFERENCES g_comptes_corrents(id) ON DELETE CASCADE,
    FOREIGN KEY (titular_id) REFERENCES g_titulars(id) ON DELETE CASCADE
);
```

## Model Eloquent

**Ubicació**: `app/Models/CompteCorrent.php`

### Relacions
- `titulars()`: Relació many-to-many amb Titular via taula pivot `g_compte_corrent_titular`
- `categories()`: Relació one-to-many amb Categoria, ordenada per `ordre`

## Validació

**Ubicació**: `app/Http/Requests/CompteCorrentRequest.php`

### Regles de validació
```php
'compte_corrent' => ['required', 'string', 'max:24', 'unique:g_comptes_corrents,compte_corrent'],
'nom' => ['nullable', 'string', 'max:100'],
'entitat' => ['required', 'string', 'max:200'],
'ordre' => ['nullable', 'integer', 'min:0', 'max:255'],
'titular_ids' => ['nullable', 'array'],
'titular_ids.*' => ['integer', 'exists:g_titulars,id'],
```

## Controller

**Ubicació**: `app/Http/Controllers/CompteCorrentController.php`

### Mètodes
- `index()`: Llista tots els comptes amb titulars eager loaded
- `store(CompteCorrentRequest)`: Crea un nou compte i sincronitza titulars
- `update(CompteCorrentRequest, CompteCorrent)`: Actualitza un compte i sincronitza titulars
- `destroy(CompteCorrent)`: Elimina un compte

### Gestió de titulars
Els titulars s'assignen mitjançant el mètode `sync()` d'Eloquent:
```php
$compteCorrent->titulars()->sync($request->input('titular_ids', []));
```

## Vista Vue

**Ubicació**: `resources/js/Pages/ComptesCorrents/Index.vue`

### Components
- Taula amb llista de comptes corrents
- Columna amb noms dels titulars associats
- Modal per crear/editar amb checkboxes per seleccionar titulars
- Botons d'acció (editar, eliminar)

### Interfície TypeScript
```typescript
interface CompteCorrent {
    id: number;
    compte_corrent: string;
    nom: string | null;
    entitat: string;
    ordre: number;
    titulars: Titular[];
    created_at: string;
    updated_at: string;
}
```

## Rutes

```php
Route::resource('comptes-corrents', CompteCorrentController::class)
    ->parameters(['comptes-corrents' => 'compte_corrent'])
    ->only(['index', 'store', 'update', 'destroy']);
```

**Important**: El mapeig de paràmetres `->parameters(['comptes-corrents' => 'compte_corrent'])` és necessari per al route model binding correcte.

- `GET /comptes-corrents` - Llistat
- `POST /comptes-corrents` - Crear
- `PUT /comptes-corrents/{compte_corrent}` - Actualitzar
- `DELETE /comptes-corrents/{compte_corrent}` - Eliminar

## Creació automàtica de categories

Quan es crea un nou compte corrent, **NO** es creen automàticament les categories "Ingressos" i "Despeses". Aquestes s'han de crear manualment des de la gestió de categories, o bé modificar la migració `2025_11_21_192259_add_compte_corrent_id_to_categories_table.php` per incloure aquesta lògica en un event listener.

Per implementar la creació automàtica, es pot afegir un observer al model CompteCorrent:

```php
// app/Observers/CompteCorrentObserver.php
public function created(CompteCorrent $compteCorrent): void
{
    $compteCorrent->categories()->createMany([
        ['nom' => 'Ingressos', 'ordre' => 0],
        ['nom' => 'Despeses', 'ordre' => 1],
    ]);
}
```
