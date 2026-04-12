# Claude – Instruccions del projecte

## Llengua

Comunica't sempre en **català** amb l'usuari.

## Descripció

Aplicació de gestió financera personal i de lloguers. Dues àrees: gestions bancàries (comptes, moviments, categories) i lloguers (immobles, contractes, llogaters, factures, impostos).

## Stack

- **Backend**: Laravel 12, PHP 8.4, SQLite
- **Frontend**: Vue 3 (Composition API) + TypeScript + Tailwind CSS 3
- **Bridge SPA**: Inertia.js (paquets `inertiajs/inertia-laravel` + `@inertiajs/vue3`)
- **Infraestructura**: Docker (PHP-FPM + Nginx + Supervisor + Redis)
- **Rutes JS**: Ziggy (`route()` disponible al frontend)
- **Auth**: Laravel Breeze + Sanctum

## Comandes habituals

Totes les comandes s'executen **des de fora del contenidor**:

```bash
# Migracions
docker compose exec app php artisan migrate

# Compilar frontend (obligatori després de canvis Vue/TS/CSS — no hi ha hot reload)
docker compose exec app npm run build 2>&1

# Tests
docker compose exec app php artisan test

# Tinker
docker compose exec app php artisan tinker
```

## Estructura del projecte

```
src/
├── app/Http/Controllers/    # Resource controllers
├── app/Http/Requests/       # Form Request per recurs
├── app/Models/              # Eloquent models
├── database/migrations/     # Migracions (prefix g_)
├── resources/js/
│   ├── Pages/               # Pàgines Inertia (1 Index.vue per mòdul)
│   ├── Components/          # Components Vue reutilitzables (Tailwind, sense UI library)
│   └── Layouts/             # AuthenticatedLayout, GuestLayout
├── routes/web.php           # Totes les rutes (sota middleware auth)
└── routes/api.php
```

## Convencions

### Backend
- **Taules**: prefix `g_` (ex: `g_lloguers`, `g_moviments_comptes_corrents`)
- **Models**: singular en català (`Lloguer`, `CompteCorrent`, `Persona`)
- **Controllers**: resource controllers (`index`, `store`, `update`, `destroy`)
- **Validació**: sempre via Form Request dedicat (ex: `LloguerRequest`)
- **Commits**: Conventional Commits en català

### Frontend
- Cada mòdul és una pàgina `Index.vue` que gestiona llistat + CRUD inline via `useForm` d'Inertia
- Interfícies TypeScript definides inline al principi de cada pàgina
- Components UI fets a mà amb Tailwind (no hi ha cap llibreria UI externa)
- Colors per mòdul: blau → comptes bancaris, ambre → lloguers

### Base de dades
- Deduplicació de moviments bancaris per hash SHA-256: `data|import|compte_id|seqüència` (el concepte s'exclou intencionadament)
- Categories jeràrquiques (auto-referència `categoria_pare_id`)
- Pivots amb dates per a propietaris d'immobles
- **`concepte_original` és immutable**: text brut del banc, mai s'actualitza en editar. Només canvia `concepte_id`. És la clau per al mapeig automàtic d'imports futurs.
- **`conciliat` (boolean)**: marca un moviment com a revisat/puntejat. S'activa automàticament quan el moviment es classifica als lloguers (despesa o ingrés) o quan es vincula a una factura. Es pot marcar/desmarcar manualment des de la columna ✓ de la taula o en bloc des de la toolbar de selecció. Filtrable (Tots / Revisats / Pendents).

### Importació de moviments bancaris (`MovementImportService`)
Sis passos seqüencials: (1) generar hashes, (2) trobar punt de junció per hash, (3) filtrar moviments nous, (4) validar/calcular saldos, (5) mapeig de categories, (6) mapeig de conceptes (`concepte_original` → `concepte_id` de moviment anterior). Cada banc té el seu `*ParserService`; camps mínims: `data_moviment`, `concepte`, `import`, `saldo_posterior`, `notes`, `categoria_path`.

### Components reutilitzables destacats
- `BulkEditModal.vue`: modal d'edició múltiple (concepte, notes, categoria). Gestiona el formulari internament; emet `@submit(payload)` i `v-model:open`. El pare conserva `saving` i `error` i fa la crida API. Usat a `Moviments/Index.vue` i `Lloguers/Index.vue`.

## Mapa de relacions del domini

```
┌─────────────────────────────── GESTIONS BANCÀRIES ───────────────────────────────┐
│                                                                                 │
│  Persona ──N:M──▶ CompteCorrent ◀── 1:N ── Categoria (arbre jeràrquic)         │
│  (titular)        │                                                             │
│                   │                                                             │
│                   └── 1:N ──▶ MovimentCompteCorrent                             │
│                                │                                                │
└────────────────────────────────┼────────────────────────────────────────────────-┘
                                 │
              ┌──────────────────┼──────────────────────────────────────┐
              │    LLOGUERS      │                                     │
              │                  ▼                                     │
              │  ┌─ Ingres (MovimentLloguerIngres) ── 1:N ─▶ Linia    │
              │  │                                                     │
              │  └─ Despesa (MovimentLloguerDespesa) ──▶ Proveidor    │
              │                                                        │
              │  Lloguer ──────▶ Immoble ──N:M──▶ Persona              │
              │  │    │          (propietaris)      (proposen arrendador)│
              │  │    │                                                │
              │  │    ├──▶ CompteCorrent                               │
              │  │    ├──▶ Proveidor (gestoria)                        │
              │  │    │                                                │
              │  │    ├── 1:N ──▶ Contracte ──N:M──▶ Llogater          │
              │  │    │           │                                     │
              │  │    │           └──▶ Arrendador ──morphTo──┐         │
              │  │    │                (adreça)              │         │
              │  │    │                                      ▼         │
              │  │    │                              Persona            │
              │  │    │                              ComunitatBens      │
              │  │    │                                                │
              │  │    └── 1:N ──▶ Factura ── 1:N ──▶ FacturaLinia      │
              │  │                │                                    │
              │  │                └──▶ MovimentCompteCorrent (vincle)   │
              │  │                                                     │
              │  └── RevisioIpc (1:N)                                  │
              │                                                        │
              │  ComunitatBens (catàleg independent, CRUD propi)       │
              │                                                        │
              │  Impostos: IVA, IRPF (calculats des de factures)       │
              └────────────────────────────────────────────────────────┘
```

## Documentació addicional

Per a detalls específics de cada mòdul, consulta `docs/`:
- `docs/gestio-lloguers.md` — lloguers, contractes, llogaters
- `docs/importacio-moviments.md` — importació Excel/CSV de moviments bancaris
- `docs/gestio-categories.md` — categories jeràrquiques i importació QIF
