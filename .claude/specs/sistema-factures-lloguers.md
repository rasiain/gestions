# Sistema de factures per a lloguers no-habitatge

## Objectiu

Implementar un sistema de factures complet per a lloguers no-habitatge amb IVA, IRPF opcional, línies extres (escombraries, regularització IPC) i vinculació amb moviments bancaris.

## Fase 1: Migracions

### 1.1 Afegir camps fiscals a `g_lloguers`

Fitxer: `src/database/migrations/2026_03_29_100000_add_fiscal_fields_to_g_lloguers.php`

- `iva_percentatge` decimal(5,2) default 21.00 after `retencio_irpf`
- `irpf_percentatge` decimal(5,2) default 19.00 after `iva_percentatge`
- `despeses_separades` boolean default false after `irpf_percentatge`

### 1.2 Crear taula `g_factures`

Fitxer: `src/database/migrations/2026_03_29_100001_create_g_factures_table.php`

- `id` autoincrement
- `lloguer_id` FK g_lloguers cascade
- `contracte_id` FK nullable g_contractes set null
- `any` integer
- `mes` integer (1-12)
- `base` decimal(10,2)
- `iva_percentatge` decimal(5,2)
- `iva_import` decimal(10,2)
- `irpf_percentatge` decimal(5,2) default 0
- `irpf_import` decimal(10,2) default 0
- `total` decimal(10,2)
- `estat` string(20) default 'esborrany' — esborrany/emesa/cobrada
- `moviment_id` FK nullable g_moviments_comptes_corrents set null
- `numero_factura` string(50) nullable
- `data_emissio` date nullable
- `notes` text nullable
- `timestamps`
- Index únic: `[lloguer_id, any, mes]`

### 1.3 Crear taula `g_factura_linies`

Fitxer: `src/database/migrations/2026_03_29_100002_create_g_factura_linies_table.php`

- `id` autoincrement
- `factura_id` FK g_factures cascade
- `concepte` string(30) — lloguer_base, escombraries, regularitzacio_ipc, altres
- `descripcio` string(200) nullable
- `base` decimal(10,2)
- `iva_import` decimal(10,2) default 0
- `irpf_import` decimal(10,2) default 0
- `timestamps`

### 1.4 Crear taula `g_lloguer_revisions_ipc`

Fitxer: `src/database/migrations/2026_03_29_100003_create_g_lloguer_revisions_ipc_table.php`

- `id` autoincrement
- `lloguer_id` FK g_lloguers cascade
- `any_aplicacio` integer
- `base_anterior` decimal(10,2)
- `base_nova` decimal(10,2)
- `ipc_percentatge` decimal(5,2)
- `data_efectiva` date
- `mesos_regularitzats` integer default 0
- `timestamps`

## Fase 2: Models

### 2.1 `Factura` (`src/app/Models/Factura.php`)

- Taula: `g_factures`
- Fillable: tots els camps
- Casts: base/iva_import/irpf_import/total → decimal:2, iva_percentatge/irpf_percentatge → decimal:2, data_emissio → date
- Relacions: belongsTo Lloguer, belongsTo Contracte, belongsTo MovimentCompteCorrent (moviment_id), hasMany FacturaLinia

### 2.2 `FacturaLinia` (`src/app/Models/FacturaLinia.php`)

- Taula: `g_factura_linies`
- Fillable: tots els camps
- Casts: base/iva_import/irpf_import → decimal:2
- Relació: belongsTo Factura

### 2.3 `LloguerRevisioIpc` (`src/app/Models/LloguerRevisioIpc.php`)

- Taula: `g_lloguer_revisions_ipc`
- Fillable: tots els camps
- Casts: decimals, data_efectiva → date
- Relació: belongsTo Lloguer

### 2.4 Modificar `Lloguer`

- Afegir a fillable: iva_percentatge, irpf_percentatge, despeses_separades
- Afegir a casts: iva_percentatge/irpf_percentatge → decimal:2, despeses_separades → boolean
- Noves relacions: hasMany Factura, hasMany LloguerRevisioIpc

### 2.5 Modificar `MovimentCompteCorrent`

- Afegir relació: hasOne Factura (moviment_id)

## Fase 3: Backend

### 3.1 Modificar `LloguerRequest`

Afegir regles:
- `iva_percentatge` → nullable, numeric, min:0, max:100
- `irpf_percentatge` → nullable, numeric, min:0, max:100
- `despeses_separades` → boolean

### 3.2 Modificar `LloguerController@index`

Afegir al mapeig de dades: iva_percentatge, irpf_percentatge, despeses_separades

### 3.3 Nou controller `FacturaController`

Accions:
- `index(Lloguer $lloguer, Request $request)`: llistar factures filtrades per any, amb linies. JSON.
- `store(Request $request, Lloguer $lloguer)`: crear factura amb línies.
- `update(Request $request, Factura $factura)`: actualitzar factura i línies.
- `destroy(Factura $factura)`: eliminar factura (només esborranys).
- `generar(Lloguer $lloguer, Request $request)`: generar factures automàtiques per rang de mesos. Crea factura amb línia lloguer_base, calcula IVA/IRPF.
- `vincularMoviment(Factura $factura, Request $request)`: associar moviment_id, canviar estat a cobrada (o treure i tornar a emesa).

Validació inline (no cal FormRequest separat per simplificar).

### 3.4 Nou controller `LloguerRevisioIpcController`

Accions:
- `index(Lloguer $lloguer)`: llistar revisions IPC. JSON.
- `store(Request $request, Lloguer $lloguer)`: crear revisió, actualitzar base_euros del lloguer, opcionalment generar línies de regularització a les factures existents de l'any.

### 3.5 Rutes noves a `routes/web.php`

```
Route::get('/lloguers/{lloguer}/factures', [FacturaController::class, 'index']);
Route::post('/lloguers/{lloguer}/factures', [FacturaController::class, 'store']);
Route::post('/lloguers/{lloguer}/factures/generar', [FacturaController::class, 'generar']);
Route::put('/factures/{factura}', [FacturaController::class, 'update']);
Route::delete('/factures/{factura}', [FacturaController::class, 'destroy']);
Route::post('/factures/{factura}/vincular-moviment', [FacturaController::class, 'vincularMoviment']);
Route::get('/lloguers/{lloguer}/revisions-ipc', [LloguerRevisioIpcController::class, 'index']);
Route::post('/lloguers/{lloguer}/revisions-ipc', [LloguerRevisioIpcController::class, 'store']);
```

## Fase 4: Frontend

### 4.1 Modificar `Lloguers/Index.vue`

- Afegir iva_percentatge, irpf_percentatge, despeses_separades a interfície Lloguer, useForm, openEditLloguerModal
- Afegir camps al formulari del modal de lloguer (visibles només si !es_habitatge)
- Afegir botó "Factures" al panel de detall de lloguers no-habitatge
- Importar i integrar FacturesModal component

### 4.2 Nou component `FacturesModal.vue`

- Props: lloguer (Lloguer), show (boolean)
- Filtre per any
- Taula de factures: mes, base, IVA, IRPF, total, estat, nº factura, accions
- Botó "Generar factures" → formulari amb any, mes inici, mes fi
- Edició inline o modal de factura individual amb línies
- Selector per vincular moviment bancari (fetch moviments del compte corrent del lloguer)
- Colors per estat: esborrany (gris), emesa (blau), cobrada (verd)

### 4.3 Nou component `RevisioIpcModal.vue`

- Props: lloguer, show
- Llistat de revisions existents
- Formulari: any, IPC%, data efectiva
- Mostra base anterior → base nova calculada
- Checkbox "Regularitzar factures existents de l'any"
- Botó crear

## Notes
- Base de dades SQLite
- Docker: `docker compose exec app`
- Build frontend: `docker compose exec app npm run build 2>&1`
- Comunicació en català
- El component Modal ja existeix a `@/Components/Modal.vue`
- Funció `formatCurrency` disponible a Index.vue, caldria extreure-la o duplicar-la als nous components
- CSRF token via meta tag
