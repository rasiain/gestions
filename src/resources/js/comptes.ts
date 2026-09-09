/**
 * Com s'agrupen i com s'ordenen els comptes corrents, en un sol lloc.
 *
 * La llista de comptes i el selector de la pàgina de moviments ensenyen els
 * mateixos tres grups. Si el criteri estigués escrit dues vegades, el mateix
 * compte acabaria sortint en llocs diferents segons la pantalla.
 */

/** El mínim per agrupar i ordenar; cada pàgina hi afegeix els camps que li calen. */
export interface CompteOrdenable {
    compte_corrent: string;
    nom: string | null;
    entitat: string | null;
    tipus: string | null;
    lloguer_nom: string | null;
}

/** Fons, plans de pensions, renda fixa i capital social: coses diferents, un sol grup. */
export const esInversio = (c: CompteOrdenable): boolean =>
    c.tipus === 'fons_inversio' || c.tipus === 'pla_pensions'
    || c.tipus === 'renda_fixa' || c.tipus === 'capital_social';

/** El nom que es llegeix a la fila: el del compte si en té, i si no el número. */
export const nomVisible = (c: CompteOrdenable): string => c.nom || c.compte_corrent;

/**
 * Per entitat i, dins de l'entitat, pel nom visible.
 *
 * Amb `sensitivity: 'base'` els accents i les majúscules no parteixen l'ordre, i
 * un compte sense entitat compta com a cadena buida en comptes de trencar-lo.
 */
export const perEntitatINom = (a: CompteOrdenable, b: CompteOrdenable): number =>
    (a.entitat || '').localeCompare(b.entitat || '', 'ca', { sensitivity: 'base' })
    || nomVisible(a).localeCompare(nomVisible(b), 'ca', { sensitivity: 'base' });
