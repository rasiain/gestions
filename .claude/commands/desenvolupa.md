# Desenvolupa

Implementa una especificació guardada a `.claude/specs/`.

## Instruccions

1. Si s'ha proporcionat una ruta a $ARGUMENTS, passa-la directament a l'agent
   **desenvolupador** com a fitxer d'especificació a executar.

2. Si no s'ha proporcionat cap argument, invoca l'agent **desenvolupador**
   perquè llistin les especificacions disponibles a `.claude/specs/` i
   demani a l'usuari quina vol executar.

3. L'agent desenvolupador s'encarregarà de:
   - Llegir l'especificació
   - Implementar els canvis
   - Compilar si cal
   - Informar del resultat
