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
- **Un contracte pot tenir diversos arrendadors** (`g_arrendador_contracte`): en proindivís cada copropietari hi consta. La copropietat també es pot modelar amb una `ComunitatBens` (NIF propi) com a arrendador únic. A efectes d'IVA es pren el primer arrendador, perquè el subjecte passiu és un de sol. L'arrendador hauria de ser propietari de l'immoble: es proposa automàticament i s'avisa si no ho és, però no es força.
- **Una despesa de lloguer pot venir d'un altre compte**: `g_moviment_lloguer_despesa` relaciona moviment i lloguer sense restricció de compte. És excepcional (normalment totes les despeses són al compte del lloguer), però els càlculs del lloguer —resum, exportacions i IRPF— parteixen dels moviments *del lloguer*, mai dels del seu compte. A la llista de moviments d'un lloguer, el filtre `tots_comptes` permet trobar aquests moviments per classificar-los; un cop classificats hi són sempre visibles, amb una etiqueta del compte d'origen.
- **`concepte_original` és immutable**: text brut del banc, mai s'actualitza en editar. Només canvia `concepte_id`. És la clau per al mapeig automàtic d'imports futurs.
- **`conciliat` (boolean)**: marca un moviment com a revisat/puntejat. S'activa automàticament quan el moviment es classifica als lloguers (despesa o ingrés) o quan es vincula a una factura. Es pot marcar/desmarcar manualment des de la columna ✓ de la taula o en bloc des de la toolbar de selecció. Filtrable (Tots / Revisats / Pendents).

### Importació de moviments bancaris (`MovementImportService`)
Sis passos seqüencials: (1) generar hashes, (2) trobar punt de junció per hash, (3) filtrar moviments nous, (4) validar/calcular saldos, (5) mapeig de categories, (6) mapeig de conceptes (`concepte_original` → `concepte_id` de moviment anterior). Cada banc té el seu `*ParserService`; camps mínims: `data_moviment`, `concepte`, `import`, `saldo_posterior`, `notes`, `categoria_path`.

**La categoria del fitxer sempre preval.** Al pas 6, la categoria d'un moviment anterior amb el mateix `concepte_original` només s'aplica com a *fallback*, quan el fitxer no en porta cap (XLS de CaixaBank i Caixa d'Enginyers). Els fitxers amb `categoria_path` explícit (QIF) conserven la seva: diferents categories poden compartir concepte —un mateix rebut de l'ajuntament per a dos immobles— i el concepte no les pot distingir.

**Categories de QIF de KMyMoney**: a la secció `!Type:Cat`, KMyMoney escriu els pares amb el nom parcial (només l'últim segment), no el path complet. Com que les declara en ordre depth-first, el pare de cada línia és l'últim path ja declarat que acaba amb aquell segment. Els moviments (`!Type:Bank`), en canvi, sí que porten el path complet a la `L`, cosa que permet validar la reconstrucció.

### Taxes (impostos municipals)
`TaxesService` detecta les taxes per patró de nom de categoria i en resol l'immoble en dos passos: (1) **l'arbre de categories**, que ja el codifica com a `DESPESES PROPIETATS > <IMMOBLE> > <taxa>` o `IMMOBLES > <POBLACIÓ> > <IMMOBLE> > [TAXES >] <taxa>`; (2) **les despeses de lloguer** dels seus moviments, únic senyal quan la categoria penja d'un organisme (`IMPOSTOS/TAXES > AJUNTAMENT X > IBI`), on dues categories amb el mateix path però de comptes diferents poden ser immobles diferents. L'arbre mana per anomenar el grup; les despeses hi afegeixen el vincle amb l'immoble real i decideixen si el grup és de lloguer. La vista agrupa en tres seccions (lloguer / identificats / la resta) i, dins de cadascuna, per població — la dels immobles ve del camp `g_immobles.poblacio`, la dels altres del node de l'arbre (normalitzat: `VILANOVA DE LA MUGA` → `Vilanova de la Muga`). Una etiqueta amb un únic municipi conegut el presta a les categories germanes que no en tenen, perquè no se separin en dos grups.

**`g_taxes_immobles`** desa les decisions que ni l'arbre ni les despeses poden resoldre, per categoria: `immoble` (nom del grup, i alhora manera d'unificar dues categories: mateix nom + mateix municipi = mateix grup), `poblacio` i `ocult` (impostos puntuals que no són de cap immoble: successions, plusvàlues). L'ajust manual mana sobre l'arbre. No té pantalla de gestió: s'edita per migració o tinker.

**Nuclis de població ≠ municipi**: l'agrupació és per **municipi**, perquè és qui recapta. L'arbre, però, de vegades usa el nucli com a node de població. Cas resolt: `VILANOVA DE LA MUGA` és un nucli de **Peralada** (migració `2026_08_17_000003`), i les seves taxes van amb les de `CARRER MAJOR` i `CAMPS`. Pedret i Marzà, en canvi, sí que és municipi propi. Si apareix un nucli nou, la correcció és un ajust de `poblacio` a `g_taxes_immobles` (i, si escau, al camp `g_immobles.poblacio`).

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
              │  │    │           └── N:M ─▶ Arrendador ─morphTo─┐     │
              │  │    │                                          ▼     │
              │  │    │                                    Persona     │
              │  │    │                                    ComunitatBens│
              │  │    │                                                │
              │  │    └── 1:N ──▶ Factura ── 1:N ──▶ FacturaLinia      │
              │  │                │                                    │
              │  │                └──▶ MovimentCompteCorrent (vincle)   │
              │  │                                                     │
              │  └── RevisioIpc (1:N)                                  │
              │                                                        │
              │  ComunitatBens (catàleg independent, CRUD propi)       │
              │                                                        │
              │  Impostos: IVA, IRPF (calculats des de factures);      │
              │            Taxes (impostos municipals, vista derivada) │
              └────────────────────────────────────────────────────────┘
```

## Documentació addicional

Per a detalls específics de cada mòdul, consulta `docs/`:
- `docs/gestio-lloguers.md` — lloguers, contractes, llogaters
- `docs/importacio-moviments.md` — importació Excel/CSV de moviments bancaris
- `docs/gestio-categories.md` — categories jeràrquiques i importació QIF
