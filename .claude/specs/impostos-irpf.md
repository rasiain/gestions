# Especificacio: Seccio Impostos al Tauler - IRPF Lloguers

## Resum

Afegir una nova seccio "Impostos" al Tauler (Dashboard) amb una accio per l'IRPF que mostra
una taula resum dels ingressos i despeses dels lloguers agrupats per categoria, amb filtre per any fiscal.

## Fitxers a modificar

### 1. `src/resources/js/Pages/Dashboard.vue`
- Afegir una nova card "Impostos" al grid del Tauler (seguint el patro de les cards existents: Gestions bancaries, Inversions, Lloguers, Configuracio).
- Color suggerit: `red` (per distingir-la de les altres seccions).
- Icona: una relacionada amb impostos/documents fiscals.
- Sub-link: `-> IRPF Lloguers` que apunti a la ruta `impostos.irpf`.

### 2. `src/routes/web.php`
- Afegir dins el grup `auth`:
  ```php
  // Impostos
  Route::get('/impostos/irpf', [ImpostosIrpfController::class, 'index'])->name('impostos.irpf');
  ```
- Afegir el `use` corresponent al controlador.

### 3. `src/app/Http/Controllers/ImpostosIrpfController.php` (NOU)
- Metode `index(Request $request)`:
  - Rep un parametre opcional `any` (per defecte l'any actual).
  - Carrega tots els lloguers amb les seves relacions (`immoble`, `compteCorrent`).
  - Per cada lloguer, consulta els moviments del compte corrent associat filtrats per any:
    - **Ingressos**: moviments que tenen relacio `ingres` amb `lloguer_id` del lloguer.
    - **Despeses**: moviments que tenen relacio `despesa` amb `lloguer_id` del lloguer, agrupades per `categoria` (comunitat, taxes, asseguranca, compres, reparacions, altres).
  - Retorna les dades via Inertia a la pagina `Impostos/Irpf.vue`.
  - Estructura de dades suggerida:
    ```php
    [
        'any' => $any,
        'lloguers' => [
            [
                'id' => ...,
                'nom' => ...,
                'immoble_adreca' => ...,
                'total_ingressos' => ...,          // suma dels imports positius (ingres)
                'total_despeses' => ...,           // suma dels imports negatius (despesa)
                'despeses_per_categoria' => [
                    'comunitat' => ...,
                    'taxes' => ...,
                    'assegurança' => ...,
                    'compres' => ...,
                    'reparacions' => ...,
                    'altres' => ...,
                ],
                'resultat_net' => ...,             // ingressos + despeses
            ],
            // ...
        ],
        'totals' => [
            'total_ingressos' => ...,
            'total_despeses' => ...,
            'despeses_per_categoria' => [ ... ],
            'resultat_net' => ...,
        ],
    ]
    ```

### 4. `src/resources/js/Pages/Impostos/Irpf.vue` (NOU)
- Pagina amb `AuthenticatedLayout` (seguint el patro de les altres pagines).
- **Selector d'any**: un `<select>` o botons per canviar d'any fiscal (recarrega la pagina amb el parametre `any`).
- **Taula principal**:
  - Columnes: Lloguer | Immoble | Ingressos | Comunitat | Taxes | Asseguranca | Compres | Reparacions | Altres | Total Despeses | Resultat Net
  - Una fila per lloguer.
  - Fila final amb els totals.
- Estil coherent amb la resta de l'aplicacio (Tailwind, dark mode).

## Fitxers nous a crear

| Fitxer | Descripcio |
|--------|------------|
| `src/app/Http/Controllers/ImpostosIrpfController.php` | Controlador que prepara les dades IRPF |
| `src/resources/js/Pages/Impostos/Irpf.vue` | Pagina Vue amb la taula resum |

## Ordre d'implementacio

1. Crear `ImpostosIrpfController.php` amb el metode `index`.
2. Afegir la ruta a `web.php`.
3. Crear el directori `Impostos/` i el fitxer `Irpf.vue`.
4. Modificar `Dashboard.vue` per afegir la card d'Impostos.
5. Compilar frontend amb `docker compose exec app npm run build`.
6. Verificar.

## Punts de verificacio

- [ ] La card "Impostos" apareix al Tauler amb el link "IRPF Lloguers".
- [ ] Clicar el link porta a `/impostos/irpf` amb l'any actual per defecte.
- [ ] La taula mostra tots els lloguers amb ingressos i despeses de l'any.
- [ ] Les despeses estan correctament agrupades per les 6 categories (comunitat, taxes, asseguranca, compres, reparacions, altres).
- [ ] El selector d'any permet canviar l'any i la taula es recarrega.
- [ ] La fila de totals suma correctament.
- [ ] El dark mode funciona correctament.

## Notes tecniques

- El camp `categoria` de `MovimentLloguerDespesa` es un string amb valors possibles: `comunitat`, `taxes`, `assegurança`, `compres`, `reparacions`, `altres`.
- Els ingressos es troben a traves de la relacio `MovimentCompteCorrent -> ingres (MovimentLloguerIngres)` on el camp `lloguer_id` identifica el lloguer.
- Les despeses es troben a traves de `MovimentCompteCorrent -> despesa (MovimentLloguerDespesa)` on el camp `lloguer_id` identifica el lloguer.
- L'import del moviment esta al camp `import` de `MovimentCompteCorrent` (positiu per ingressos, negatiu per despeses).
- Cada lloguer te un `compte_corrent_id` que indica a quin compte estan els seus moviments.
