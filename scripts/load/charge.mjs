// Test de charge sans installation (Node 18+ suffit) : simule des membres connectes en meme temps.
//
//   node scripts/load/charge.mjs --base https://copie-de-test.exemple.org --tokens load-test-tokens.txt --mode realiste
//
// Modes :
//   realiste : rythme reel (le pouls toutes les ~45 s), paliers 10 -> 25 -> 50 -> 100 -> 250 -> 500,
//              puis redescente a 50 et pic brutal a 500 en 10 s (tout le monde ouvre l'application
//              apres une notification). ~15 min.
//   capacite : chaque membre enchaine les ecrans sans pause (0,5 a 1,5 s) pour trouver la limite
//              du serveur. ~6 min. A ne lancer que sur une copie de test.
//   pic      : 10 membres puis 500 en 5 s, maintenus 2 min. ~3 min.
//   endurance: 200 membres au rythme reel pendant --minutes (120 par defaut), resultats par
//              tranche de 10 min : les temps de reponse ne doivent pas se degrader avec le temps.
//
// Chaque membre virtuel ouvre l'application (les 3 appels du tableau de bord, en parallele),
// puis appelle le pouls a son rythme ; de temps en temps il ouvre le calendrier. Les jetons viennent de
// « php artisan app:load-test-users 500 » (fichier storage/app/load-test-tokens.txt).
// Resultats par palier : requetes/s, temps moyen, p95, p99, erreurs (apres les nouvelles tentatives
// automatiques de l'application). Options : --out fichier.json, --connexions N (par membre, 1 par defaut).

import { readFileSync, writeFileSync } from 'node:fs'
import http from 'node:http'
import https from 'node:https'

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, a, i, all) => {
  if (a.startsWith('--')) acc.push([a.slice(2), all[i + 1]?.startsWith('--') ? 'true' : all[i + 1]])
  return acc
}, []))
const BASE = (args.base || 'http://127.0.0.1:8000').replace(/\/$/, '')
const MODE = args.mode || 'realiste'
const tokens = readFileSync(args.tokens || 'load-test-tokens.txt', 'utf8').split(/\r?\n/).filter(Boolean)
if (!tokens.length) throw new Error('Aucun jeton dans le fichier.')

// [duree en secondes, nombre de membres vise a la fin du palier, nom]
const MODES = {
  realiste: {
    think: [35, 55],
    stages: [[60, 10, '10'], [60, 25, '25'], [60, 50, '50'], [90, 100, '100'], [120, 250, '250'], [180, 500, '500'],
      [30, 50, 'redescente 50'], [10, 500, 'pic 50 → 500'], [120, 500, 'après le pic'], [20, 0, 'fin']],
  },
  capacite: {
    think: [0.5, 1.5],
    stages: [[45, 10, '10'], [45, 25, '25'], [45, 50, '50'], [60, 100, '100'], [60, 250, '250'], [90, 500, '500'], [20, 0, 'fin']],
  },
  pic: {
    think: [35, 55],
    stages: [[30, 10, '10'], [5, 500, 'pic 10 → 500'], [120, 500, 'après le pic'], [20, 0, 'fin']],
  },
  endurance: {
    think: [35, 55],
    stages: [[60, 200, 'montée 200'],
      ...Array.from({ length: Math.ceil(Number(args.minutes || 120) / 10) }, (_, i) => [600, 200, `${(i + 1) * 10} min`]),
      [20, 0, 'fin']],
  },
}
const plan = MODES[MODE]
if (!plan) throw new Error(`Mode inconnu : ${MODE}`)

const samples = [] // { t, kind, ms, status }
const started = Date.now()
const now = () => (Date.now() - started) / 1000
const sleep = (s) => new Promise((r) => setTimeout(r, s * 1000))
const between = ([a, b]) => a + Math.random() * (b - a)

// Comme un navigateur : une connexion durable par membre (--connexions pour en autoriser plus),
// delai de 25 s, et jusqu'a 2 nouvelles tentatives si le reseau coupe ou si le serveur est
// momentanement sature (502/503/504), jamais apres un delai depasse : comme l'application (api/client.ts).
const lib = BASE.startsWith('https') ? https : http
const perMember = Number(args.connexions || 1)
let retries = 0

function once(path, token, agent) {
  return new Promise((resolve) => {
    const req = lib.get(BASE + '/api' + path, { agent, timeout: 25000, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
      (res) => { res.resume(); res.on('end', () => resolve({ status: res.statusCode, retryAfter: Number(res.headers['retry-after']) })) })
    let timedOut = false
    req.on('timeout', () => { timedOut = true; req.destroy() })
    req.on('error', () => resolve({ status: 0, timedOut }))
  })
}

async function get(path, token, kind, agent) {
  const t0 = performance.now()
  let r
  for (let attempt = 0; ; attempt++) {
    r = await once(path, token, agent)
    if (r.timedOut || !([0, 502, 503, 504].includes(r.status) && attempt < 2)) break
    retries++
    await sleep((r.retryAfter || 0.7 * (attempt + 1)) + Math.random() * 0.5)
  }
  samples.push({ t: now(), kind, ms: performance.now() - t0, status: r.status })
  return r.status
}

let target = 0
let active = 0
let nextId = 0

async function member(id) {
  active++
  try {
    await session(id)
  } finally {
    active--
  }
}

async function session(id) {
  const token = tokens[id % tokens.length]
  const agent = new lib.Agent({ keepAlive: true, maxSockets: perMember })
  try {
    await visit(token, agent)
  } finally {
    agent.destroy()
  }
}

async function visit(token, agent) {
  const day = new Date().toISOString().slice(0, 10)
  const week = new Date(Date.now() + 6 * 86400000).toISOString().slice(0, 10)
  // Les memes appels que l'application a l'ouverture du tableau de bord : profil, lectures des
  // blocs regroupees en un seul appel (/me/home), pouls.
  const blocks = ['/dashboard/verse', '/me/fiss', '/me/events?days=30', '/me/announcements', '/me/exercises',
    `/calendar?from=${day}&to=${week}&only=events`]
  const home = '/me/home?' + blocks.map((p) => 'paths[]=' + encodeURIComponent(p)).join('&')
  await Promise.all(['/me', home, '/me/pulse'].map((path) => get(path, token, 'ouverture', agent)))
  while (active <= target) {
    await sleep(between(plan.think))
    if (active > target) break
    await get('/me/pulse', token, 'pouls', agent)
    if (Math.random() < 0.15) await get(`/calendar?from=${day}&to=${day}`, token, 'ecran', agent)
  }
}

const pct = (arr, p) => arr.length ? arr[Math.min(arr.length - 1, Math.floor(arr.length * p))] : 0
function summarize(rows) {
  const ms = rows.map((r) => r.ms).sort((a, b) => a - b)
  const errors = rows.filter((r) => r.status === 0 || r.status >= 500).length
  const limited = rows.filter((r) => r.status === 429).length
  return {
    requetes: rows.length,
    moyenne_ms: Math.round(ms.reduce((a, b) => a + b, 0) / (ms.length || 1)),
    p50_ms: Math.round(pct(ms, 0.5)), p95_ms: Math.round(pct(ms, 0.95)), p99_ms: Math.round(pct(ms, 0.99)),
    max_ms: Math.round(ms[ms.length - 1] || 0),
    erreurs: errors, taux_erreur: rows.length ? +(100 * errors / rows.length).toFixed(2) : 0, limites_429: limited,
  }
}

console.log(`Test « ${MODE} » sur ${BASE} avec ${tokens.length} jetons.`)
const windows = []
for (const [duration, goal, name] of plan.stages) {
  const from = target
  const start = now()
  const end = start + duration
  while (now() < end) {
    target = Math.round(from + (goal - from) * Math.min(1, (now() - start) / duration))
    while (active < target) member(nextId++)
    await sleep(0.2)
  }
  target = goal
  windows.push({ palier: name, debut: start, fin: now(), membres: goal })
  const rows = samples.filter((s) => s.t >= start && s.t < now())
  const s = summarize(rows)
  console.log(`${name.padEnd(14)} ${String(goal).padStart(4)} membres | ${String((rows.length / duration).toFixed(1)).padStart(6)} req/s | moy ${String(s.moyenne_ms).padStart(5)} ms | p95 ${String(s.p95_ms).padStart(5)} | p99 ${String(s.p99_ms).padStart(5)} | max ${String(s.max_ms).padStart(6)} | erreurs ${s.erreurs} (${s.taux_erreur} %)${s.limites_429 ? ` | 429 : ${s.limites_429}` : ''}`)
}
// Derniers membres : on attend la fin de leurs requetes (au plus 35 s).
for (let i = 0; active > 0 && i < 70; i++) await sleep(0.5)

const result = {
  base: BASE, mode: MODE, date: new Date().toISOString(),
  paliers: windows.map((w) => {
    const rows = samples.filter((s) => s.t >= w.debut && s.t < w.fin)
    return { palier: w.palier, membres: w.membres, req_par_s: +(rows.length / (w.fin - w.debut)).toFixed(1), ...summarize(rows),
      par_type: Object.fromEntries(['ouverture', 'pouls', 'ecran'].map((k) => [k, summarize(rows.filter((r) => r.kind === k))])) }
  }),
  total: summarize(samples),
  nouvelles_tentatives: retries,
}
console.log(`Total : ${result.total.requetes} requêtes, ${result.total.erreurs} erreurs (${result.total.taux_erreur} %), p95 ${result.total.p95_ms} ms, p99 ${result.total.p99_ms} ms, ${retries} nouvelle(s) tentative(s).`)
if (args.out) writeFileSync(args.out, JSON.stringify(result, null, 2))
