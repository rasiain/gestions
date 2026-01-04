# Guia d'ús de Xdebug amb VSCode

## Prerequisits

1. **Extensió de VSCode**: Instal·la l'extensió "PHP Debug" de Xdebug
   - Obre VSCode
   - Ves a Extensions (Cmd+Shift+X)
   - Busca "PHP Debug"
   - Instal·la l'extensió de Felix Becker

## Com utilitzar el debugger

### 1. Iniciar el listener de Xdebug

1. A VSCode, obre el panell de Debug (Cmd+Shift+D)
2. Selecciona "Listen for Xdebug" al menú desplegable
3. Clica el botó verd de Play (o prem F5)
4. Veuràs "XDEBUG" a la barra d'estat inferior

### 2. Posar breakpoints

1. Obre el fitxer PHP on vols debugar (per exemple: `CaixaEnginyersParserService.php`)
2. Clica a l'esquerra del número de línia on vols aturar l'execució
3. Apareixerà un punt vermell (breakpoint)

### 3. Executar el codi

Quan executis una petició web o una comanda artisan, el debugger s'aturarà als breakpoints.

**Exemple per debugar la importació de moviments:**

1. Posa un breakpoint a `src/app/Http/Services/ImportFiles/CaixaEnginyersParserService.php` (línia ~20, al mètode `parse`)
2. Inicia el listener de Xdebug a VSCode (F5)
3. Al navegador, carrega un fitxer i clica "Analitzar fitxer"
4. VSCode s'aturarà al breakpoint

### 4. Controls del debugger

Quan el codi s'aturi al breakpoint:

- **Continue (F5)**: Continua fins al següent breakpoint
- **Step Over (F10)**: Executa la línia actual i va a la següent
- **Step Into (F11)**: Entra dins de la funció que s'està cridant
- **Step Out (Shift+F11)**: Surt de la funció actual
- **Restart (Cmd+Shift+F5)**: Reinicia el debugger
- **Stop (Shift+F5)**: Atura el debugger

### 5. Inspeccionar variables

A l'esquerra del VSCode veuràs:

- **Variables**: Totes les variables locals i globals
- **Watch**: Variables que vols monitoritzar
- **Call Stack**: La pila de crides de funcions
- **Breakpoints**: Tots els breakpoints actius

Pots passar el ratolí per sobre de qualsevol variable al codi per veure el seu valor.

### 6. Debug Console

A la part inferior pots executar codi PHP en temps real:

```php
// Exemple: veure el contingut d'una variable
$movements

// Exemple: executar una funció
count($parsedMovements)

// Exemple: cridar un mètode
$this->someMethod($param)
```

## Exemples pràctics

### Debugar la importació de fitxers

**Fitxer**: `src/app/Http/Controllers/MovementImportController.php`

```php
public function parse(Request $request): JsonResponse
{
    // Breakpoint aquí per veure les dades del request
    $validated = $request->validate([...]);

    // Breakpoint aquí per veure el tipus de banc seleccionat
    $bankType = $validated['bank_type'];

    // Breakpoint aquí per veure les files parseades
    $rows = $this->fileParser->parse($file);

    // Continua...
}
```

### Debugar el parser de Caixa d'Enginyers

**Fitxer**: `src/app/Http/Services/ImportFiles/CaixaEnginyersParserService.php`

```php
public function parse($input, int $compteCorrentId): array
{
    // Breakpoint aquí per veure totes les files del fitxer
    $movements = [];
    $headerFound = false;

    foreach ($input as $index => $row) {
        // Breakpoint aquí per veure cada fila
        if (!$headerFound) {
            // Inspeccioneu com detecta el header
        }

        // Breakpoint aquí per veure com parseja cada moviment
        $movements[] = [
            'data_moviment' => ...,
            'concepte' => ...,
            // etc.
        ];
    }

    return $movements;
}
```

### Debugar la generació de hashes

**Fitxer**: `src/app/Http/Services/MovementImportService.php`

```php
public function processMovements(array $parsedMovements, int $compteCorrentId, ?string $importMode = null): array
{
    // Breakpoint aquí per veure tots els moviments abans de generar hashes
    foreach ($parsedMovements as &$movement) {
        // Breakpoint aquí per veure cada moviment abans de generar el hash
        $movement['hash'] = MovimentCompteCorrent::generateHash(
            $movement['data_moviment'],
            $movement['concepte'],
            $movement['import'],
            $compteCorrentId
        );
        // Breakpoint aquí per veure el hash generat
    }
}
```

## Configuració tècnica

### Fitxers de configuració

- **[Dockerfile.dev](../Dockerfile.dev)**: Instal·la Xdebug 3.4.1 i copia la configuració
- **[docker/php/xdebug.ini](../docker/php/xdebug.ini)**: Configuració d'Xdebug
- **[.vscode/launch.json](../.vscode/launch.json)**: Configuració de VSCode

### Configuració d'Xdebug actual

```ini
xdebug.mode=debug
xdebug.start_with_request=yes
xdebug.client_host=host.docker.internal
xdebug.client_port=9003
xdebug.idekey=VSCODE
xdebug.log=/tmp/xdebug.log
xdebug.log_level=7
```

- **mode=debug**: Activa el debugger pas a pas
- **start_with_request=yes**: Inicia automàticament amb cada petició
- **client_host=host.docker.internal**: Connecta amb VSCode a la màquina host
- **client_port=9003**: Port estàndard d'Xdebug 3
- **idekey=VSCODE**: Identificador per VSCode

## Troubleshooting

### El debugger no es connecta

1. Verifica que el contenidor està executant-se:
   ```bash
   docker compose ps
   ```

2. Verifica que Xdebug està actiu:
   ```bash
   docker compose exec app php -v
   ```
   Hauries de veure: `with Xdebug v3.4.1`

3. Comprova la configuració d'Xdebug:
   ```bash
   docker compose exec app php --ri xdebug
   ```
   Hauries de veure `Step Debugger => ✔ enabled`

4. Comprova els logs de Xdebug:
   ```bash
   docker compose exec app cat /tmp/xdebug.log
   ```

### Els breakpoints no funcionen

1. Assegura't que el fitxer està dins de `src/`
2. Verifica que el path mapping és correcte a `.vscode/launch.json`
3. Prova a reiniciar el debugger (Stop + Start)

### El codi va massa lent

Xdebug pot alentir l'execució. Si no estàs debugant activament:

1. Atura el listener de Xdebug a VSCode (Shift+F5)
2. O desactiva Xdebug temporalment: comenta les línies a `docker/php/xdebug.ini`

## Consells

- **Conditional breakpoints**: Clica dret sobre un breakpoint → "Edit Breakpoint" → pots afegir una condició (ex: `$index > 10`)
- **Logpoints**: Com breakpoints però només mostren un missatge sense aturar l'execució
- **Hit count**: Atura només després de X iteracions
- **Watch expressions**: Afegeix expressions que vols monitoritzar constantment

## Recursos

- [Documentació oficial de Xdebug](https://xdebug.org/docs/)
- [VSCode PHP Debug Extension](https://marketplace.visualstudio.com/items?itemName=xdebug.php-debug)
