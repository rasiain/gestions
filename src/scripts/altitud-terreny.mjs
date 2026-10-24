// Llegeix l'altitud del terreny dels punts d'unes quantes sortides als models baixats amb
// `sortides:baixa-models`: l'ICGC de 2 m (Catalunya) i l'IGN de 5 m (Espanya). Especificació a
// .claude/specs/bicis-altitud-corregida.md.
//
//   node scripts/altitud-terreny.mjs <carpeta dels models>  < entrada  > sortida
//
// Entrada: una línia JSON per sortida, {"id": 1979, "punts": [[lat, lon], …]} en graus; un punt
// sense posició és null. Sortida: una línia per sortida, en el mateix ordre,
//
//   {"id": 1979, "fonts": {"icgc": [alt, …] | null, "ign": [alt, …] | null}, "sense_dades": {"ign": 3}}
//
// amb l'altitud en cm, alineada amb els punts de l'entrada (null on no hi ha posició). **Una font
// només es dona si cobreix tots els punts amb posició** —res de barrejar fonts dins d'una sortida—;
// si no, és null i `sense_dades` diu quants punts n'han quedat fora.
//
// Interpolació bilineal entre les quatre cel·les veïnes. Els fulls llegits es guarden en memòria
// (els últims CAU de cada font): si l'entrada porta les sortides ordenades per zona, cada full es
// llegeix un sol cop.

import { fromFile } from 'geotiff';
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';
import zlib from 'node:zlib';

const carpeta = process.argv[2];
if (!carpeta) {
    console.error('Ús: node scripts/altitud-terreny.mjs <carpeta dels models>');
    process.exit(1);
}

const CAU = 32;

/** Un mapa que oblida el que fa més temps que no es fa servir. */
function cau() {
    const m = new Map();
    return {
        async obte(clau, carrega) {
            if (m.has(clau)) {
                const v = m.get(clau);
                m.delete(clau);
                m.set(clau, v);
                return v;
            }
            const v = await carrega();
            m.set(clau, v);
            if (m.size > CAU) m.delete(m.keys().next().value);
            return v;
        },
    };
}

// ── UTM 31N (ETRS89, el datum de l'ICGC) ───────────────────────────────────────────────────────
// Sèrie de Krüger fins a n³: error per sota del mil·límetre dins del fus.
const A_ELIPS = 6378137, F = 1 / 298.257222101, K0 = 0.9996, LON0 = 3, FALS_EST = 500000;
const N = F / (2 - F);
const A_RECT = (A_ELIPS / (1 + N)) * (1 + N ** 2 / 4 + N ** 4 / 64);
const ALFA = [N / 2 - (2 * N ** 2) / 3 + (5 * N ** 3) / 16, (13 * N ** 2) / 48 - (3 * N ** 3) / 5, (61 * N ** 3) / 240];
const E2N = (2 * Math.sqrt(N)) / (1 + N);

function utm31(lat, lon) {
    const fi = (lat * Math.PI) / 180, lambda = ((lon - LON0) * Math.PI) / 180;
    const t = Math.sinh(Math.atanh(Math.sin(fi)) - E2N * Math.atanh(E2N * Math.sin(fi)));
    const xi = Math.atan(t / Math.cos(lambda)), eta = Math.atanh(Math.sin(lambda) / Math.sqrt(1 + t * t));
    let e = eta, n = xi;
    for (let j = 1; j <= 3; j++) {
        e += ALFA[j - 1] * Math.cos(2 * j * xi) * Math.sinh(2 * j * eta);
        n += ALFA[j - 1] * Math.sin(2 * j * xi) * Math.cosh(2 * j * eta);
    }
    return [FALS_EST + K0 * A_RECT * e, K0 * A_RECT * n];
}

// ── Bilineal sobre una graella; `valor(i, j)` torna null on no hi ha dades ────────────────────
function bilineal(fx, fy, ample, alt, valor) {
    const i0 = Math.floor(fx), j0 = Math.floor(fy);
    if (i0 < 0 || j0 < 0 || i0 + 1 >= ample || j0 + 1 >= alt) return null;
    const dx = fx - i0, dy = fy - j0;
    const v00 = valor(i0, j0), v10 = valor(i0 + 1, j0), v01 = valor(i0, j0 + 1), v11 = valor(i0 + 1, j0 + 1);
    if (v00 === null || v10 === null || v01 === null || v11 === null) return null;
    return (v00 * (1 - dx) + v10 * dx) * (1 - dy) + (v01 * (1 - dx) + v11 * dx) * dy;
}

// ── ICGC: un ZIP per full, amb una graella ESRI ASCII en UTM 31N, al centímetre ───────────────
const carpetaIcgc = path.join(carpeta, 'icgc');
const SENSE_DADES_ICGC = -999900;  // -9999,00 en cm

/** L'entrada única d'un ZIP: on comencen les dades comprimides i quant ocupen. */
function entradaZip(buf) {
    let fi = buf.length - 22;
    while (fi >= 0 && buf.readUInt32LE(fi) !== 0x06054b50) fi--;
    if (fi < 0) throw new Error('no és cap ZIP');
    const cd = buf.readUInt32LE(fi + 16);
    const comprimit = buf.readUInt32LE(cd + 20), local = buf.readUInt32LE(cd + 42);
    return { inici: local + 30 + buf.readUInt16LE(local + 26) + buf.readUInt16LE(local + 28), comprimit };
}

function capcalera(text) {
    const c = {};
    for (const linia of text.split('\n').slice(0, 6)) {
        const [k, v] = linia.trim().split(/\s+/);
        c[k.toUpperCase()] = Number(v);
    }
    return { ample: c.NCOLS, alt: c.NROWS, x0: c.XLLCENTER, y0: c.YLLCENTER, mida: c.CELLSIZE };
}

/**
 * L'índex dels fulls: la capçalera de cada graella. Es desa al costat dels fulls i es refà quan n'hi
 * ha de nous; llegir-ne només la capçalera vol descomprimir els primers bytes de cada ZIP.
 */
function indexIcgc() {
    if (!fs.existsSync(carpetaIcgc)) return [];
    const fitxers = fs.readdirSync(carpetaIcgc).filter((f) => /^\d{6}\.zip$/.test(f));
    const fitxerIndex = path.join(carpetaIcgc, 'index.json');
    if (fs.existsSync(fitxerIndex)) {
        const index = JSON.parse(fs.readFileSync(fitxerIndex, 'utf8'));
        if (index.length === fitxers.length) return index;
    }
    const index = fitxers.map((f) => {
        const buf = fs.readFileSync(path.join(carpetaIcgc, f));
        const { inici } = entradaZip(buf);
        const text = zlib.inflateRawSync(buf.subarray(inici, inici + 1024), { finishFlush: zlib.constants.Z_SYNC_FLUSH }).toString('latin1');
        return { full: f.slice(0, 6), ...capcalera(text) };
    });
    fs.writeFileSync(fitxerIndex, JSON.stringify(index));
    return index;
}

/** Els valors d'un full, en cm i per files de dalt a baix. */
function llegeixFullIcgc(full) {
    const buf = fs.readFileSync(path.join(carpetaIcgc, `${full.full}.zip`));
    const { inici, comprimit } = entradaZip(buf);
    const text = zlib.inflateRawSync(buf.subarray(inici, inici + comprimit));
    const valors = new Int32Array(full.ample * full.alt);
    // Sis línies de capçalera
    let p = 0;
    for (let l = 0; l < 6; l++) p = text.indexOf(10, p) + 1;
    // Els números a mà, sense passar per cadenes: van amb dos decimals i el resultat és en cm
    let k = 0, v = 0, signe = 1, decimals = -1, dins = false;
    for (; p < text.length && k < valors.length; p++) {
        const c = text[p];
        if (c >= 48 && c <= 57) {
            v = v * 10 + (c - 48);
            if (decimals >= 0) decimals++;
            dins = true;
        } else if (c === 46) {
            decimals = 0;
        } else if (c === 45) {
            signe = -1;
        } else if (dins) {
            const d = decimals < 0 ? 0 : decimals;
            valors[k++] = signe * (d === 2 ? v : Math.round(v * 10 ** (2 - d)));
            v = 0; signe = 1; decimals = -1; dins = false;
        }
    }
    if (dins && k < valors.length) valors[k++] = signe * v;
    return valors;
}

// Els fulls per quadrícula de 5 km, que no calgui mirar-los tots a cada punt
const QUADRICULA = 5000;
const quadricula = new Map();
for (const full of indexIcgc()) {
    const [x1, y1] = [full.x0 + (full.ample - 1) * full.mida, full.y0 + (full.alt - 1) * full.mida];
    for (let qx = Math.floor(full.x0 / QUADRICULA); qx <= Math.floor(x1 / QUADRICULA); qx++) {
        for (let qy = Math.floor(full.y0 / QUADRICULA); qy <= Math.floor(y1 / QUADRICULA); qy++) {
            const clau = `${qx},${qy}`;
            quadricula.has(clau) ? quadricula.get(clau).push(full) : quadricula.set(clau, [full]);
        }
    }
}
const cauIcgc = cau();

async function altitudIcgc(lat, lon) {
    const [x, y] = utm31(lat, lon);
    for (const full of quadricula.get(`${Math.floor(x / QUADRICULA)},${Math.floor(y / QUADRICULA)}`) ?? []) {
        const fx = (x - full.x0) / full.mida, fy = (y - full.y0) / full.mida;
        if (fx < 0 || fy < 0 || fx > full.ample - 1 || fy > full.alt - 1) continue;
        const valors = await cauIcgc.obte(full.full, () => llegeixFullIcgc(full));
        // fy compta des de baix i les files van de dalt a baix
        const alt = bilineal(fx, fy, full.ample, full.alt, (i, j) => {
            const v = valors[(full.alt - 1 - j) * full.ample + i];
            return v === SENSE_DADES_ICGC ? null : v;
        });
        if (alt !== null) return Math.round(alt);
    }
    return null;
}

// ── IGN: un GeoTIFF de 0,1° per tros, en graus ETRS89 i metres enters; 0 = mar o fora d'Espanya ─
const carpetaIgn = path.join(carpeta, 'ign');
const TROS = 0.1;
const cauIgn = cau();

function nomTros(lat, lon) {
    // El mateix que BaixaModelsTerreny::nomTros(); el marge evita que 41,9 quedi a 41,8 per arrodoniment
    const s = Math.floor(lat / TROS + 1e-9) * TROS, o = Math.floor(lon / TROS + 1e-9) * TROS;
    const signe = (v, ample) => (v < 0 ? '-' : '+') + Math.abs(v).toFixed(1).padStart(ample - 1, '0');
    return `mdt05_${signe(s, 5)}_${signe(o, 6)}`;
}

async function llegeixTrosIgn(nom) {
    const fitxer = path.join(carpetaIgn, `${nom}.tif`);
    if (!fs.existsSync(fitxer)) return null;
    const imatge = await (await fromFile(fitxer)).getImage();
    const [valors] = await imatge.readRasters();
    const [ox, oy] = imatge.getOrigin(), [rx, ry] = imatge.getResolution();
    return { valors, ample: imatge.getWidth(), alt: imatge.getHeight(), ox, oy, rx, ry };
}

async function altitudIgn(lat, lon) {
    const tros = await cauIgn.obte(nomTros(lat, lon), () => llegeixTrosIgn(nomTros(lat, lon)));
    if (tros === null) return null;
    // L'origen és el cantó de la primera cel·la; el valor és del seu centre
    const fx = (lon - tros.ox) / tros.rx - 0.5, fy = (lat - tros.oy) / tros.ry - 0.5;
    const cx = Math.min(Math.max(fx, 0), tros.ample - 1.000001), cy = Math.min(Math.max(fy, 0), tros.alt - 1.000001);
    const alt = bilineal(cx, cy, tros.ample, tros.alt, (i, j) => {
        const v = tros.valors[j * tros.ample + i];
        return v === 0 ? null : v * 100;
    });
    return alt === null ? null : Math.round(alt);
}

// ── Sortides ──────────────────────────────────────────────────────────────────────────────────
const FONTS = { icgc: altitudIcgc, ign: altitudIgn };

const linies = readline.createInterface({ input: process.stdin, crlfDelay: Infinity });
for await (const linia of linies) {
    if (!linia.trim()) continue;
    const { id, punts } = JSON.parse(linia);
    const fonts = {}, senseDades = {};
    for (const [font, altitud] of Object.entries(FONTS)) {
        const alts = [];
        let falten = 0;
        for (const p of punts) {
            if (p === null || p[0] === null || p[1] === null) {
                alts.push(null);
                continue;
            }
            const a = await altitud(p[0], p[1]);
            if (a === null) falten++;
            alts.push(a);
        }
        fonts[font] = falten === 0 ? alts : null;
        if (falten > 0) senseDades[font] = falten;
    }
    process.stdout.write(JSON.stringify({ id, fonts, sense_dades: senseDades }) + '\n');
}
