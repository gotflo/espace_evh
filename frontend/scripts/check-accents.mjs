// Verifie qu'aucun texte affiche ne contient un mot francais courant ecrit sans ses accents
// (« reponse », « evenement », « Ecrivez »...) :
// - application (src/) : texte JSX et chaines de caracteres ;
// - API (backend/app) : messages renvoyes a l'utilisateur (abort, message, notifications...).
// Les commentaires, identifiants, classes CSS et cles techniques sont ignores.
// Usage : node scripts/check-accents.mjs   (code de sortie 1 si un texte est a corriger)
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

const WORDS = `
activite activites acces annee annees apres arriere assiduite bapteme bientot cloture
completee creee creees cree crees decembre deja departement departements derniere dernieres
desactive desactivee detail details ecran ecrans ecrivez eglise element elements enregistre
enregistree enregistrees equipe etape etapes etat etats etes evenement evenements evaluation
evaluations fevrier fidele fideles genere generee identite integration interet journee journees
memoriser meditation meditations mediter modifie modifiee modifies modifiees numero numeros
parametres periode periodes personnalise ponctualite precedent precedente premiere presence
presences prenom priere prieres probleme problemes publie publiee recu recue recus reessayez
reference reglages regle regles reinitialiser repetition repetitions reponse reponses repondu
reseau resultat resultats reussi reussie role roles seance seances securite selection
selectionne selectionner serie series special speciale succes supprime supprimee supprimes
systeme telephone termine terminee tres verifiez video videos
`.split(/\s+/).filter(Boolean)

const wordRe = new RegExp(`(^|[^\\p{L}])(${WORDS.join('|')})(?=[^\\p{L}]|$)`, 'iu')
const exprRe = /\$\{[^}]*\}/g
const toPath = (rel) => new URL(rel, import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')

const files = []
const walk = (dir, ext) => readdirSync(dir).forEach((f) => {
  const p = join(dir, f)
  if (statSync(p).isDirectory()) walk(p, ext)
  else if (ext.test(f)) files.push(p)
})
walk(toPath('../src'), /\.(tsx|ts)$/)
walk(toPath('../../backend/app'), /\.php$/)

// Lignes PHP qui produisent un texte destine a l'utilisateur.
const phpUserText = /abort|message|Notifier::send|withMessages|'title'|'body'|'label'|=> '[A-Z]|\? '[A-Z]|: '[A-Z]|return '[A-Z]/

let problems = 0
for (const file of files) {
  const php = file.endsWith('.php')
  readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
    const code = line.replace(/\/\/.*$/, '').replace(/\/\*.*?\*\//g, '')
    if (/^\s*(\*|\/\*|#|import |export type|type |interface |use |namespace )/.test(code)) return
    if (php && !phpUserText.test(code)) return
    const texts = []
    if (!php) for (const m of code.matchAll(/>([^<>{}]+)</g)) texts.push(m[1])
    for (const m of code.matchAll(/'([^'\\]*(?:\\.[^'\\]*)*)'|"([^"\\]*(?:\\.[^"\\]*)*)"|`([^`]*)`/g)) texts.push(m[1] ?? m[2] ?? m[3] ?? '')
    for (const t of texts) {
      // Expressions ${...} et mots a tirets (classes CSS, cles) : pas du texte affiche.
      const visible = t.replace(exprRe, ' ').split(/\s+/).filter((w) => !w.includes('-') && !w.includes('_')).join(' ')
      if (t.trim().startsWith('/')) continue // adresse d'API ou de page
      if (!/\s/.test(visible.trim()) && /^[a-z0-9./:#?=&%]*$/.test(visible.trim())) continue // cle, chemin
      const hit = visible.match(wordRe)
      if (hit) {
        problems++
        console.log(`${file.replace(/.*[\\/](src|app)[\\/]/, '$1/')}:${i + 1}  « ${hit[2]} »  ${t.trim().slice(0, 90)}`)
      }
    }
  })
}
console.log(problems ? `\n${problems} texte(s) a corriger.` : 'Aucun mot sans accent dans les textes affiches.')
process.exit(problems ? 1 : 0)
