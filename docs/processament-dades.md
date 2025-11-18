# Processament de fitxers de dades

## Accés

Dashboard → "Processar fitxer de dades"

## Formats suportats

- Excel (.xlsx, .xls)
- CSV (.csv, .txt) - detecta automàticament el delimitador
- HTML (.html, .xls que contenen taules HTML)

El format es detecta automàticament analitzant el contingut del fitxer.

## Estructura

### Backend

- `DataFileController` - Controlador principal
- `FileParserService` - Parseja els diferents formats
- `FileAnalyzerService` - Detecta capçaleres i informació del compte

### Frontend

- `DataFileProcessor.vue` - Interfície de pujada i visualització

### API

```
POST /api/data/process
```

Paràmetres:
- `excel_file` (required) - Fitxer a processar

Resposta:
- `account_info` - IBAN/CCC detectat
- `header_info` - Informació extreta de la capçalera
- `headers` - Noms de les columnes
- `transactions` - Dades processades

## Detecció automàtica

El sistema detecta automàticament:

1. **Format del fitxer** - Magic bytes per Excel, patrons HTML per taules
2. **Delimitador CSV** - Tab, coma, punt i coma, pipe
3. **Fila de capçalera** - Busca la fila amb més contingut textual
4. **Compte bancari** - Patrons IBAN i CCC
