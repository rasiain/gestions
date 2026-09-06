/**
 * Els grups del tauler i les seves accions, en un sol lloc.
 *
 * D'aquí surten tant les targetes del tauler com la barra lateral que acompanya cada
 * acció. Si estiguessin escrits dues vegades, acabarien dient coses diferents.
 *
 * Els colors van escrits sencers i no compostos (`text-blue-600` i no `text-${c}-600`)
 * perquè Tailwind només conserva les classes que troba escrites al codi.
 */
export interface Accio {
    titol: string;
    /** Nom de ruta de Ziggy. */
    ruta: string;
}

export interface Grup {
    clau: string;
    titol: string;
    descripcio: string;
    /** Traç de la icona, en un viewBox de 24×24. */
    icona: string;
    color: {
        fons: string;
        icona: string;
        vora: string;
        enllac: string;
        actiu: string;
    };
    accions: Accio[];
}

const colors = {
    blau: {
        fons: 'bg-blue-100 dark:bg-blue-900',
        icona: 'text-blue-600 dark:text-blue-400',
        vora: 'border-blue-200 dark:border-blue-800',
        enllac: 'hover:text-blue-600 dark:hover:text-blue-400',
        actiu: 'bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    },
    verd: {
        fons: 'bg-green-100 dark:bg-green-900',
        icona: 'text-green-600 dark:text-green-400',
        vora: 'border-green-200 dark:border-green-800',
        enllac: 'hover:text-green-600 dark:hover:text-green-400',
        actiu: 'bg-green-50 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    },
    ambre: {
        fons: 'bg-amber-100 dark:bg-amber-900',
        icona: 'text-amber-600 dark:text-amber-400',
        vora: 'border-amber-200 dark:border-amber-800',
        enllac: 'hover:text-amber-600 dark:hover:text-amber-400',
        actiu: 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    },
    vermell: {
        fons: 'bg-red-100 dark:bg-red-900',
        icona: 'text-red-600 dark:text-red-400',
        vora: 'border-red-200 dark:border-red-800',
        enllac: 'hover:text-red-600 dark:hover:text-red-400',
        actiu: 'bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    },
    cel: {
        fons: 'bg-sky-100 dark:bg-sky-900',
        icona: 'text-sky-600 dark:text-sky-400',
        vora: 'border-sky-200 dark:border-sky-800',
        enllac: 'hover:text-sky-600 dark:hover:text-sky-400',
        actiu: 'bg-sky-50 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    },
    lila: {
        fons: 'bg-purple-100 dark:bg-purple-900',
        icona: 'text-purple-600 dark:text-purple-400',
        vora: 'border-purple-200 dark:border-purple-800',
        enllac: 'hover:text-purple-600 dark:hover:text-purple-400',
        actiu: 'bg-purple-50 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    },
};

const icones = {
    calculadora: 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z',
    grafica: 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6',
    casa: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    rebut: 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z',
    // Caixa de cartró: el calaix del que encara no té grup propi
    caixa: 'M20 7l-8-4-8 4m16 0v10l-8 4m8-14l-8 4m0 10L4 17V7m8 10V11M4 7l8 4',
    engranatge: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
};

export const grups: Grup[] = [
    {
        clau: 'bancaries',
        titol: 'Gestions bancàries',
        descripcio: 'Gestió de comptes i transaccions',
        icona: icones.calculadora,
        color: colors.blau,
        accions: [
            { titol: 'Importar moviments', ruta: 'importar.index' },
            { titol: 'Comptes Corrents', ruta: 'comptes-corrents.index' },
            { titol: 'Entitats bancàries', ruta: 'entitats.index' },
        ],
    },
    {
        clau: 'inversions',
        titol: 'Inversions',
        descripcio: "Gestió d'inversions bancàries",
        icona: icones.grafica,
        color: colors.verd,
        accions: [
            { titol: "Fons d'Inversió", ruta: 'fons-inversio.index' },
            { titol: 'Plans de Pensions', ruta: 'plans-pensions.index' },
            { titol: 'Renda Fixa', ruta: 'renda-fixa.index' },
        ],
    },
    {
        clau: 'lloguers',
        titol: 'Lloguers',
        descripcio: "Gestió d'immobles i llogaters",
        icona: icones.casa,
        color: colors.ambre,
        accions: [
            { titol: 'Lloguers', ruta: 'lloguers.index' },
            { titol: 'Immobles', ruta: 'immobles.index' },
        ],
    },
    {
        clau: 'impostos',
        titol: 'Impostos i assegurances',
        descripcio: 'Declaracions, càlculs fiscals i pòlisses',
        icona: icones.rebut,
        color: colors.vermell,
        accions: [
            { titol: 'IRPF Lloguers', ruta: 'impostos.irpf' },
            { titol: 'IVA Lloguers', ruta: 'impostos.iva' },
            { titol: 'Model 184', ruta: 'impostos.model-184' },
            { titol: 'Patrimoni', ruta: 'impostos.patrimoni' },
            { titol: 'Taxes', ruta: 'impostos.taxes' },
            { titol: 'Assegurances', ruta: 'impostos.assegurances' },
        ],
    },
    {
        clau: 'altres',
        titol: 'Altres',
        descripcio: 'El que no encaixa als altres blocs',
        icona: icones.caixa,
        color: colors.cel,
        accions: [
            { titol: 'Vehicles a motor', ruta: 'vehicles-motor.index' },
            { titol: 'Bicis', ruta: 'bicis.index' },
        ],
    },
    {
        clau: 'configuracio',
        titol: 'Configuració',
        descripcio: 'Dades mestres del sistema',
        icona: icones.engranatge,
        color: colors.lila,
        accions: [
            { titol: 'Persones', ruta: 'persones.index' },
            { titol: 'Proveïdors', ruta: 'proveidors.index' },
            { titol: 'Categories', ruta: 'categories.index' },
            { titol: 'Comunitats de Béns', ruta: 'comunitats-bens.index' },
        ],
    },
];
