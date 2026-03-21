# Planifica

Invoca l'agent planificador per dissenyar la implementació d'una nova
funcionalitat o canvi.

## Instruccions

1. Invoca l'agent **planificador** passant-li com a context inicial:
   - Qualsevol especificació proporcionada a $ARGUMENTS
   - L'estat actual del projecte (branca, últims commits si és rellevant)

2. L'agent planificador s'encarregarà de:
   - Demanar especificacions si calen
   - Explorar el codi
   - Proposar un pla de canvis
   - Demanar confirmació

Si l'usuari proporciona una descripció a $ARGUMENTS, passa-la directament
com a punt de partida al planificador.
