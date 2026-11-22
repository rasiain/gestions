/**
 * El que comparteixen totes les gràfiques (chart.js): els colors de sèrie i els que canvien amb el mode fosc.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';

/** Els dos colors de sèrie del projecte: validats en clar i en fosc, per contrast i per a daltonisme. */
export const SERIE_BLAU = '#0284c7';
export const SERIE_AMBRE = '#d97706';

/**
 * Els colors de les gràfiques segons el mode clar o fosc del sistema.
 *
 * Tailwind fa el mode fosc amb `prefers-color-scheme` i el CSS canvia sol, però chart.js pinta en un
 * canvas i s'hi ha de dir: aquí s'escolta la preferència i els colors es tornen a calcular quan canvia.
 * Abans hi havia aquest mateix bloc copiat a vuit pàgines.
 */
export function useColorsGrafica() {
    const fosc = ref(false);
    let consulta: MediaQueryList | null = null;
    const anota = (e: MediaQueryListEvent | MediaQueryList) => (fosc.value = e.matches);

    onMounted(() => {
        consulta = window.matchMedia('(prefers-color-scheme: dark)');
        anota(consulta);
        consulta.addEventListener('change', anota);
    });
    onUnmounted(() => consulta?.removeEventListener('change', anota));

    return {
        fosc,
        /** Els textos dels eixos i les llegendes. */
        tinta: computed(() => (fosc.value ? '#9ca3af' : '#6b7280')),
        quadricula: computed(() => (fosc.value ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)')),
        /** La línia del zero, quan l'eix té valors negatius. */
        zero: computed(() => (fosc.value ? 'rgba(255,255,255,0.35)' : 'rgba(0,0,0,0.35)')),
        /** El fons de la targeta, per a les vores que separen les barres. */
        superficie: computed(() => (fosc.value ? '#1f2937' : '#ffffff')),
    };
}
