/**
 * Token CSRF per a les crides `fetch`, que s'envia com a capçalera `X-XSRF-TOKEN`.
 *
 * Es llegeix de la galeta `XSRF-TOKEN` i no del `<meta name="csrf-token">`: el meta és el de
 * quan es va carregar la pàgina, i si la sessió es renova després —un login, que amb Inertia
 * no recarrega— queda vell i cada petició torna un 419 sense dir res.
 */
export const xsrfToken = (): string => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
};
