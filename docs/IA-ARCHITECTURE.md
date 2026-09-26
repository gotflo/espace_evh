# Proposition : fonctions d'intelligence artificielle (non implémentées)

Ce document décrit **comment** des fonctions d'IA pourraient être ajoutées plus tard, sans rien
activer aujourd'hui. Aucune donnée de la plateforme n'est envoyée à un service d'IA dans la version actuelle.

## Principes non négociables

1. **Confidentialité d'abord.** Les FISS, sujets de prière, situations familiales et notes sont des données
   pastorales sensibles. Par défaut, elles ne quittent jamais le serveur de l'église.
2. **Consentement explicite et révocable** du membre pour toute analyse qui le concerne (case dédiée dans le profil,
   désactivée par défaut, historique dans l'audit).
3. **Minimisation** : on n'envoie que des agrégats ou des textes pseudonymisés, jamais le téléphone, le nom complet,
   la photo ni l'identifiant interne.
4. **L'IA propose, l'humain décide.** Aucune décision automatique (statut, rôle, validation de FISS, message envoyé
   à un membre) : les suggestions sont présentées au responsable, qui les accepte ou non.
5. **Même périmètre que l'interface** : une fonction d'IA ne voit que ce que le responsable a déjà le droit de voir
   (`MemberScope`, `ReportService::resolveScope`). Pas de contournement par l'IA.
6. **Traçabilité** : chaque appel est journalisé dans `audit_logs` (action `ai.*`, auteur, périmètre, finalité),
   sans stocker le contenu envoyé.

## Cas d'usage envisageables (par ordre de sensibilité croissante)

| Cas | Données | Risque | Approche |
|-----|---------|--------|----------|
| Rédaction assistée d'annonces et d'événements | texte saisi par le responsable | faible | appel direct, aucune donnée de membre |
| Résumé mensuel d'un rapport de tribu | agrégats de `ReportService` (déjà anonymes) | faible | agrégats seulement |
| Suggestions de suivi (« 3 membres n'ont pas rempli leur FISS depuis 2 mois ») | indicateurs calculés en SQL | moyen | règles déterministes d'abord ; l'IA ne fait que formuler |
| Détection de tendances (baisse d'assiduité d'un GEM) | séries mensuelles agrégées | moyen | statistiques locales, IA facultative pour l'explication |
| Analyse de textes libres (sujets de prière, commentaires FISS) | textes personnels | **élevé** | à éviter ; sinon modèle **hébergé localement**, consentement obligatoire |

## Architecture proposée

```
            (responsable autorisé)
                     │
             Interface React ── bouton « Suggérer » explicite
                     │
               API Laravel  ──> AiGateway (service unique)
                     │             ├─ vérifie permission + périmètre + consentement
                     │             ├─ construit un contexte MINIMAL (agrégats / pseudonymes)
                     │             ├─ filtre de données sensibles (téléphones, courriels, noms)
                     │             ├─ appelle le fournisseur configuré (ou un modèle local)
                     │             ├─ valide la réponse (format attendu, longueur, pas de données personnelles)
                     │             └─ journalise ai.* dans audit_logs (sans le contenu)
                     │
            Réponse = suggestion affichée, jamais appliquée automatiquement
```

- **`App\Services\Ai\AiGateway`** : point d'entrée unique ; interface `AiProvider` avec implémentations
  interchangeables (fournisseur externe, modèle local via un serveur d'inférence interne, ou « désactivé »).
- **Configuration** : `AI_ENABLED=false` par défaut, `AI_PROVIDER`, `AI_MODEL`, clé dans `.env` uniquement,
  quota par utilisateur (limiteur nommé `ai`), délai maximal et coupure automatique en cas d'erreur.
- **Pseudonymisation** : table de correspondance temporaire en mémoire (`Membre A`, `Membre B`) ; la réponse est
  « ré-identifiée » côté serveur seulement pour l'affichage au responsable autorisé.
- **Pas d'entraînement** sur les données de l'église : choisir des fournisseurs qui garantissent contractuellement
  l'absence de conservation et d'entraînement, ou un modèle local.
- **File d'attente** pour les traitements longs (résumés), afin de ne pas bloquer l'interface.

## Conformité

- Loi 25 (Québec) : évaluation des facteurs relatifs à la vie privée avant toute mise en service, registre des
  traitements, information claire des membres, responsable de la protection des renseignements personnels désigné.
- Hébergement des données au Canada privilégié ; transfert hors Québec uniquement après évaluation.
- Droit d'accès et de retrait : le retrait du consentement supprime les résultats d'IA liés au membre.

## Étapes suggérées

1. Rédaction assistée d'annonces (aucune donnée de membre) — valide l'infrastructure `AiGateway`.
2. Résumé des rapports à partir des agrégats existants.
3. Évaluer le besoin réel avant toute analyse de données individuelles.
