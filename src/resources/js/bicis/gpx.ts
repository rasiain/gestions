/**
 * GPX d'un recorregut, el format que importen Wikiloc, Garmin Connect i Komoot. Es genera al navegador amb
 * els punts que la pàgina ja té (la sortida, una proposta), sense tornar al servidor. És el mateix format
 * que PropostesService::gpx(), que fa servir la comanda sortides:proposa.
 */

export interface PuntGpx {
    lat: number;
    lon: number;
    /** Altitud en m. */
    ele: number | null;
    /** Hora del punt, ISO 8601 en UTC: amb ella Wikiloc en mostra els temps i les velocitats. */
    time?: string;
}

const escapa = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

export function gpx(nom: string, punts: PuntGpx[]): string {
    const nomXml = escapa(nom);
    const trkpts = punts
        .map((p) => {
            const dins = (p.ele === null ? '' : `<ele>${p.ele.toFixed(1)}</ele>`) + (p.time ? `<time>${p.time}</time>` : '');
            return `      <trkpt lat="${p.lat.toFixed(6)}" lon="${p.lon.toFixed(6)}">${dins}</trkpt>`;
        })
        .join('\n');

    return `<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="gestions" xmlns="http://www.topografix.com/GPX/1/1">
  <metadata><name>${nomXml}</name></metadata>
  <trk>
    <name>${nomXml}</name>
    <trkseg>
${trkpts}
    </trkseg>
  </trk>
</gpx>
`;
}

/** Fa que el navegador baixi el GPX amb aquest nom de fitxer. */
export function baixaGpx(nom: string, punts: PuntGpx[], fitxer: string): void {
    const enllac = document.createElement('a');
    enllac.href = URL.createObjectURL(new Blob([gpx(nom, punts)], { type: 'application/gpx+xml' }));
    // Sense els caràcters que els sistemes de fitxers no admeten
    enllac.download = fitxer.replace(/[\\/:*?"<>|]+/g, '-').trim();
    enllac.click();
    URL.revokeObjectURL(enllac.href);
}
