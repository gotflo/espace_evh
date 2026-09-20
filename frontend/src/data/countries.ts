export interface Country {
  iso: string
  name: string
  dial: string
  flag: string
}

// Canada en tete (valeur par defaut). Liste orientee vers les pays
// pertinents pour l'eglise (francophonie, Amerique du Nord, Caraibes...).
export const COUNTRIES: Country[] = [
  { iso: 'CA', name: 'Canada', dial: '+1', flag: '🇨🇦' },
  { iso: 'US', name: 'États-Unis', dial: '+1', flag: '🇺🇸' },
  { iso: 'FR', name: 'France', dial: '+33', flag: '🇫🇷' },
  { iso: 'BE', name: 'Belgique', dial: '+32', flag: '🇧🇪' },
  { iso: 'CH', name: 'Suisse', dial: '+41', flag: '🇨🇭' },
  { iso: 'HT', name: 'Haïti', dial: '+509', flag: '🇭🇹' },
  { iso: 'CD', name: 'RD Congo', dial: '+243', flag: '🇨🇩' },
  { iso: 'CG', name: 'Congo', dial: '+242', flag: '🇨🇬' },
  { iso: 'CI', name: "Côte d'Ivoire", dial: '+225', flag: '🇨🇮' },
  { iso: 'CM', name: 'Cameroun', dial: '+237', flag: '🇨🇲' },
  { iso: 'SN', name: 'Sénégal', dial: '+221', flag: '🇸🇳' },
  { iso: 'BJ', name: 'Bénin', dial: '+229', flag: '🇧🇯' },
  { iso: 'TG', name: 'Togo', dial: '+228', flag: '🇹🇬' },
  { iso: 'BF', name: 'Burkina Faso', dial: '+226', flag: '🇧🇫' },
  { iso: 'ML', name: 'Mali', dial: '+223', flag: '🇲🇱' },
  { iso: 'GN', name: 'Guinée', dial: '+224', flag: '🇬🇳' },
  { iso: 'GA', name: 'Gabon', dial: '+241', flag: '🇬🇦' },
  { iso: 'NE', name: 'Niger', dial: '+227', flag: '🇳🇪' },
  { iso: 'TD', name: 'Tchad', dial: '+235', flag: '🇹🇩' },
  { iso: 'RW', name: 'Rwanda', dial: '+250', flag: '🇷🇼' },
  { iso: 'BI', name: 'Burundi', dial: '+257', flag: '🇧🇮' },
  { iso: 'MG', name: 'Madagascar', dial: '+261', flag: '🇲🇬' },
  { iso: 'MA', name: 'Maroc', dial: '+212', flag: '🇲🇦' },
  { iso: 'DZ', name: 'Algérie', dial: '+213', flag: '🇩🇿' },
  { iso: 'TN', name: 'Tunisie', dial: '+216', flag: '🇹🇳' },
  { iso: 'NG', name: 'Nigéria', dial: '+234', flag: '🇳🇬' },
  { iso: 'GH', name: 'Ghana', dial: '+233', flag: '🇬🇭' },
  { iso: 'GB', name: 'Royaume-Uni', dial: '+44', flag: '🇬🇧' },
]

export const DEFAULT_COUNTRY = COUNTRIES[0] // Canada

/**
 * Construit un numero E.164 a partir d'un pays et d'une saisie nationale.
 * Retire un eventuel 0 de tete pour les pays hors Amerique du Nord (prefixe interurbain).
 */
export function toE164(country: Country, national: string): string {
  let digits = national.replace(/\D+/g, '')
  if (country.dial !== '+1' && digits.startsWith('0')) {
    digits = digits.replace(/^0+/, '')
  }
  return `${country.dial}${digits}`
}
