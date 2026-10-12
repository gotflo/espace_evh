import { useEffect, useSyncExternalStore } from 'react'
import { api } from './api/client'
import { usePulse } from './pulse'

/**
 * Apparence choisie par l'administration (sans code) : image de l'accueil, photos de l'ecran de
 * connexion et textes affiches. Lue sans connexion (l'ecran de connexion en a besoin), gardee sur
 * l'appareil pour s'afficher aussitot, et rafraichie seule quand elle change (pouls « settings »).
 */
export interface AppearanceImage { id: string; small: string; large: string; position: string; widths?: [number, number] }

/** srcset avec les largeurs reelles des deux tailles. */
export const srcSetOf = (i: AppearanceImage) => `${i.small} ${i.widths?.[0] ?? 800}w, ${i.large} ${i.widths?.[1] ?? 1600}w`
export interface Appearance {
  home: AppearanceImage
  login: AppearanceImage[]
  texts: { login_notice: string; welcome_title: string; welcome_message: string; pending_message: string }
}

const KEY = 'evh_appearance'

/** Valeurs d'origine (identiques au serveur sans reglage) : affichees avant la premiere reponse. */
export const DEFAULT_APPEARANCE: Appearance = {
  home: { id: 'origine', small: '/verset/fond-800.jpg', large: '/verset/fond-1600.jpg', position: '62% 42%' },
  login: [
    ['bible', '50% 40%'], ['louange', '50% 30%'], ['table', '50% 55%'], ['fete', '50% 35%'],
  ].map(([id, position]) => ({ id, small: `/login/${id}-800.jpg`, large: `/login/${id}-1600.jpg`, position })),
  texts: {
    login_notice: '',
    welcome_title: 'Bienvenue dans votre famille spirituelle',
    welcome_message: "Nous sommes heureux de vous accueillir sur My vasesdhonneur, l'espace des Vases d'Honneur Chicoutimi.",
    pending_message: "Le pasteur va valider votre inscription. Votre espace s'ouvrira alors automatiquement.",
  },
}

function read(): Appearance | null {
  try {
    const raw = localStorage.getItem(KEY)
    const v = raw ? (JSON.parse(raw) as Appearance) : null
    return v && v.home && Array.isArray(v.login) && v.texts ? v : null
  } catch { return null }
}

let current: Appearance | null = read()
let loading: Promise<void> | null = null
let loadedAt = 0
const listeners = new Set<() => void>()

/** Recharge l'apparence (une seule requete a la fois ; ignore si rechargee il y a moins de 3 s, sauf force). */
export function refreshAppearance(force = false): Promise<void> {
  if (loading) return loading
  if (!force && Date.now() - loadedAt < 3000) return Promise.resolve()
  loading = api<Appearance>('/appearance', { auth: false, toast: false })
    .then((a) => {
      loadedAt = Date.now()
      if (JSON.stringify(a) !== JSON.stringify(current)) {
        current = a
        try { localStorage.setItem(KEY, JSON.stringify(a)) } catch { /* stockage indisponible */ }
        listeners.forEach((l) => l())
      }
    })
    .catch(() => { /* hors ligne : on garde la derniere apparence connue */ })
    .finally(() => { loading = null })
  return loading
}

/** Mise a jour immediate apres une modification dans l'administration. */
export function setAppearance(a: Appearance) {
  current = a
  loadedAt = Date.now()
  try { localStorage.setItem(KEY, JSON.stringify(a)) } catch { /* stockage indisponible */ }
  listeners.forEach((l) => l())
}

const subscribe = (l: () => void) => { listeners.add(l); return () => { listeners.delete(l) } }

/**
 * Apparence courante. `known` est faux tant que rien n'est connu sur cet appareil (premiere visite) :
 * l'ecran de connexion attend alors la reponse plutot que d'afficher une photo qui changerait aussitot.
 */
export function useAppearance(watch = true): { appearance: Appearance; known: boolean } {
  const value = useSyncExternalStore(subscribe, () => current)
  useEffect(() => { void refreshAppearance() }, [])
  usePulse('settings', () => { void refreshAppearance(true) }, watch)
  return { appearance: value ?? DEFAULT_APPEARANCE, known: value !== null }
}
