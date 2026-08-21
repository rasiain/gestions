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
`TaxesService` detecta les taxes per patró de nom de categoria i en resol l'immoble en dos passos: (1) **l'arbre de categories**, que ja el codifica com a `DESPESES PROPIETATS > <IMMOBLE> > <taxa>` o `IMMOBLES > <POBLACIÓ> > <IMMOBLE> > [TAXES >] <taxa>`; (2) **les despeses de lloguer** dels seus moviments, únic senyal quan la categoria penja d'un organisme (`IMPOSTOS/TAXES > AJUNTAMENT X > IBI`), on dues categories amb el mateix path però de comptes diferents poden ser immobles diferents. L'arbre mana per anomenar el grup; les despeses hi afegeixen el vincle amb l'immoble real i decideixen si el grup és de lloguer. La vista agrupa en tres seccions (lloguer / identificats / la resta) i, dins de cadascuna, per població — l'ajust manual primer, després el camp `g_immobles.poblacio` i, si no, el node de l'arbre. Les tres fonts passen per `canonitzaPoblacio()` (`VILANOVA DE LA MUGA` → `Vilanova de la Muga`): si no, un `SALT` desat a `g_immobles` faria capçalera a part del `Salt` vingut de l'arbre. Una etiqueta amb un únic municipi conegut el presta a les categories germanes que no en tenen, perquè no se separin en dos grups.

**`g_taxes_immobles`** desa les decisions que ni l'arbre ni les despeses poden resoldre, per categoria: `immoble` (nom del grup, i alhora manera d'unificar dues categories: mateix nom + mateix municipi = mateix grup), `poblacio` i `ocult` (impostos puntuals que no són de cap immoble: successions, plusvàlues). L'ajust manual mana sobre l'arbre. No té pantalla de gestió: s'edita per migració o tinker.

**`g_taxes_rebuts`** desa el **total anual** que l'ajuntament gira per `(grup, tipus, any)` — el `grup` és la clau d'agrupació de `TaxesService` (`nom:<ETIQUETA>|<POBLACIÓ>` o `immoble:<id>`). D'aquí surten el percentatge pagat, els terminis i el que queda. Amb `repercutible` + `concepte_repercussio` (avui només `escombraries`), `TaxesEstatService` hi afegeix la part del llogater: repercutit (suma de les línies de factura del lloguer amb aquell concepte), pendent i **saldo** = repercutit − pagat (positiu: el llogater ha avançat; negatiu: el propietari finança). Cap import no s'infereix mai de text lliure. L'import retornat pel llogater es mostra encara que no s'hagi definit el total del rebut (és cert per ell mateix); el que necessita el total són els percentatges i el pendent.

**Repercussions als ingressos de lloguer**: `g_moviment_lloguer_ingres_linia.naturalesa` distingeix `deduccio` (gestoria, reparacions: es **resten** de `base_lloguer` per arribar al net del banc) de `repercussio` (escombraries retornades pel llogater: ja són **dins** de la base, només la desglossen). `base_lloguer` continua sent el total cobrat — al modal de classificació s'introdueix com a **Renda + Escombraries**, i la base és la suma calculada. Per això cap càlcul no resta mai una repercussió: ni el net calculat, ni l'exportació, ni l'IRPF (la base ja hi compta sencera com a ingrés íntegre, i la taxa es dedueix pel seu pagament).

**Nuclis de població ≠ municipi**: l'agrupació és per **municipi**, perquè és qui recapta. L'arbre, però, de vegades usa el nucli com a node de població. Cas resolt: `VILANOVA DE LA MUGA` és un nucli de **Peralada** (migració `2026_08_17_000003`), i les seves taxes van amb les de `CARRER MAJOR` i `CAMPS`. Pedret i Marzà, en canvi, sí que és municipi propi. Si apareix un nucli nou, la correcció és un ajust de `poblacio` a `g_taxes_immobles` (i, si escau, al camp `g_immobles.poblacio`).

### Assegurances

`AssegurancesService` detecta les pòlisses pagades des de qualsevol compte amb la mateixa idea que les taxes —patró sobre l'arbre de categories, mai sobre el concepte bancari (un patró «GENERALI» enganxa les nòmines de la GENERALITAT)— amb dues diferències que imposen les dades:

- **El patró es busca a tot el camí, no només a la fulla.** A les taxes la fulla és la taxa (`… > IBI`); a les assegurances acostuma a ser la **companyia** (`… > BONASTRUCH DE PORTA 35 > ASSEGURANÇA > SEGURCAIXA`). Val el **node coincident més alt**: és el de la pòlissa i no canvia quan es canvia d'asseguradora. Mirant només la fulla es detecten 64 moviments; mirant el camí, 230.
- **La coincidència és per inici de paraula** (`\bASSEGURAN`), no `str_contains`: «CAN MASSEGUR» (complements de casa) i «L'ENSEGUR» (un restaurant) contenen «ASSEGUR» i no són cap pòlissa.

L'objecte assegurat es resol en tres passos: (1) l'**immoble de l'arbre**, igual que a les taxes; (2) el **lloguer** de les despeses classificades dels seus moviments, únic senyal quan la categoria penja d'un node genèric (`DESPESES > ASSEGURANCES > SEGURCAIXA NEGOCI`); (3) el **pare del node** de la pòlissa (`MOTOR > MOTO > ASSEGURANÇA MOTO` → `MOTO`) i, si el pare és genèric, el node mateix. La vista agrupa en tres seccions —immobles de lloguer / altres immobles / vehicles, persones i altres— i, dins de les d'immoble, per població. La **fila** és l'etiqueta del patró (Assegurança, Comunitat, Vehicle, Decessos), no la companyia: així un canvi d'asseguradora no parteix la sèrie en dues, i la comunitat d'un immoble queda com a pòlissa a part de la seva.

**Comparació amb l'any anterior**: aquí no cal declarar cap total a mà (a diferència de `g_taxes_rebuts`), perquè el de l'any passat ja és a les dades. `AssegurancesEstatService` compara amb l'any anterior **retallat al mateix dia** —vuit mesos de 2026 no es comparen amb dotze de 2025—, i l'any anterior sencer hi és a part com a referència. Hi afegeix la **periodicitat**, deduïda del nombre de càrrecs dels últims dotze mesos, i la **prima**, que és el **càrrec més gran** d'aquesta finestra i no l'últim: en una categoria que barreja el rebut anual amb comissions de 30 €, l'últim càrrec compararia el rebut d'enguany amb una comissió de l'any passat. Els imports positius (indemnitzacions, extorns de prima) es mostren a part i **no es resten mai** del pagat. A les pòlisses d'immobles de lloguer s'hi avisa dels càrrecs no classificats com a despesa: són deduccions d'IRPF que s'escapen.

D'aquestes dues en surt la **previsió de tancament** —pagat + els càrrecs que falten a la prima d'ara— i el **proper càrrec** (últim + el període). Només mentre l'any corre: un any tancat ja no espera res, i les dues columnes desapareixen. Un proper càrrec que ja ha passat es marca en ambre: vol dir que la pòlissa s'ha donat de baixa, ha canviat de forma de pagament o té els càrrecs mal classificats.

**`g_assegurances_polisses`** desa els ajustos manuals per categoria, com `g_taxes_immobles`, amb dos camps que les taxes no necessiten: `inclou`, per a les pòlisses que cap patró no pot enganxar pel nom (`SERVEIS > MUTUALITAT DELS ENGINYERS`), i `companyia`, per llegir l'asseguradora d'una altra manera sense tocar l'arbre. **`companyia` avui no s'usa**, i és deliberat: quan una companyia surt escrita de dues maneres, la correcció és **reanomenar la categoria**, no posar-hi un àlies, perquè l'àlies només arregla una columna d'una pantalla i a Moviments i a Categories el nom vell continuaria sortint. La migració `2026_08_21_000002` ho va fer amb totes les que hi havia: `CATALANA OCCIDENTE` i `CATALANA OCCIDENT` → OCCIDENT, `AXA SEG. GENERALES` → AXA, `REALE SEGUROS GENERALES S.A.` → REALE, `BILBAO C. A. DE SEGUROS Y REASEGURO` → BILBAO i `SEGURCAIXA NEGOCI` → SEGURCAIXA (aquesta darrera per decisió expressa: «NEGOCI» era el producte, no la companyia). També hi ha `objecte` (nom del grup, i alhora manera d'unificar dues categories), `poblacio`, `tipus` (etiqueta de la fila, per damunt de la del patró) i `ocult`. L'ajust es busca **tant a la categoria del moviment com al node de la pòlissa**, i es combinen: el municipi se sol desar al node i la companyia a la fulla. L'ajust mana sobre l'arbre.

`g_assegurances_patrons` desa els patrons. Totes dues taules s'editen des de **`/impostos/assegurances/config`**, que llista els patrons i, sota, **un registre per camí de categoria** amb el que n'ha resolt el detector. Els ajustos s'editen **per camí i no per categoria**: el mateix camí existeix a cada compte que l'hagi importat i vol dir el mateix a tots, de manera que desar-ne un escriu una fila per cada categoria del camí i buidar-lo les esborra totes. Qui necessiti distingir dues categories del mateix camí ho ha de fer per tinker. La inclusió manual es fa cercant el camí al servidor (l'arbre té milers de categories i no s'envia sencer).

### Components reutilitzables destacats
- `Services\Concerns\ResolPerArbre`: resolució d'immoble i municipi des de l'arbre de categories, compartida per `TaxesService` i `AssegurancesService` (si divergissin, el mateix immoble sortiria amb dos noms segons la vista). `Http\Controllers\Concerns\CategoriesPerCompte` fa el mateix amb el selector de categories de les dues vistes.
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
              │            Assegurances (pòlisses, vista derivada)     │
              └────────────────────────────────────────────────────────┘
```

## Documentació addicional

Per a detalls específics de cada mòdul, consulta `docs/`:
- `docs/gestio-lloguers.md` — lloguers, contractes, llogaters
- `docs/importacio-moviments.md` — importació Excel/CSV de moviments bancaris
- `docs/gestio-categories.md` — categories jeràrquiques i importació QIF
