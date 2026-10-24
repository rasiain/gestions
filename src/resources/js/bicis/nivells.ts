/**
 * Els nivells de tolerància de les rutes canòniques (RutesCanoniquesService::NIVELLS), com es diuen a
 * la pantalla. El nom diu quina diferència s'hi admet, no com es calcula: el percentatge va a part.
 */
export const NIVELLS: Record<string, { nom: string; descripcio: string }> = {
    identiques: { nom: 'Calcades', descripcio: 'El mateix recorregut: només varien el soroll del GPS i les puntes.' },
    mateixa: { nom: 'Retocs', descripcio: 'La mateixa ruta amb algun carrer o camí canviat.' },
    variants: { nom: 'Un tram diferent', descripcio: 'El gruix és comú, però una pujada o una baixada és una altra.' },
    zona: { nom: 'Mateix terreny', descripcio: 'Comparteixen menys de la meitat del recorregut, pels mateixos camins.' },
};

export const nomNivell = (clau: string) => NIVELLS[clau]?.nom ?? clau;
