/** « de septembre 2026 », mais « d’octobre 2026 », « d’avril », « d’août » (élision devant une voyelle). */
export const de = (words?: string | null): string => (!words ? '' : /^[aeiouyàâäéèêëîïôöûüh]/i.test(words) ? `d’${words}` : `de ${words}`)

/** Situation matrimoniale : cle enregistree -> libelle affiche (formulaire du profil, fiche du membre). */
export const MARITAL_LABEL: Record<string, string> = {
  celibataire: 'Célibataire', marie: 'Marié(e)', fiance: 'Fiancé(e)',
  veuf: 'Veuf(ve)', divorce: 'Divorcé(e)', concubinage: 'En concubinage',
}
