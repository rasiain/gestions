# Modal de resum de lloguers (vista web)

## Objectiu

A la vista de lloguers (`Lloguers/Index.vue`), afegir un botó "Resum" al costat d'"Exportar" que obri un modal amb les mateixes dades que l'exportació XLSX, organitzades en 3 pestanyes: Resum, Ingressos i Despeses.

## Backend

### 1. Refactoritzar `LloguerController.php`

Extreure la lògica de preparació de dades del mètode `exportar()` (línies 209-284) a un mètode privat `prepararDadesResum(Lloguer $lloguer, ?int $any)` que retorni un array amb:

```php
[
    'ingressos' => [...],    // array d'ingressos amb data, concepte, base, despeses, net_calculat, import_banc, diferencia, notes
    'despeses' => [...],     // array de despeses amb data, categoria, concepte, proveidor, nif, import, notes
    'total_base' => float,
    'total_despeses' => float,
    'resultat_net' => float,
    'lloguer_nom' => string,
    'immoble_adreca' => string,
    'any' => int|null,
]
```

- El mètode `exportar()` ha de cridar `prepararDadesResum()` i continuar generant l'XLSX amb les dades retornades.
- Nou mètode `resum()` que crida `prepararDadesResum()` i retorna `response()->json(...)`.

### 2. Ruta nova a `routes/web.php`

```php
Route::get('/lloguers/{lloguer}/resum', [LloguerController::class, 'resum'])->name('lloguers.resum');
```

Afegir-la al costat de la ruta d'exportar existent.

## Frontend

### 3. Botó "Resum" a `Lloguers/Index.vue`

Al costat del botó "Exportar" (línia ~994), afegir un botó "Resum" amb estil similar (outline en lloc de solid per diferenciar-lo). Icona d'ull o de taula.

### 4. Modal amb pestanyes

- Variable reactiva `showResumModal` (boolean) i `resumData` (les dades del JSON).
- Funció `openResum()` que fa fetch a `/lloguers/{id}/resum?any=...` i obre el modal.
- Modal gran (max-w-5xl o similar) amb 3 pestanyes seleccionables:

#### Pestanya "Resum"
- Info del lloguer (nom, immoble, any)
- Taula simple: Total ingressos bruts, Total despeses, Resultat net

#### Pestanya "Ingressos"
- Taula amb columnes: Data, Concepte, Base lloguer, Despeses, Net calculat, Import bancari, Diferència, Notes
- Files amb diferència != 0 marcades amb fons vermellós (com a l'XLSX)
- Fila de totals al final

#### Pestanya "Despeses"
- Taula amb columnes: Data, Categoria, Concepte, Proveïdor, NIF/CIF, Import, Notes
- Fila de totals al final

### Estil
- Pestanyes tipus tabs horitzontals amb border-bottom actiu
- Taules amb estil coherent amb la resta de l'aplicació (Tailwind, dark mode)
- Importants en format moneda (2 decimals, separador de milers)
- Modal amb scroll intern si el contingut és llarg

## Notes
- La funció `formatCurrency` ja existeix al component, reutilitzar-la.
- El modal ja existeix com a patró al component (classificació), seguir el mateix patró.
- Base de dades SQLite, Docker: `docker compose exec app`.
- Compilar frontend: `docker compose exec app npm run build 2>&1`.
