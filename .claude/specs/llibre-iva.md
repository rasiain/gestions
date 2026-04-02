# Exportacio del Llibre de registre d'IVA (xlsx)

## Context

Per complir amb les obligacions fiscals dels lloguers no-habitatge, cal poder exportar el Llibre de registre d'IVA en format xlsx amb 4 pestanyes: factures emeses, factures rebudes (buida), despeses i amortitzacions (buida). Aixo requereix afegir camps que falten al model de dades (NIF a persones, adreca estructurada a proveidors, tipus de despesa fiscal a despeses).

## Canvis

### A. Model de dades

#### A1. NIF a persones

##### Fitxers a modificar
- `src/app/Models/Persona.php` -- afegir `'nif'` a `$fillable`
- `src/app/Http/Requests/PersonaRequest.php` -- afegir regla `'nif' => ['nullable', 'string', 'max:20']` amb missatges
- `src/resources/js/Pages/Persones/Index.vue` -- afegir camp `nif` a la interficie `Persona`, al formulari (`useForm`), al modal (input), a la taula (columna) i al `openEditModal`

##### Fitxers nous
- `src/database/migrations/2026_04_02_000001_add_nif_to_g_persones.php` -- `$table->string('nif', 20)->nullable()->after('cognoms')`

#### A2. Adreca estructurada a proveidors

##### Fitxers a modificar
- `src/app/Models/Proveidor.php` -- afegir `'codi_postal', 'poblacio', 'provincia', 'pais'` a `$fillable`
- `src/app/Http/Requests/ProveidorRequest.php` -- afegir regles nullable per als 4 camps nous amb missatges
- `src/resources/js/Pages/Proveidors/Index.vue` -- afegir els 4 camps a la interficie, formulari, modal i taula. El camp `pais` amb valor per defecte 'Espana'

##### Fitxers nous
- `src/database/migrations/2026_04_02_000002_add_address_fields_to_g_proveidors.php` -- afegir `codi_postal` (string 10), `poblacio` (string 100), `provincia` (string 100), `pais` (string 100, default 'Espana'), tots nullable, despres de `adreca`

#### A3. Tipus de despesa fiscal

##### Fitxers nous
- `src/database/migrations/2026_04_02_000003_create_g_tipus_despesa_fiscal_table.php` -- taula amb `id`, `codi` (string 10 unique), `descripcio` (string 255), `timestamps`
- `src/database/migrations/2026_04_02_000004_add_tipus_despesa_fiscal_id_to_g_moviment_lloguer_despesa.php` -- afegir `tipus_despesa_fiscal_id` FK nullable referint `g_tipus_despesa_fiscal`
- `src/database/seeders/TipusDespesaFiscalSeeder.php` -- inserir: `623` (Serveis de professionals independents), `625` (Primes d'assegurances), `626` (Serveis bancaris i similars), `631` (Altres tributs)
- `src/app/Models/TipusDespesaFiscal.php` -- model amb `$table = 'g_tipus_despesa_fiscal'`, `$fillable = ['codi', 'descripcio']`

##### Fitxers a modificar
- `src/app/Models/MovimentLloguerDespesa.php` -- afegir `'tipus_despesa_fiscal_id'` a `$fillable`, afegir relacio `tipusDespesaFiscal(): BelongsTo`
- `src/app/Http/Controllers/MovimentClassificacioController.php` -- afegir `'tipus_despesa_fiscal_id' => ['nullable', 'integer', 'exists:g_tipus_despesa_fiscal,id']` a `validationRules()`, afegir-lo al `create()` de despesa (linia 97) i al `movimentResponse()` (linia 134)
- `src/app/Http/Controllers/LloguerController.php` -- al metode `moviments()`, incloure `tipus_despesa_fiscal_id` a la resposta de `despesa` (linia 220), i passar `tipusDespesaFiscal` com a dades addicionals a la resposta
- `src/resources/js/Pages/Lloguers/Index.vue` -- afegir `tipus_despesa_fiscal_id` a la interficie `MovimentDespesa`, al `classificacioDespesa` ref, al formulari de classificacio (select amb els tipus), i al body de `saveClassificacio`

### B. Exportacio xlsx

##### Fitxers nous
- `src/app/Http/Controllers/LlibreIvaController.php` -- controlador amb metode `exportar(Lloguer $lloguer, Request $request): StreamedResponse`

##### Fitxers a modificar
- `src/routes/web.php` -- afegir ruta `Route::get('/lloguers/{lloguer}/exportar-llibre-iva', [LlibreIvaController::class, 'exportar'])->name('lloguers.exportar-llibre-iva')` dins del grup auth
- `src/resources/js/Pages/Lloguers/Index.vue` -- afegir boto "Llibre IVA" al costat del boto "Exportar" existent, visible nomes si `!selectedLloguer.es_habitatge`, amb href `/lloguers/${id}/exportar-llibre-iva?any=${any}`

#### Detall del controlador `LlibreIvaController@exportar`

Seguir el patro de `LloguerController@exportar` (mateixos imports PhpSpreadsheet, mateixa StreamedResponse).

**Dades a carregar:**
- Lloguer amb `immoble`, `contractes.llogaters`, `contractes.arrendador.arrendadorable`
- Contracte actiu a la data: el primer contracte on `data_inici <= fi_any` i (`data_fi IS NULL` o `data_fi >= inici_any`)
- Arrendador via contracte actiu: `$contracte->arrendador->arrendadorable` (pot ser Persona o ComunitatBens)
- Nom empresa: si Persona -> `nom cognoms`, si ComunitatBens -> `nom`
- NIF empresa: si Persona -> `nif`, si ComunitatBens -> `nif`
- Factures: `Factura::where('lloguer_id', ...)->where('any', $any)->orderBy('mes')` 
- Despeses: `MovimentLloguerDespesa::where('lloguer_id', ...)->whereHas('moviment', fn($q) => $q->whereYear('data_moviment', $any))->with('moviment', 'proveidor', 'tipusDespesaFiscal')->orderBy via join amb moviment`

**Pestanya 1: "Llibre factures emeses"**
- Fila 1: "Llibre de factures emeses" (merge A1:N1, bold, centrat)
- Fila 2: "Empresa: [nom empresa]"
- Fila 3: "NIF: [nif empresa]"
- Fila 4: "Any [any]"
- Fila 6: "RECEPTOR" (merge sobre columnes M-N)
- Fila 7 (capcaleres): NUM. REG. (A) | NUMERO (B) | DATA (C) | CONCEPTE (D) | % IMPUTACIO (E) | BASE IMPONIBLE IMPUTABLE (F) | % RETENCIO (G) | RETENCIO (H) | TIPUS IMPOST (I) | IMPOST IMPUTABLE (J) | TOTAL FRA. (K) | TIPUS OPERACIO (L) | NOM O RAO SOCIAL (M) | NIF (N)
- Dades (a partir de fila 8): una fila per factura
  - NUM. REG.: index sequencial (1, 2, 3...)
  - NUMERO: `$factura->numero_factura`
  - DATA: `$factura->data_emissio` formatat dd/mm/yyyy
  - CONCEPTE: "Lloguer mensual del local [adreca immoble]"
  - % IMPUTACIO: 1
  - BASE IMPONIBLE IMPUTABLE: `$factura->base`
  - % RETENCIO: `$factura->irpf_percentatge / 100` (ex: 0.19 per 19%)
  - RETENCIO: `$factura->irpf_import`
  - TIPUS IMPOST: "21% I.V.A." (o el % real de la factura)
  - IMPOST IMPUTABLE: `$factura->iva_import`
  - TOTAL FRA.: `$factura->total`
  - TIPUS OPERACIO: "Nacional"
  - NOM O RAO SOCIAL: primer llogater del contracte actiu a la data de la factura (`nom cognoms`)
  - NIF: `$llogater->identificador`
- Fila totals: "TOTALS" a A, sumes a F (base), H (retencio), J (iva), K (total)

**Pestanya 2: "Llibre factures rebudes"**
- Mateixa capcalera (empresa, NIF, any) a files 1-4
- Fila 6: "EMISOR" (merge sobre columnes de nom/NIF)
- Fila 7: NUM. REG. | NUMERO | FECHA | % IMPUTACIO | BASE IMPONIBLE IMPUTABLE | TIPO IMPUESTO | IMPUESTO IMPUTABLE | TIPO OPERACIO | NOMBRE O RAZON SOCIAL | NIF | CRITERIO DE CAJA
- Sense dades
- Fila totals buida amb capcaleres "21% I.V.A." i "10% I.V.A."

**Pestanya 3: "Despeses"**
- Sense capcalera d'empresa
- Fila 1: buit
- Fila 2: buit
- Fila 3: "EMISOR" (merge sobre columnes de nom/NIF/adreca/CP/poblacio/provincia/pais/telefon)
- Fila 4 (capcaleres): NUM. REG. (A) | NUMERO (B, buit) | DATA (C) | CONCEPTE (D) | IMPORT (E) | % IMPUTABLE (F) | TIPO GASTO (G) | DESC. TIPO GASTO (H) | NOMBRE O RAZON SOCIAL (I) | NIF (J) | DIRECCION (K) | CODIGO POSTAL (L) | POBLACION (M) | PROVINCIA (N) | PAIS (O) | TELEFONO (P) | NOTAS (Q)
- Dades (a partir de fila 5): una fila per MovimentLloguerDespesa amb moviment i proveidor
  - NUM. REG.: index sequencial
  - NUMERO: buit
  - DATA: data_moviment del moviment bancari (dd/mm/yyyy)
  - CONCEPTE: concepte del moviment (via relacio concepte o concepte_original)
  - IMPORT: valor absolut del moviment (`abs($moviment->import)`)
  - % IMPUTABLE: 1
  - TIPO GASTO: `$despesa->tipusDespesaFiscal->codi` o buit
  - DESC. TIPO GASTO: `$despesa->tipusDespesaFiscal->descripcio` o buit
  - NOMBRE O RAZON SOCIAL: `$despesa->proveidor->nom_rao_social` o buit
  - NIF: `$despesa->proveidor->nif_cif` o buit
  - DIRECCION: `$despesa->proveidor->adreca` o buit
  - CODIGO POSTAL: `$despesa->proveidor->codi_postal` o buit
  - POBLACION: `$despesa->proveidor->poblacio` o buit
  - PROVINCIA: `$despesa->proveidor->provincia` o buit
  - PAIS: `$despesa->proveidor->pais` o buit
  - TELEFONO: `$despesa->proveidor->telefons` o buit
  - NOTAS: `$despesa->notes` o buit
- Fila totals: "TOTALS" a A, suma imports a E

**Pestanya 4: "Libro de Amortizaciones"**
- Capcalera amb empresa, NIF, any (com pestanya 1)
- Sense dades

**Estils:** seguir el patro de `LloguerController@exportar` -- headerStyle amb fons gris fosc i text blanc, totalStyle amb borda superior, format euro `#,##0.00`, amplades de columna adequades.

### C. Boto a la vista

##### Fitxers a modificar
- `src/resources/js/Pages/Lloguers/Index.vue` -- afegir un link `<a>` amb text "Llibre IVA" just despres del boto "Exportar" existent (linia ~1311), amb:
  - `v-if="!selectedLloguer.es_habitatge"`
  - `:href="/lloguers/${selectedLloguer.id}/exportar-llibre-iva?any=${movimentsFilterAny}"`
  - `target="_blank"`
  - Estil verd (bg-green-600 hover:bg-green-700) per diferenciar-lo del boto exportar ambar

## Ordre d'implementacio

1. Migracio A1: afegir `nif` a `g_persones`
2. Model + Request + Vista Persona (afegir camp `nif`)
3. Migracio A2: afegir camps adreca a `g_proveidors`
4. Model + Request + Vista Proveidor (afegir 4 camps)
5. Migracio A3a: crear taula `g_tipus_despesa_fiscal`
6. Seeder `TipusDespesaFiscalSeeder` + executar-lo
7. Model `TipusDespesaFiscal`
8. Migracio A3b: afegir `tipus_despesa_fiscal_id` a `g_moviment_lloguer_despesa`
9. Actualitzar `MovimentLloguerDespesa` model (fillable + relacio)
10. Actualitzar `MovimentClassificacioController` (validacio + create + response)
11. Actualitzar `LloguerController@moviments` (resposta despesa + llistar tipus despesa fiscal)
12. Actualitzar `Lloguers/Index.vue` (formulari classificacio amb select tipus fiscal)
13. Crear `LlibreIvaController` amb metode `exportar`
14. Afegir ruta a `web.php`
15. Afegir boto "Llibre IVA" a `Lloguers/Index.vue`
16. Compilar frontend: `docker compose exec app npm run build 2>&1`

## Verificacio

- Executar migracions: `docker compose exec app php artisan migrate`
- Executar seeder: `docker compose exec app php artisan db:seed --class=TipusDespesaFiscalSeeder`
- Compilar frontend: `docker compose exec app npm run build 2>&1`
- Verificar que la pagina Persones mostra el camp NIF i permet editar-lo
- Verificar que la pagina Proveidors mostra els camps d'adreca nous i permet editar-los
- Verificar que al classificar una despesa d'un lloguer no-habitatge, apareix el select de tipus despesa fiscal
- Verificar que el boto "Llibre IVA" nomes apareix per a lloguers no-habitatge
- Descarregar l'xlsx per un lloguer no-habitatge amb factures i despeses, comprovar les 4 pestanyes
- Comprovar que les dades de la pestanya "Llibre factures emeses" coincideixen amb les factures del lloguer
- Comprovar que la pestanya "Despeses" mostra les despeses amb proveidor, tipus fiscal i adreca correctes
