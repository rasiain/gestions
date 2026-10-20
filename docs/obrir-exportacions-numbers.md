# Obrir les exportacions dels lloguers amb Numbers

Després de prémer **Exportar** als moviments d’un lloguer, el missatge de fitxer desat ofereix **Veure a la web** i **Obrir amb Numbers**. El segon enllaç obre amb un clic el mateix `.xlsx` desat, sense descarregar-ne una còpia. Es manté la confirmació abans de sobreescriure un fitxer existent.

## Configuració del Mac

Són compatibles Numbers Creator Studio (`com.apple.Numbers`) i la versió anterior de Numbers (`com.apple.iWork.Numbers`).

Cal tenir Numbers, les eines de línia de comandes d’Xcode (`xcode-select --install`, si no estan instal·lades) i Python 3. Des de l’arrel del repositori:

```bash
bash tools/macos/setup.sh
```

Això compila el codi Swift, crea `tools/macos/build/Gestions Launcher.app`, el signa localment i el registra a macOS com a gestor dels enllaços `gestions://`. No el copia a Aplicacions ni instal·la cap servei permanent. El directori `build` no es versiona; el codi i l’instal·lador sí.

Per defecte permet exportacions dins de `~/Documents` i `~/Downloads` (incloses les subcarpetes). Per limitar-ho a les carpetes concretes que fas servir, o autoritzar una altra ubicació, torna a executar la configuració passant **totes** les carpetes permeses:

```bash
bash tools/macos/setup.sh "$HOME/Documents/Lloguers" "$HOME/Downloads"
```

Cada execució substitueix la llista anterior. L’auxiliar només obre fitxers regulars existents amb nom `lloguer-*.xlsx`, dins d’aquestes carpetes; resol els enllaços simbòlics abans de comprovar-ho. No executa ordres enviades per la web. Altres webs també poden intentar activar aquest protocol: les restriccions s’apliquen igualment.

## Ús i comprovació

1. Recarrega la pàgina de Gestions després de compilar el frontend.
2. Entra als moviments d’un lloguer, selecciona l’any i prem **Exportar**.
3. Al missatge de confirmació, prem **Obrir amb Numbers**.
4. Si el navegador demana permís per obrir Gestions Launcher, accepta’l. Segons el navegador, pot oferir recordar la decisió; no es pot garantir eliminar aquesta confirmació.
5. Comprova que Numbers mostra l’Excel de l’any seleccionat.

L’auxiliar s’executa al Mac on està obert el navegador. El fitxer ha d’existir en aquest mateix Mac, a la ruta retornada pel servidor. El Docker Compose actual comparteix Documents i Downloads amb el mateix camí absolut, de manera que això encaixa amb la configuració existent. Des d’un altre ordinador l’obertura local no funciona si no té el fitxer a la mateixa ruta.

Numbers importa el `.xlsx` i pot proposar desar-lo en format `.numbers` quan l’editis. L’exportació de Gestions continua sent `.xlsx`.

## Manteniment

- Si mous el repositori, canvies de Mac o modifiques `Launcher.swift`, torna a executar `setup.sh`.
- Si el navegador no reconeix l’enllaç, repeteix la configuració i torna a provar; pot caldre reiniciar el navegador.
- Si apareix un avís de fitxer no permès, comprova la ruta d’exportació del lloguer i la llista de carpetes del comandament de configuració.
- Per retirar el registre, abans d’eliminar el directori generat:

```bash
/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister -u "$PWD/tools/macos/build/Gestions Launcher.app"
```

## Codi

- `tools/macos/Launcher.swift`: rep el protocol, valida el fitxer i l’obre amb Numbers mitjançant les API de macOS.
- `tools/macos/setup.sh`: compila i registra l’auxiliar.
- `LloguerController::desarExport()`: retorna l’enllaç del fitxer un cop desat.
- `resources/js/Pages/Lloguers/Index.vue`: mostra l’accés a Numbers al missatge de confirmació.
