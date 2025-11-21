# Gestió de Titulars

## Descripció

Els titulars són les persones associades als comptes corrents bancaris. Cada compte corrent pot tenir múltiples titulars, i cada titular pot estar associat a múltiples comptes.

## Funcionalitats

### Llistat de Titulars
- Visualització de tots els titulars amb les seves dades
- Ordenació per nom
- Accessible des de: `/titulars`

### Crear Titular
- Formulari modal per afegir un nou titular
- Camps obligatoris: DNI, nom, cognoms
- Camp opcional: data de naixement

### Editar Titular
- Modificar les dades d'un titular existent
- Mateix formulari que la creació

### Eliminar Titular
- Confirmació abans d'eliminar
- Només es pot eliminar si no està associat a cap compte corrent

## Camps

- **DNI**: Document d'identitat (DNI o NIE espanyol)
  - Validació del format i dígit de control
  - Màxim 9 caràcters

- **Nom**: Nom del titular
  - Obligatori
  - Màxim 100 caràcters

- **Cognoms**: Cognoms del titular
  - Obligatori
  - Màxim 200 caràcters

- **Data de Naixement**: Data de naixement
  - Opcional
  - Format: YYYY-MM-DD

## Base de Dades

### Taula: `g_titulars`

```sql
CREATE TABLE g_titulars (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    dni VARCHAR(9) NOT NULL UNIQUE,
    nom VARCHAR(100) NOT NULL,
    cognoms VARCHAR(200) NOT NULL,
    data_naixement DATE NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## Model Eloquent

**Ubicació**: `app/Models/Titular.php`

### Relacions
- `comptesCorrents()`: Relació many-to-many amb CompteCorrent via taula pivot `g_compte_corrent_titular`

## Validació

**Ubicació**: `app/Http/Requests/TitularRequest.php`

### Regles de validació
```php
'dni' => ['required', 'string', 'max:9', 'unique:g_titulars,dni', new ValidDniNie],
'nom' => ['required', 'string', 'max:100'],
'cognoms' => ['required', 'string', 'max:200'],
'data_naixement' => ['nullable', 'date'],
```

### Validació personalitzada
- **ValidDniNie**: Valida el format i dígit de control de DNI/NIE espanyols

## Controller

**Ubicació**: `app/Http/Controllers/TitularController.php`

### Mètodes
- `index()`: Llista tots els titulars
- `store(TitularRequest)`: Crea un nou titular
- `update(TitularRequest, Titular)`: Actualitza un titular
- `destroy(Titular)`: Elimina un titular

## Vista Vue

**Ubicació**: `resources/js/Pages/Titulars/Index.vue`

### Components
- Taula amb llista de titulars
- Modal per crear/editar
- Botons d'acció (editar, eliminar)

### Interfície TypeScript
```typescript
interface Titular {
    id: number;
    dni: string;
    nom: string;
    cognoms: string;
    data_naixement: string | null;
    created_at: string;
    updated_at: string;
}
```

## Rutes

```php
Route::resource('titulars', TitularController::class)->only([
    'index', 'store', 'update', 'destroy'
]);
```

- `GET /titulars` - Llistat
- `POST /titulars` - Crear
- `PUT /titulars/{titular}` - Actualitzar
- `DELETE /titulars/{titular}` - Eliminar
