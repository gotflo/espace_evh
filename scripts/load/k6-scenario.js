// Test de charge progressif (k6 : https://k6.io).
// A lancer sur une copie de test (ou en production pendant un creneau calme, avec accord).
//
//   1. Sur le serveur : php artisan app:load-test-users 500
//      puis recuperer storage/app/load-test-tokens.txt a cote de ce fichier.
//   2. Sur un ordinateur : k6 run -e BASE_URL=https://espace.vasesdhonneurchicoutimi.org scripts/load/k6-scenario.js
//   3. Apres le test : php artisan app:load-test-users --delete
//
// Chaque utilisateur virtuel se comporte comme un membre : il ouvre l'application
// (profil, verset, evenements, notifications), puis l'application appelle le « pouls »
// toutes les ~45 s ; de temps en temps il ouvre le calendrier ou ses exercices.
// Paliers : 10 -> 50 -> 100 -> 250 -> 500 utilisateurs simultanes, puis pic brutal et retour.
import http from 'k6/http'
import { check, sleep } from 'k6'
import { SharedArray } from 'k6/data'

const BASE = __ENV.BASE_URL || 'http://localhost:8000'
const tokens = new SharedArray('jetons', () => open('./load-test-tokens.txt').split('\n').filter(Boolean))

export const options = {
  scenarios: {
    montee: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '1m', target: 10 },
        { duration: '2m', target: 50 },
        { duration: '2m', target: 100 },
        { duration: '3m', target: 250 },
        { duration: '3m', target: 500 },
        { duration: '3m', target: 500 },  // plateau
        { duration: '30s', target: 50 },  // redescente
        { duration: '20s', target: 500 }, // pic brutal
        { duration: '2m', target: 500 },
        { duration: '1m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],                 // moins de 1 % d'erreurs
    'http_req_duration{kind:pulse}': ['p(95)<800'],  // le pouls reste rapide
    'http_req_duration{kind:page}': ['p(95)<2500'],
  },
}

const get = (path, token, kind) => http.get(`${BASE}/api${path}`, {
  headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  tags: { kind },
})

export default function () {
  const token = tokens[(__VU - 1) % tokens.length]
  if (__ITER === 0) {
    // Ouverture de l'application
    for (const path of ['/me', '/dashboard/verse', '/me/events?days=30', '/me/announcements', '/me/exercises']) {
      check(get(path, token, 'page'), { 'ouverture 200': (r) => r.status === 200 })
    }
  }
  check(get('/me/pulse', token, 'pulse'), { 'pouls 200': (r) => r.status === 200 })
  if (Math.random() < 0.15) {
    const day = new Date().toISOString().slice(0, 10)
    check(get(`/calendar?from=${day}&to=${day}`, token, 'page'), { 'calendrier 200': (r) => r.status === 200 })
  }
  sleep(35 + Math.random() * 20) // rythme reel du pouls (~45 s)
}
