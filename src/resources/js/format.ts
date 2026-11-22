/**
 * Com es mostren els números, els imports, les dates i les durades a tota l'aplicació.
 *
 * Abans cada pàgina en tenia la seva còpia —`formatEur` era a tretze fitxers, escrit de tres
 * maneres—, i canviar com es veu un import volia dir canviar-ho a tots. Ara és aquí, i tot el que
 * no té valor (`null`, `undefined`) es mostra com a «—».
 */

const BUIT = '—';
const LOCALE = 'ca-ES';

type Valor = number | null | undefined;

const res = (v: Valor): v is null | undefined => v === null || v === undefined;

/** Un import en euros: «1.234,56 €». */
export const formatEur = (v: Valor): string =>
    res(v) ? BUIT : new Intl.NumberFormat(LOCALE, { style: 'currency', currency: 'EUR' }).format(v);

/** Un número amb un nombre fix de decimals: «1.234» o «12,5». */
export const nombre = (v: Valor, decimals = 0): string =>
    res(v) ? BUIT : v.toLocaleString(LOCALE, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

/** Un percentatge que ja ve en tant per cent (12,3 i no 0,123): «12,30 %». */
export const formatPct = (v: Valor, decimals = 2): string => (res(v) ? BUIT : `${nombre(v, decimals)} %`);

/**
 * Una data ISO (`2026-11-15`) en català: «15 de nov. 2026» (`medium`) o «15 de novembre de 2026» (`long`).
 * Es llegeix a migdia perquè una data sense hora no canviï de dia segons la zona horària.
 */
export const formatData = (iso: string | null | undefined, estil: 'medium' | 'long' = 'medium'): string =>
    iso ? new Intl.DateTimeFormat(LOCALE, { dateStyle: estil }).format(new Date(iso.slice(0, 10) + 'T12:00:00')) : BUIT;

/** Una data ISO com a «15/11/2026», sense passar per `Date`: el text ja diu el dia. */
export const dataCurta = (iso: string | null | undefined): string => {
    if (!iso) return BUIT;
    const [any, mes, dia] = iso.slice(0, 10).split('-');
    return `${dia}/${mes}/${any}`;
};

/**
 * Una durada en segons com a hores i minuts («1:05») o, amb `ambSegons`, també els segons («1:05:09»).
 * Els minuts s'arrodoneixen sobre el total: 3.599 s és «1:00», no «0:60».
 */
export function durada(s: Valor, ambSegons = false): string {
    if (res(s)) return BUIT;
    const dosXifres = (n: number) => String(n).padStart(2, '0');
    if (ambSegons) {
        const total = Math.round(s);
        return `${Math.floor(total / 3600)}:${dosXifres(Math.floor((total % 3600) / 60))}:${dosXifres(total % 60)}`;
    }
    const minuts = Math.round(s / 60);
    return `${Math.floor(minuts / 60)}:${dosXifres(minuts % 60)}`;
}
