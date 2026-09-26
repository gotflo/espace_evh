import { Component, type ErrorInfo, type ReactNode } from 'react'
import { isStaleBuildError, reloadOnce } from '../utils/reload'

/**
 * Filet de securite de l'interface : une erreur d'affichage n'aboutit jamais a une page
 * blanche. Apres une mise a jour du site, l'application se recharge d'elle-meme.
 */
export class ErrorBoundary extends Component<{ children: ReactNode }, { error: Error | null }> {
  state = { error: null as Error | null }

  static getDerivedStateFromError(error: Error) {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    if (isStaleBuildError(error) && reloadOnce()) return
    console.error('Erreur d\'affichage', error, info.componentStack)
  }

  render() {
    if (!this.state.error) return this.props.children
    return (
      <div className="screen">
        <div className="card crash-card" role="alert">
          <div className="crash-icon" aria-hidden>🙏</div>
          <h2 className="section-title">Un petit souci d'affichage</h2>
          <p className="section-sub">
            {isStaleBuildError(this.state.error)
              ? "Une nouvelle version de l'application est disponible."
              : "Cette page n'a pas pu s'afficher. Vos données ne sont pas perdues."}
          </p>
          <button className="btn btn-primary" onClick={() => window.location.reload()}>Recharger</button>
          <button className="btn btn-ghost mt-sm" onClick={() => { window.location.href = '/tableau-de-bord' }}>Retour à l'accueil</button>
        </div>
      </div>
    )
  }
}
