# Gestió de Persones

## Descripció

Les persones són entitats generals que poden tenir diferents rols dins l'aplicació. En el context dels comptes corrents, actuen com a titulars. En futurs desenvolupaments, també podran ser propietaris d'immobles o altres actius.

## Funcionalitats

### Llistat de Persones
- Visualització de totes les persones amb les seves dades
- Ordenació per cognoms i nom
- Accessible des de: `/persones`

### Crear Persona
- Formulari modal per afegir una nova persona
- Camps obligatoris: nom, cognoms

### Editar Persona
- Modificar les dades d'una persona existent
- Mateix formulari que la creació

### Eliminar Persona
- Confirmació abans d'eliminar
- Només es pot eliminar si no està associada a cap compte corrent o altre actiu

## Camps

- **Nom**: Nom de la persona
  - Obligatori
  - Màxim 20 caràcters

- **Cognoms**: Cognoms de la persona
  - Obligatori
  - Màxim 50 caràcters

## Base de Dades

### Taula: `g_persones`

```sql
CREATE TABLE g_persones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom VARCHAR(20) NOT NULL,
    cognoms VARCHAR(50) NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## Model Eloquent

**Ubicació**: `app/Models/Persona.php`

### Relacions
- `comptesCorrents()`: Relació many-to-many amb CompteCorrent via taula pivot `g_compte_corrent_titular` (quan actuen com a titulars)

## Validació

**Ubicació**: `app/Http/Requests/PersonaRequest.php`

### Regles de validació
```php
'nom' => ['required', 'string', 'max:20'],
'cognoms' => ['required', 'string', 'max:50'],
```

## Controller

**Ubicació**: `app/Http/Controllers/PersonaController.php`

### Mètodes
- `index()`: Llista totes les persones
- `store(PersonaRequest)`: Crea una nova persona
- `update(PersonaRequest, Persona)`: Actualitza una persona
- `destroy(Persona)`: Elimina una persona

## Vista Vue

**Ubicació**: `resources/js/Pages/Persones/Index.vue`

### Components
- Taula amb llista de persones
- Modal per crear/editar
- Botons d'acció (editar, eliminar)

### Interfície TypeScript
```typescript
interface Persona {
    id: number;
    nom: string;
    cognoms: string;
    created_at: string;
    updated_at: string;
}
```

## Rutes

```php
Route::resource('persones', PersonaController::class)->only([
    'index', 'store', 'update', 'destroy'
]);
```

- `GET /persones` - Llistat
- `POST /persones` - Crear
- `PUT /persones/{persona}` - Actualitzar
- `DELETE /persones/{persona}` - Eliminar
