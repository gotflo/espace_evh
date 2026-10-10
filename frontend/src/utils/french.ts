/** « de septembre 2026 », mais « d’octobre 2026 », « d’avril », « d’août » (élision devant une voyelle). */
export const de = (words?: string | null): string => (!words ? '' : /^[aeiouyàâäéèêëîïôöûüh]/i.test(words) ? `d’${words}` : `de ${words}`)
