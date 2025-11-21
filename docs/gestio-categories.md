# Gestió de Categories

## Descripció

Les categories permeten classificar els ingressos i despeses bancaris en una estructura jeràrquica d'arbre. Cada compte corrent té el seu propi conjunt de categories independent, amb dues categories arrel: "Ingressos" i "Despeses".

## Característiques principals

- **Estructura jeràrquica**: Categories pare i fills amb profunditat il·limitada
- **Específiques per compte**: Cada compte corrent té les seves pròpies categories
- **Ordenables**: Les categories germanes es poden ordenar amb el camp `ordre`
- **Categories arrel**: "Ingressos" i "Despeses" per cada compte corrent

## Funcionalitats

### Selector de Compte Corrent
- Dropdown per seleccionar el compte corrent a gestionar
- Filtra les categories mostrades segons el compte seleccionat
- Per defecte selecciona el primer compte disponible

### Llistat de Categories
- Visualització jeràrquica en forma d'arbre
- Categories arrel destacades amb fons de color
- Subcategories indentades visualment
- Mostra el camp `ordre` de cada categoria
- Accessible des de: `/categories`

### Crear Categoria
- Formulari modal per afegir una nova categoria
- Camps obligatoris: nom, compte corrent
- Camps opcionals: categoria pare, ordre
- Es pot crear com a categoria arrel o subcategoria

### Afegir Subcategoria
- Botó "+ Subcategoria" a cada categoria
- Pre-selecciona la categoria pare automàticament

### Editar Categoria
- Modificar les dades d'una categoria existent
- No es pot seleccionar la pròpia categoria com a pare (prevenció de cicles)

### Eliminar Categoria
- Confirmació abans d'eliminar
- Elimina en cascada totes les subcategories

## Camps

- **Compte Corrent**: Compte al qual pertany la categoria
  - Obligatori
  - Foreign key a `g_comptes_corrents`
  - Les categories només són visibles dins del seu compte

- **Nom**: Nom de la categoria
  - Obligatori
  - Màxim 100 caràcters

- **Categoria Pare**: Categoria superior en la jerarquia
  - Opcional (NULL = categoria arrel)
  - Foreign key a `g_categories`
  - Auto-referència per crear l'estructura d'arbre

- **Ordre**: Ordre de visualització entre categories germanes
  - Opcional
  - Valor entre 0 i 255
  - Per defecte: 0

## Base de Dades

### Taula: `g_categories`

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

CREATE INDEX idx_categories_lookup
ON g_categories(compte_corrent_id, categoria_pare_id, ordre);
```

### Migracions

1. **`2025_11_21_185432_create_categories_table.php`**:
   - Crea la taula inicial sense `compte_corrent_id`
   - Inseria categories globals "Ingressos" i "Despeses"

2. **`2025_11_21_192259_add_compte_corrent_id_to_categories_table.php`**:
   - Afegeix `compte_corrent_id` a la taula
   - Trunca les categories globals existents
   - Crea "Ingressos" i "Despeses" per cada compte corrent existent
   - Actualitza els índexs

## Model Eloquent

**Ubicació**: `app/Models/Categoria.php`

### Relacions
- `compteCorrent()`: Relació belongs-to amb CompteCorrent
- `pare()`: Relació belongs-to amb Categoria (auto-referència)
- `fills()`: Relació has-many amb Categoria (auto-referència), ordenada per `ordre`

### Scopes
- `arrel()`: Filtra només categories sense pare (WHERE categoria_pare_id IS NULL)
- `perCompteCorrent($compteCorrentId)`: Filtra per compte corrent

## Validació

**Ubicació**: `app/Http/Requests/CategoriaRequest.php`

### Regles de validació
```php
'compte_corrent_id' => ['required', 'integer', 'exists:g_comptes_corrents,id'],
'nom' => ['required', 'string', 'max:100'],
'categoria_pare_id' => ['nullable', 'integer', 'exists:g_categories,id'],
'ordre' => ['nullable', 'integer', 'min:0', 'max:255'],
```

## Controller

**Ubicació**: `app/Http/Controllers/CategoriaController.php`

### Mètodes
- `index(Request)`:
  - Llista tots els comptes corrents per al selector
  - Obté el compte seleccionat del request o usa el primer
  - Carrega categories amb eager loading de fills (fills.fills)
  - Filtra per compte corrent i només categories arrel

- `store(CategoriaRequest)`: Crea una nova categoria
- `update(CategoriaRequest, Categoria)`: Actualitza una categoria
- `destroy(Categoria)`: Elimina una categoria i les seves subcategories

### Eager Loading
Les categories es carreguen amb dos nivells de profunditat:
```php
Categoria::with('fills.fills')
    ->perCompteCorrent($compteCorrentId)
    ->arrel()
    ->get();
```

## Vista Vue

**Ubicació**: `resources/js/Pages/Categories/Index.vue`

### Components principals
- Selector de compte corrent (dropdown)
- Arbre jeràrquic de categories
- Modal per crear/editar
- Botons d'acció per cada categoria

### Estructura de dades
```typescript
interface CompteCorrent {
    id: number;
    compte_corrent: string;
    nom: string | null;
    entitat: string;
    ordre: number;
}

interface Categoria {
    id: number;
    compte_corrent_id: number;
    nom: string;
    categoria_pare_id: number | null;
    ordre: number;
    fills?: Categoria[];
    created_at: string;
    updated_at: string;
}
```

### Computed: allCategories
Aplana l'estructura jeràrquica per al dropdown de categoria pare:
- Afegeix un camp `level` a cada categoria
- Indenta visualment amb guions (`—`) segons el nivell
- Desactiva la pròpia categoria en mode edició (prevenció de cicles)

### Funcions clau
- `onCompteCorrentChange()`: Recarrega la pàgina amb el nou compte seleccionat
- `openCreateModal(parentId)`: Obre el modal amb categoria pare pre-seleccionada
- `openEditModal(categoria)`: Carrega dades de la categoria al formulari

## Rutes

```php
Route::resource('categories', CategoriaController::class)->only([
    'index', 'store', 'update', 'destroy'
]);
```

- `GET /categories?compte_corrent_id=X` - Llistat filtrat per compte
- `POST /categories` - Crear
- `PUT /categories/{categoria}` - Actualitzar
- `DELETE /categories/{categoria}` - Eliminar

## Consideracions tècniques

### Prevenció de cicles
La vista desactiva l'opció de seleccionar la pròpia categoria com a pare:
```vue
:disabled="!!(isEditing && editingCategoria && cat.id === editingCategoria.id)"
```

### Eliminació en cascada
El constraint `ON DELETE CASCADE` en la foreign key `categoria_pare_id` assegura que quan s'elimina una categoria, totes les seves subcategories també s'eliminen.

### Índex compost
L'índex `[compte_corrent_id, categoria_pare_id, ordre]` optimitza les consultes jeràrquiques filtrades per compte.

### TypeScript
El doble negació `!!` converteix l'expressió boolean a un boolean estricte per evitar errors de tipus amb l'atribut HTML `:disabled`.

## Ús futur

Les categories s'utilitzaran per classificar els moviments bancaris (ingressos i despeses) que s'importaran des dels fitxers de dades bancàries.
