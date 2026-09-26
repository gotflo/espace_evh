import { ICONS, type IconName } from '../utils/icons'

/** Icone au trait (voir utils/icons.ts), decorative : masquee aux lecteurs d'ecran. */
export function Icon({ name, path, size = 20, className }: { name?: IconName; path?: string; size?: number; className?: string }) {
  return (
    <svg className={className} width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d={path ?? (name ? ICONS[name] : '')} />
    </svg>
  )
}
