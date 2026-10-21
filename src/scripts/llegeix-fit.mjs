// Llegeix els fitxers FIT d'activitat d'una carpeta i n'escriu, una línia JSON per sessió,
// el resum del dispositiu i els punts del recorregut. El crida `sortides:importa`: PHP no té
// cap llibreria oficial de FIT, i el SDK de Garmin sí que en té per a JavaScript.
//
//   node scripts/llegeix-fit.mjs <carpeta> [inicis.json]
//
// `inicis.json` és una llista d'hores d'inici (segons UTC) de les sessions que interessen:
// dels 40.000 fitxers d'una exportació només uns pocs són sortides en bici, i així no es
// treuen per la sortida els punts de la resta.
//
// Els punts van com a llista [t, lat, lon, alt, dist, vel, fc, cad, temp] amb els valors
// originals del dispositiu, sense pèrdua: coordenades en semicercles, altitud i distància en
// cm, velocitat en mm/s. `t` són els segons des de l'inici de la sessió.

import { Decoder, Stream } from '@garmin/fitsdk';
import fs from 'node:fs';
import path from 'node:path';

const [carpeta, fitxerInicis] = process.argv.slice(2);
const inicis = fitxerInicis ? new Set(JSON.parse(fs.readFileSync(fitxerInicis, 'utf8'))) : null;
const MARGE_S = 5;

const SEMI = 180 / 2 ** 31;
/** Segons entre l'origen Unix i el dels FIT (1989-12-31 00:00 UTC). */
const ORIGEN_FIT = 631065600;
const arrodoneix = (v, factor) => (v == null ? null : Math.round(v * factor));

function interessa(inici) {
    if (inicis === null) return true;
    for (let dt = -MARGE_S; dt <= MARGE_S; dt++) if (inicis.has(inici + dt)) return true;
    return false;
}

// Douglas-Peucker sobre graus: prou per dibuixar un mapa petit, no per mesurar res
function simplifica(punts, tolerancia) {
    if (punts.length < 3) return punts;
    const guarda = new Uint8Array(punts.length);
    guarda[0] = guarda[punts.length - 1] = 1;
    const pila = [[0, punts.length - 1]];
    while (pila.length) {
        const [a, b] = pila.pop();
        const [ax, ay] = punts[a], [bx, by] = punts[b];
        const dx = bx - ax, dy = by - ay, llarg = Math.hypot(dx, dy) || 1e-12;
        let max = -1, idx = -1;
        for (let i = a + 1; i < b; i++) {
            const d = Math.abs(dy * punts[i][0] - dx * punts[i][1] + bx * ay - by * ax) / llarg;
            if (d > max) { max = d; idx = i; }
        }
        if (max > tolerancia) { guarda[idx] = 1; pila.push([a, idx], [idx, b]); }
    }
    return punts.filter((_, i) => guarda[i]);
}

// Polilínia codificada de Google, 5 decimals: [lat, lon]
function codifica(punts) {
    let sortida = '', pLat = 0, pLon = 0;
    const valor = (v) => {
        let s = v < 0 ? ~(v << 1) : v << 1, r = '';
        while (s >= 0x20) { r += String.fromCharCode((0x20 | (s & 0x1f)) + 63); s >>= 5; }
        return r + String.fromCharCode(s + 63);
    };
    for (const [lat, lon] of punts) {
        const la = Math.round(lat * 1e5), lo = Math.round(lon * 1e5);
        sortida += valor(la - pLat) + valor(lo - pLon);
        pLat = la; pLon = lo;
    }
    return sortida;
}

// Les dates del SDK passen a ISO perquè el JSON sigui el mateix a PHP
const serialitza = (obj) => JSON.parse(JSON.stringify(obj, (_, v) => (v instanceof Date ? v.toISOString() : v)));

for (const nom of fs.readdirSync(carpeta)) {
    if (!nom.toLowerCase().endsWith('.fit')) continue;

    let missatges;
    try {
        const decoder = new Decoder(Stream.fromBuffer(fs.readFileSync(path.join(carpeta, nom))));
        if (!decoder.isFIT()) continue;
        missatges = decoder.read({ convertDateTimesToDates: true }).messages;
    } catch (e) {
        process.stdout.write(JSON.stringify({ fitxer: nom, error: String(e).slice(0, 200) }) + '\n');
        continue;
    }

    if (missatges.fileIdMesgs?.[0]?.type !== 'activity') continue;

    const sessions = missatges.sessionMesgs ?? [];
    const registres = missatges.recordMesgs ?? [];

    // Desfasament de l'hora local (s): el missatge «activity» porta l'hora UTC i la local, aquesta
    // en segons des de l'origen dels FIT (1989-12-31). Serveix per a la data local d'una sortida
    // que no té el resum de Garmin al costat.
    const activitat = missatges.activityMesgs?.[0];
    const desfasLocal = activitat?.timestamp && activitat?.localTimestamp != null
        ? activitat.localTimestamp - (activitat.timestamp.getTime() / 1000 - ORIGEN_FIT)
        : null;

    for (const sessio of sessions) {
        if (!sessio.startTime) continue;
        const inici = Math.round(sessio.startTime.getTime() / 1000);
        if (!interessa(inici)) continue;

        // Amb més d'una sessió al fitxer, només els punts de la seva finestra
        const fi = inici + Math.ceil(sessio.totalElapsedTime ?? Infinity);
        const propis = sessions.length > 1
            ? registres.filter((r) => { const t = r.timestamp?.getTime() / 1000; return t >= inici && t <= fi; })
            : registres;

        const punts = [];
        const coords = [];
        let latMin = Infinity, latMax = -Infinity, lonMin = Infinity, lonMax = -Infinity;

        for (const r of propis) {
            if (!r.timestamp) continue;
            const lat = r.positionLat ?? null, lon = r.positionLong ?? null;
            punts.push([
                Math.round(r.timestamp.getTime() / 1000) - inici,
                lat,
                lon,
                arrodoneix(r.enhancedAltitude ?? r.altitude, 100),
                arrodoneix(r.distance, 100),
                arrodoneix(r.enhancedSpeed ?? r.speed, 1000),
                r.heartRate ?? null,
                r.cadence ?? null,
                r.temperature ?? null,
            ]);
            if (lat != null && lon != null) {
                const la = lat * SEMI, lo = lon * SEMI;
                coords.push([la, lo]);
                if (la < latMin) latMin = la; if (la > latMax) latMax = la;
                if (lo < lonMin) lonMin = lo; if (lo > lonMax) lonMax = lo;
            }
        }

        process.stdout.write(JSON.stringify({
            fitxer: nom,
            inici,
            desfas_local: desfasLocal,
            sessio: serialitza(sessio),
            punts,
            rectangle: coords.length ? { lat_min: latMin, lat_max: latMax, lon_min: lonMin, lon_max: lonMax } : null,
            // ~10 m de tolerància: un recorregut de 3 h queda en uns centenars de punts
            simplificat: coords.length ? codifica(simplifica(coords, 0.0001)) : null,
        }) + '\n');
    }
}
