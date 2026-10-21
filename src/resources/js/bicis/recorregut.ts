/**
 * Peces comunes de les pàgines que dibuixen recorreguts de bici (la sortida i les rutes):
 * llegir polilínies, el pendent de cada punt i l'escala de colors per pendent.
 */

/** Els dos colors de sèrie del projecte: validats en clar i en fosc. */
export const SERIE_BLAU = '#0284c7';
export const SERIE_AMBRE = '#d97706';

/** Polilínia codificada de Google (5 decimals) → [lat, lon]. */
export function decodifica(codi: string): [number, number][] {
    const punts: [number, number][] = [];
    let i = 0, lat = 0, lon = 0;
    const valor = () => {
        let resultat = 0, despl = 0, b: number;
        do {
            b = codi.charCodeAt(i++) - 63;
            resultat |= (b & 0x1f) << despl;
            despl += 5;
        } while (b >= 0x20 && i < codi.length);
        return resultat & 1 ? ~(resultat >> 1) : resultat >> 1;
    };
    while (i < codi.length) {
        lat += valor();
        lon += valor();
        punts.push([lat / 1e5, lon / 1e5]);
    }
    return punts;
}

/** Amplada del tram sobre el qual es mesura el pendent de cada punt. */
export const TRAM_PENDENT_KM = 0.1;

/**
 * El pendent (%) de cada punt: el que puja o baixa l'altitud en els 100 m centrats en ell.
 * No el dona el dispositiu, es calcula. Punt a punt seria soroll —l'altímetre va de 0,2 m en
 * 0,2 m i entre dos punts hi ha pocs metres—, i el tram l'esmorteeix. Amb menys de 20 m de
 * recorregut dins del tram (aturat) no es diu res.
 *
 * @param km  distància acumulada de cada punt, creixent
 * @param alt altitud de cada punt (m)
 */
export function pendents(km: number[], alt: (number | null)[]): (number | null)[] {
    const n = km.length, mig = TRAM_PENDENT_KM / 2;
    const sortida: (number | null)[] = new Array(n).fill(null);
    let a = 0, b = 0;
    for (let i = 0; i < n; i++) {
        while (a < i && km[a] < km[i] - mig) a++;
        if (b < i) b = i;
        while (b < n - 1 && km[b + 1] <= km[i] + mig) b++;
        // Els extrems del tram han de tenir altitud
        let ia = a, ib = b;
        while (ia < i && alt[ia] === null) ia++;
        while (ib > i && alt[ib] === null) ib--;
        const llarg = km[ib] - km[ia];
        if (alt[ia] !== null && alt[ib] !== null && llarg >= 0.02) {
            sortida[i] = Math.round(((alt[ib]! - alt[ia]!) / (llarg * 1000)) * 1000) / 10;
        }
    }
    return sortida;
}

/**
 * Trams de pendent del mapa: escala divergent, baixades en blau i pujades de l'ambre al
 * marró, amb el pla en gris. Validada (separació amb visió normal i amb daltonisme entre
 * trams veïns); sobre les teselles, el recorregut porta una vora blanca que li dona contrast.
 */
export const TRAMS_PENDENT = [
    { fins: -8, color: '#0c4a6e', etiqueta: '< −8 %' },
    { fins: -3, color: '#0284c7', etiqueta: '−8 a −3 %' },
    { fins: 3, color: '#9ca3af', etiqueta: '−3 a 3 %' },
    { fins: 8, color: '#f59e0b', etiqueta: '3 a 8 %' },
    { fins: 12, color: '#c2410c', etiqueta: '8 a 12 %' },
    { fins: Infinity, color: '#7c2d12', etiqueta: '> 12 %' },
];

export const colorPendent = (v: number | null) =>
    v === null ? TRAMS_PENDENT[2].color : TRAMS_PENDENT.find((t) => v < t.fins)!.color;

/**
 * Trossos d'un recorregut del mateix color de pendent, per dibuixar-los un a un. Cada tros
 * comença on acaba l'anterior perquè no quedin forats.
 */
export function trossosPerPendent(lat: (number | null)[], lon: (number | null)[], pendent: (number | null)[]) {
    const trossos: { color: string; punts: [number, number][] }[] = [];
    let actual: { color: string; punts: [number, number][] } | null = null;
    lat.forEach((la, i) => {
        const lo = lon[i];
        if (la === null || lo === null) return;
        const punt: [number, number] = [la, lo];
        const color = colorPendent(pendent[i] ?? null);
        if (actual && actual.color !== color) {
            actual.punts.push(punt);
            trossos.push(actual);
            actual = null;
        }
        actual ??= { color, punts: [] };
        actual.punts.push(punt);
    });
    if (actual && (actual as { punts: unknown[] }).punts.length > 1) trossos.push(actual);
    return trossos;
}
