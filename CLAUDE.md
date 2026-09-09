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
- **`g_comptes_corrents.tipus`**: `corrent`, `fons_inversio`, `pla_pensions`, `renda_fixa` o `capital_social`. Tots menys `corrent` no tenen moviments bancaris —el seu detall són les aportacions o els valors declarats, a les pantalles de Fons d'Inversió, Plans de Pensions, Renda Fixa i Capital social— i a les llistes van junts sota el grup **Inversions**, que és com se'n diu al Dashboard. Fins a la migració `2026_08_22_000001` els plans de pensions també eren `fons_inversio`: com que el tipus no els distingia, les dues pantalles oferien els mateixos comptes en crear un contracte i res no impedia penjar un contracte de pensions d'un compte de fons.
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

### Model 184 (comunitats de béns)

`Model184Service` munta la declaració d'atribució de rendes d'una comunitat de béns: un registre per immoble llogat (clau C, per referència cadastral) i el repartiment entre comuners. Afecta només els lloguers els contractes dels quals tenen una `ComunitatBens` com a arrendadora.

**El 184 fa dues reparticions que no coincideixen**: el **rendiment** va per **percentatge de participació** i les **retencions** per **quota de titularitat**. La diferència és l'amortització, que cada comuner es dedueix de la seva:

```
base repartible   = ingressos íntegres − (despeses − amortització)
rendiment comuner = quota × base repartible − amortització pròpia
% participació    = rendiment comuner / suma dels rendiments
```

L'**amortització de cada comuner no és derivable** de les dades del projecte: depèn del valor i la data d'adquisició de la seva quota (compra, herència…) i `g_immobles` només desa un joc de valors per immoble. És una dada d'entrada, al pivot **`g_propietaris_immobles`** (`quota`, `amortitzacio_anual`), que ja porta `data_inici`/`data_fi` i per tant ja modela els canvis de comuner.

**Els ingressos i les retencions surten de les factures**, no dels moviments classificats: les factures són la sèrie completa de l'any i porten la retenció calculada, mentre que la classificació dels cobraments pot anar endarrerida. Les despeses, en canvi, sí que surten de la classificació — i de les línies d'ingrés que no són repercussió, com a l'IRPF.

**La casella del 184 no és una classificació nova.** Es dedueix de la categoria de la despesa amb la mateixa taula que ja la tradueix al compte del PGC (`g_categoria_lloguer_fiscal.casella_184`). A `g_moviment_lloguer_despesa.casella_184` només s'hi desa l'excepció: `null` = la del mapatge, `0` = fora de la declaració. Comprovat contra una declaració real: les caselles surten soles de la classificació existent, i les que no quadraven era per una despesa classificada amb el criteri del llibre d'IVA (una prima d'assegurança com a `comissions`).

**La declaració es materialitza** (`g_184_declaracions` i les seves filles): recalcular-la anys després no dona el mateix —una despesa canvia de categoria, una factura es corregeix— i el que s'ha de poder consultar és el que va anar a Hisenda. Els noms (del lloguer, dels comuners, la referència cadastral) s'hi desen **copiats i no per clau forana**, pel mateix motiu. Quan el càlcul d'avui difereix del desat, la pantalla ho diu casella per casella: una despesa reclassificada mou diners d'una casella a una altra **sense tocar cap total**, i comparant només els totals no es veuria.

La pantalla avisa del que cal repassar abans de declarar: quotes que no sumen 100, comuners sense amortització, exercici sense factures, despeses que superen els ingressos (quasi sempre vol dir que falten factures) i factures encara no cobrades (els ingressos són de tot l'any i les despeses només de les pagades). Especificació completa a `.claude/specs/model-184-comunitats-bens.md` (no versionat).

Pendent: el fitxer de presentació de l'AEAT, els dies d'arrendament i la casella 3 (interessos i despeses de reparació pendents), que no s'ha fet servir mai.

### Renda fixa

**El títol és el producte, el contracte és el que en tens tu** (`g_rf_titols` / `g_rf_contractes`), com el fons i el seu contracte. L'analogia, però, es trenca en el que importa: la cotització d'un fons viu al *fons* perquè és compartida, mentre que el valor patrimonial de la renda fixa es desa **per contracte** i en euros, que és com ve a l'extracte. El títol, doncs, no entra en cap càlcul: només hi posa l'ISIN, el nom i l'emissor, i permet que el mateix producte en dos comptes surti amb el mateix nom.

Per això **no hi ha formulari de títol nou**: es crea des del formulari del contracte, que és quan se sap l'ISIN. Un ISIN que ja és al catàleg no és cap error de duplicat sinó el mateix producte comprat un altre cop, i s'hi reaprofita el títol **sense tocar-ne el nom** —el nom el comparteixen tots els seus contractes i s'edita al catàleg, que queda només per consultar i corregir. La pantalla avisa abans de desar quan l'ISIN escrit ja hi és.

### Capital social

Les aportacions que fan soci d'una cooperativa de crèdit (`/capital-social`). Va com la renda fixa —un contracte per compte, una sèrie de valors per data i els rendiments cobrats— amb dues diferències que imposa l'extracte:

- **No hi ha catàleg de productes.** El capital social no és cap valor de mercat amb ISIN: és de l'entitat del compte, i el «Contracte» que diu l'extracte és el número del compte mateix. Per això el compte és de tipus `capital_social` i el nom de la posició surt d'ell.
- **El valor són dos números, no un import**: `titols` i `valor_unitari`, tal com ve l'extracte («Nre. títols 11 · Valor Nominal Unitari 100,00»). El total **no es desa**, que és el producte dels dos i desat només podria contradir-los. El «N. titulars» tampoc: els titulars surten del compte, com a tot el grup d'inversions.

Sense cap valor declarat no val res —aquí no hi ha cap nominal de contracte que serveixi de mínim, com sí que en té la renda fixa— i per això la seva sèrie als totals mobiliaris comença a zero fins al primer valor.

**El nominal es revaloritza.** Entre dos valors seguits el total pot pujar per dues raons ben diferents: perquè hi ha **títols nous** (una aportació) o perquè els que ja hi eren **valen més** (una revaloració, que al banc arriba com un moviment «REVALORACIO TITOLS»: 11 títols de 100 que passen a 102 són 22 €). Les dues es **dedueixen dels valors** i no es desen —els títols nous al preu que hi havia, el canvi de preu a tots els títols—, i per construcció sumen exactament la diferència de total, cosa que és un test. Un capital social que només creix per revaloració i un que creix perquè s'hi ha aportat diners són coses diferents, i la pantalla les separa.

### Totals mobiliaris

`/inversions/totals-mobiliaris` suma en una sola taula el que les pantalles d'inversions diuen per separat: comptes corrents, fons, plans de pensions, renda fixa i capital social. `PatrimoniMobiliariService` posa les quatre en la mateixa forma —un nom, un valor i com es reparteix— i cadascuna es valora **com a la seva pantalla**: el compte pel saldo, el fons i el pla per participacions × darrera cotització, la renda fixa per `valorAData()` (i si no hi ha valor declarat, pel nominal) i el capital social per títols × nominal unitari. Els comptes de tipus `fons_inversio`, `pla_pensions`, `renda_fixa` i `capital_social` **no hi entren com a comptes**: el que valen són els contractes que hi pengen, i comptar-los tots dos els doblaria.

El repartiment és **a parts iguals** entre els titulars del compte, amb el residu per a l'últim, que és el que ja fan els tres «Totals per titular» existents (`CompteCorrentController`, `FonsInversioController`, `RendaFixaController`). Comprovat contra dos d'ells: dona els mateixos números.

La tria —quins titulars, quines posicions i quin moment se sumen— **es resol al client**: el servidor envia totes les posicions amb el repartiment ja fet i la pantalla filtra. Són poques dades, i així cada casella que es marca respon sense tornar al servidor. Marcar titulars no és només filtrar la suma: **amaga les posicions que no són seves**, perquè la llista de caselles no ofereixi comptes i contractes de qui no s'està mirant.

**Cada posició porta també què valia a final de cada mes**, des del primer mes amb dades. No cal desar-ho enlloc, perquè les quatre fonts es poden reconstruir: el saldo d'un compte a una data és el `saldo_posterior` del darrer moviment fins aquell dia (llegit amb un cursor de quatre columnes, que hi ha desenes de milers de moviments), i el d'un fons o un pla, les participacions aportades fins aleshores per la cotització vigent. Els mesos sense cap dada arrosseguen el valor del mes anterior. Dues excepcions que el zero no diria bé: **abans de la primera cotització coneguda** s'hi val aquesta mateixa —el contracte hi era encara que la sèrie de valors no arribi tan enrere—, mentre que **abans de comprar un títol de renda fixa** el valor sí que és zero, perquè aleshores no existia. La punta de la sèrie (el mes en curs) dona sempre el mateix que la valoració d'avui, i això és un test.

La pantalla en treu el **tall** (la taula per titular a qualsevol final de mes o d'any, no només avui) i la **gràfica d'evolució**: una barra per període dins d'un rang, amb el total dels titulars marcats en **una sola sèrie**. No es desglossa per titular a posta —per veure'n un, es marca ell sol— i clicar una barra mou el tall de la taula. Sota la gràfica hi ha els seus mateixos números en una **taula de detall per període** (una fila per barra, amb el desglossament per font i la variació contra el període anterior), que va del més recent al més antic com la resta de taules del projecte, mentre la gràfica va cap endavant. El repartiment a parts iguals es repeteix al client perquè el pugui refer a qualsevol tall sense tornar al servidor.

### Vehicles (a motor i bicis)
`g_vehicles` és el catàleg, i n'hi ha dues llistes sobre la mateixa taula: **Vehicles a motor** (`/vehicles-motor`, tipus `cotxe` i `moto`, els que tenen matrícula, ITV i combustible) i **Bicis** (`/bicis`). La secció es deia «Cotxes» i es va reanomenar el 2026-09-06, perquè hi han conviscut sempre cotxes i motos; a dins, `cotxe` continua sent un tipus de vehicle. La ruta i el mètode van sense la preposició —`vehicles-motor.index`, `VehicleController::vehiclesMotor()`— per a anar amb les taules, que ja es deien `g_vehicles_motor_*`. Les bicis no tenen `combustible`. Dues taules de despesa, totes dues amb `moviment_id` opcional cap al moviment bancari:

- **`g_vehicles_motor_repostatges`**: només el que s'apunta a mà —data, `km_totals` del comptador, `preu_litre`, `cost`. Litres, quilòmetres fets i consum es **calculen sempre**, mai es desen: així estava al full de càlcul d'on venen les dades i comparar-los amb les seves columnes calculades és com es valida la importació (`vehicles:importa-repostatges --prova`). `diposit_ple` marca els que no serveixen per mesurar consum.
- **`g_vehicles_motor_despeses`**: reparacions, ITV, assegurança, impost de circulació i `altres`. `km_totals` és opcional —l'assegurança no passa pel taller—, però quan hi és **també compta com a lectura del comptador**.

El resum per any (`VehicleController::perAny()`) creua les dues taules i en treu **dues mesures de quilòmetres**, totes dues «darrera lectura de l'any menys darrera lectura d'abans» però amb lectures diferents:

- **`km`** només mira els repostatges. Es queda curta —del darrer ple a Cap d'Any encara s'hi roda—, però és l'únic tram que té els litres al costat: barrejar-hi la lectura del taller donava un L/100 km que no volia dir res, perquè hi sumava quilòmetres sense litres. És la que divideix el `consum`.
- **`km_estimats`** hi afegeix les lectures apuntades a les despeses (taller, ITV): la millor estimació del que s'ha rodat, i la que divideix el `cost_km`.

Un any sense cap lectura pròpia —només l'assegurança, posem— té `km` i `km_estimats` a `null`, no pas a zero: no se sap fins on va arribar el comptador. El primer any tampoc no té cap lectura anterior amb què comparar.

La pestanya **Gràfiques** dibuixa el que diuen els repostatges (chart.js + vue-chartjs, com als fons i als plans de pensions): consum de cada ple amb la mitjana anual sobreposada com a tram pla, dies entre repostatges i lectura del comptador. Tot es calcula al client a partir de les mateixes dades que ja porten les taules —cap consulta nova—, i el tram de la mitjana va del darrer repostatge de l'any anterior al darrer de l'any, que és exactament el que ha mesurat `perAny()`. Les dues sèries són `#0284c7` i `#d97706` a les dues aparences: passen les comprovacions de contrast i de daltonisme sobre fons clar i fosc, i així només cal canviar textos i quadrícula segons `prefers-color-scheme` (que és com Tailwind fa el mode fosc en aquest projecte, sense classe `dark` a l'arrel).

Els CSV del Numbers van del més recent al més antic i hi ha dies amb dos repostatges: l'ordenació desempata per `km_totals`, que només puja.

### Formularis d'Inertia: `data` és un nom prohibit

**Cap camp d'`useForm` no es pot dir `data`.** `form.data()` és un mètode del formulari —retorna els camps— i un camp amb aquest nom el trepitja: el `v-model` posa la funció dins de l'`<input>` (`The specified value "data(){...}" does not conform to "yyyy-MM-dd"`) i l'enviament peta amb `data is not a function` **abans de sortir del navegador**, de manera que al servidor no hi arriba res: ni petició als logs, ni error, ni fila a la base de dades. El botó sembla mort i no hi ha cap pista de per què.

La convenció és dir-ne **`dia`** al formulari i enviar-lo com a `data` amb `transform` (`Vehicles/Index.vue` en té la funció `perAlServidor`), que així el servidor no canvia. Hi van caure alhora els valors i els cupons de renda fixa, els valors i els rendiments de capital social, i els repostatges i les despeses de vehicles.

D'aquí també ve una regla de disseny d'aquests formularis: **el botó de desar no es deshabilita** quan falta alguna cosa. Un botó apagat no diu què li falta, i el mateix silenci tapava el problema; en comptes d'això es clica sempre i, si falta un camp, es diu quin. Els imports i els números van en camps de **text**, no `type="number"`: un camp numèric rebutja en silenci el que no entén («100,00» amb coma, segons el navegador) i el deixa buit per dins encara que a la pantalla s'hi vegi el text.

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
              │            Model 184 (comunitats de béns)              │
              └────────────────────────────────────────────────────────┘
```

## Documentació addicional

Per a detalls específics de cada mòdul, consulta `docs/`:
- `docs/gestio-lloguers.md` — lloguers, contractes, llogaters
- `docs/importacio-moviments.md` — importació Excel/CSV de moviments bancaris
- `docs/gestio-categories.md` — categories jeràrquiques i importació QIF
