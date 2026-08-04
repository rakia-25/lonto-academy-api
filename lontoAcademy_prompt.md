# Prompt de développement — Lonto Academy

## Contexte général

Tu es un développeur full-stack senior. Tu vas m'aider à construire **Lonto Academy**, une plateforme e-learning payante destinée au marché nigérien et ouest-africain. L'application est développée en **React (frontend)** et **Laravel (backend/API REST)**, avec une base de données **MySQL**.

La plateforme propose des cours en ligne en :
- **Bureautique** : Word, Excel, PowerPoint, Access
- **SIG (Système d'Information Géographique)** : QGIS, ArcGIS, CartoDB, etc.

Chaque matière est déclinée en **3 niveaux** : Débutant · Moyen · Avancé.

Le développement se fait **étape par étape**, en commençant par la base de données, puis le backend Laravel, puis le frontend React. Ne génère jamais tout d'un coup : attends mes confirmations entre chaque étape.

---

## Stack technique

| Couche | Technologie |
|---|---|
| Frontend | React (Vite), React Router, Tailwind CSS, Axios |
| Backend | Laravel 11, API REST (JSON) |
| Base de données | MySQL |
| Auth | Laravel Sanctum (tokens SPA) |
| Paiement | Mobile money : NITA, Amana (intégration API à définir) |
| Vidéos | Hébergement propre (stockage serveur ou S3-compatible) |
| Emails | Laravel Mail (SMTP) |
| PDF certificats | Laravel + DomPDF ou Browsershot |
| Déploiement | Serveur Linux (Apache/Nginx), FileZilla pour transfert |

---

## Rôles utilisateurs

### 1. Apprenant (learner)
- Inscription / connexion / mot de passe oublié
- Tableau de bord personnel : cours achetés, progression, certificats
- Catalogue de cours filtrable par matière et niveau
- Lecteur vidéo intégré (reprise de position, contrôle de vitesse)
- Téléchargement des supports PDF par chapitre
- QCM après chaque chapitre (score minimum requis pour passer)
- Exercices pratiques : téléchargement du fichier de consignes + soumission du fichier complété
- Génération et téléchargement du certificat après validation du cours
- Paiement : achat à l'unité (accès à vie) ou abonnement mensuel/annuel

### 2. Administrateur (admin)
- Tableau de bord : statistiques globales (inscrits, ventes, taux de complétion)
- Gestion des cours : créer, modifier, publier/dépublier, organiser en chapitres et leçons
- Gestion des vidéos et supports (upload)
- Gestion des QCM et exercices pratiques
- Gestion des utilisateurs : voir, bloquer, réinitialiser
- Gestion des paiements : historique, statuts, remboursements manuels
- Gestion des certificats : modèle, logo, génération manuelle si besoin
- Notifications email : nouveau cours, confirmation paiement, certificat disponible

---

## Modèle de données (entités principales)

```
users                  → id, name, email, password, role (learner/admin), avatar, created_at
courses                → id, title, slug, description, category (bureautique|sig), level (debutant|moyen|avance), price, thumbnail, is_published, created_at
chapters               → id, course_id, title, order
lessons                → id, chapter_id, title, video_path, duration, order
lesson_resources       → id, lesson_id, title, file_path (PDF)
quizzes                → id, chapter_id, title, pass_score (%)
quiz_questions         → id, quiz_id, question, options (JSON), correct_answer
quiz_attempts          → id, user_id, quiz_id, score, passed, created_at
exercises              → id, chapter_id, title, instructions_file, created_at
exercise_submissions   → id, user_id, exercise_id, file_path, submitted_at
enrollments            → id, user_id, course_id, type (one_time|subscription), expires_at, created_at
lesson_progress        → id, user_id, lesson_id, completed, last_position_seconds
payments               → id, user_id, course_id, amount, method (nita|amana|other), status (pending|paid|failed), reference, created_at
subscriptions          → id, user_id, plan (monthly|yearly), status, starts_at, ends_at
certificates           → id, user_id, course_id, issued_at, verification_code (unique UUID)
notifications          → id, user_id, type, message, read_at, created_at
```

---

## Fonctionnalités détaillées

### Catalogue & cours
- Filtres : matière (Bureautique / SIG), niveau, prix
- Page cours : description, programme (chapitres/leçons), avis, bouton achat/abonnement
- Accès conditionnel : leçon accessible uniquement si inscrit et QCM précédent validé

### Lecteur vidéo
- Vidéo hébergée sur le serveur (pas YouTube)
- Reprise automatique à la dernière position
- Marquage de la leçon comme "terminée" après visionnage complet (ou 90%)
- Vitesse de lecture : 0.75x, 1x, 1.25x, 1.5x, 2x

### QCM
- Questions à choix unique ou multiple
- Score affiché immédiatement après soumission
- Score minimum configurable (ex : 70%) pour débloquer le chapitre suivant
- Nombre de tentatives illimité
- Historique des tentatives visible par l'apprenant

### Exercices pratiques (hybride)
- Fichier de consignes téléchargeable (.docx, .xlsx, etc.)
- L'apprenant travaille sur son propre logiciel (Word, Excel, QGIS…)
- Upload de son fichier complété sur la plateforme
- Correction : QCM de validation rapide ("Ton fichier affiche-t-il X ? Oui/Non") ou correction manuelle par l'admin

### Paiement (mobile money Niger)
- Opérateurs : NITA, Amana (intégration API selon documentation opérateur)
- Flux : initiation du paiement → attente confirmation → activation de l'accès
- Gestion des états : pending, paid, failed, expired
- Notification email après paiement réussi

### Certification
- Déclenchée automatiquement quand : toutes les leçons terminées + tous les QCM validés
- Certificat PDF soigné avec :
  - Logo et nom **Lonto Academy**
  - Nom complet de l'apprenant
  - Titre du cours et niveau
  - Date d'obtention
  - Code de vérification unique (UUID)
- URL de vérification publique : `/verify/{code}` (sans connexion requise)
- Téléchargeable depuis le tableau de bord apprenant

### Notifications email
- Confirmation d'inscription
- Confirmation de paiement (avec reçu)
- Nouveau cours disponible
- Certificat généré (avec lien de téléchargement)
- Rappel si cours non terminé depuis X jours (optionnel)

---

## Langue & marché cible

- Interface entièrement en **français**
- Marché principal : **Niger** et Afrique de l'Ouest francophone
- Devise : **FCFA**
- Paiement mobile money en priorité (NITA, Amana)

---

## Règles de développement

1. Toujours commencer par le **schéma de base de données complet** avant tout code
2. Le backend Laravel expose une **API REST** consommée par React
3. Chaque endpoint API doit être **protégé par Sanctum** sauf les routes publiques (catalogue, vérification certificat, auth)
4. Utiliser les **Form Requests** Laravel pour la validation
5. Les **policies Laravel** gèrent les autorisations (admin vs learner)
6. Le frontend React utilise **React Router v6**, **Axios** avec intercepteur de token, **Tailwind CSS**
7. Pas de librairie UI externe (pas de Chakra, MUI, etc.) — Tailwind pur
8. Les composants React sont **fonctionnels** avec hooks
9. Gestion d'état global avec **Context API** (pas Redux pour l'instant)
10. Code en **français pour les commentaires**, anglais pour les noms de variables/fonctions

---

## Ordre de développement suggéré

**Phase 1 — Base**
- [ ] Schéma MySQL complet (migrations Laravel)
- [ ] Auth (inscription, connexion, Sanctum)
- [ ] Seeders de test (cours, chapitres, leçons fictifs)

**Phase 2 — Catalogue & lecteur**
- [ ] API cours (liste, détail, filtre)
- [ ] Frontend : page catalogue, page cours, lecteur vidéo

**Phase 3 — Évaluations**
- [ ] API QCM (questions, soumission, score)
- [ ] API exercices (upload consignes, soumission)
- [ ] Frontend : lecteur QCM, page exercice

**Phase 4 — Paiement**
- [ ] Intégration mobile money (NITA, Amana)
- [ ] Gestion enrollments et accès conditionnel

**Phase 5 — Certification**
- [ ] Logique de complétion
- [ ] Génération PDF certificat (DomPDF)
- [ ] Page de vérification publique

**Phase 6 — Back-office admin**
- [ ] Dashboard statistiques
- [ ] CRUD cours / chapitres / leçons / QCM
- [ ] Gestion utilisateurs et paiements

**Phase 7 — Polish**
- [ ] Notifications email (Laravel Mail)
- [ ] Optimisations (cache, lazy loading vidéo)
- [ ] Tests (Feature tests Laravel)

---

## Première instruction

Commence par la **Phase 1** : génère les **migrations Laravel complètes** pour toutes les tables listées dans le modèle de données, dans le bon ordre (respect des clés étrangères). Chaque migration dans un fichier séparé. Ajoute des commentaires pour expliquer les choix importants.
