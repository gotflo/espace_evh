const TITLE_ID = 'presentation-video-title'

/**
 * Video de presentation de l'application : fichier de la plateforme, lu sur place dans le lecteur du telephone.
 * Rien d'autre que la video et son petit titre (aucun compte, aucun partage). Telechargee seulement a la lecture.
 */
export function PresentationVideo({ className, title = 'Présentation de l’application' }: { className: string; title?: string }) {
  return (
    <section className={className} aria-labelledby={TITLE_ID}>
      <h3 id={TITLE_ID} className="video-title">{title}</h3>
      <div className="yt-frame">
        <video className="video-player" src="/guide-captures/presentation-application.mp4" poster="/guide-captures/video-affiche.webp"
          aria-labelledby={TITLE_ID} controls playsInline preload="none"
          controlsList="nodownload noplaybackrate noremoteplayback" disablePictureInPicture disableRemotePlayback x-webkit-airplay="deny"
          onContextMenu={(e) => e.preventDefault()} />
      </div>
    </section>
  )
}
