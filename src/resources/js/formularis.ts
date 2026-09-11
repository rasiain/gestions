/**
 * El que costa d'encertar dels formularis d'aquestes pantalles, en un sol lloc.
 *
 * Són tres coses que ja han fallat en producció i que, escrites dues vegades, tornarien a
 * fallar només a una de les dues: llegir un número tal com s'escriu aquí, dir què falta
 * quan no es pot desar, i esquivar el nom de camp que Inertia té ocupat.
 */

/**
 * El número que s'ha escrit, o null si no n'hi ha cap de vàlid.
 *
 * Els camps d'import van en `type="text"` a posta: un `input type=number` rebutja en
 * silenci el que no entén —«100,00» amb coma, segons el navegador i el seu idioma— i el
 * deixa buit per dins encara que a la pantalla s'hi vegi el text, de manera que el botó de
 * desar no s'activa mai i no hi ha manera de saber per què.
 */
export const num = (v: number | string | null): number | null => {
    if (v === null) return null;

    const text = String(v).trim().replace(/\s/g, '');
    if (text === '') return null;

    // Amb coma, el punt només pot ser separador de milers: «1.100,50»
    const n = Number(text.includes(',') ? text.replace(/\./g, '').replace(',', '.') : text);

    return Number.isFinite(n) ? n : null;
};

/**
 * Què falta d'omplir, o null si no falta res.
 *
 * Els botons de desar d'aquests formularis no es deshabiliten: un botó apagat no diu què
 * li falta, i el mateix silenci ja va tapar un error durant tota una tarda. Es clica
 * sempre i, si falta alguna cosa, es diu quina.
 *
 * @param  data   la data escrita, que hi és a tots aquests formularis
 * @param  camps  el número ja llegit i com se'n diu a la pantalla
 */
export const queFalta = (data: string, camps: Array<[number | null, string]>): string | null => {
    const buits = camps.filter(([valor]) => valor === null).map(([, nom]) => nom);
    if (!data) buits.unshift('la data');

    return buits.length ? 'Falta ' + buits.join(' i ') + '.' : null;
};

/**
 * Al formulari el camp de la data es diu `dia`, i al servidor `data`.
 *
 * `data` és un mètode d'`useForm` —`form.data()` són els camps— i un camp que es digui
 * així el trepitja: l'input rep la funció com a valor i l'enviament peta amb «data is not
 * a function», sense que arribi cap petició al servidor.
 */
export const perAlServidor = <T extends { dia: string }>({ dia, ...dades }: T) => ({ ...dades, data: dia });
