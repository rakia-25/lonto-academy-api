<?php

namespace Database\Seeders;

use App\Models\Course;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    /**
     * Cours d'exemple (Bureautique + SIG) avec chapitres, lecons, QCM et exercices.
     * Idempotent : on retrouve le cours par son slug et on reconstruit son contenu.
     */
    public function run(): void
    {
        // Anciens slugs renommés (évite les doublons au reseed)
        Course::whereIn('slug', ['excel-avance'])->delete();

        foreach ($this->courses() as $data) {
            $chapters = $data['chapters'];
            unset($data['chapters']);

            $course = Course::updateOrCreate(['slug' => $data['slug']], $data);

            // Reconstruit le contenu proprement (cascade : lecons, quiz, exercices)
            $course->chapters()->delete();

            foreach ($chapters as $ci => $chap) {
                $chapterDuration = collect($chap['lessons'])->sum('duration');

                $chapter = $course->chapters()->create([
                    'title'    => $chap['title'],
                    'duration' => $chapterDuration, // en secondes (duree video)
                    'order'    => $ci + 1,
                ]);

                foreach ($chap['lessons'] as $li => $lesson) {
                    $chapter->lessons()->create([
                        'title'    => $lesson['title'],
                        'duration' => 0,
                        'order'    => $li + 1,
                    ]);
                }

                if (! empty($chap['quiz'])) {
                    $quiz = $chapter->quiz()->create([
                        'title'      => $chap['quiz']['title'],
                        'pass_score' => $chap['quiz']['pass_score'] ?? 70,
                    ]);

                    foreach ($chap['quiz']['questions'] as $q) {
                        $quiz->questions()->create([
                            'question'       => $q['question'],
                            'options'        => $q['options'],
                            'correct_answer' => $q['correct_answer'],
                        ]);
                    }
                }

                if (! empty($chap['exercise'])) {
                    $chapter->exercises()->create([
                        'title'             => $chap['exercise']['title'],
                        'instructions'      => $chap['exercise']['instructions'] ?? null,
                        'instructions_file' => $chap['exercise']['instructions_file'] ?? null,
                    ]);
                }
            }
        }
    }

    private function courses(): array
    {
        return [
            // ---------------------------------------------------------------
            [
                'title'        => 'Word — Maîtriser le traitement de texte',
                'slug'         => 'word-debutant',
                'category'     => 'bureautique',
                'level'        => 'debutant',
                'price'        => 4990,
                'is_published' => true,
                'short_description' => 'Apprenez à créer des documents professionnels avec Microsoft Word.',
                'description'  => '<p>Apprenez à créer des documents <strong>professionnels</strong> avec Microsoft Word : mise en forme, styles, tableaux et publipostage.</p><h3>À la fin de ce cours, vous saurez :</h3><ul><li>Mettre en forme un document long et structuré</li><li>Utiliser les styles, sommaires et sections</li><li>Réaliser un publipostage complet</li></ul>',
                'prerequisites' => ['Savoir utiliser un ordinateur', 'Avoir Microsoft Word installé'],
                'target_audience' => ['Débutants en bureautique', 'Étudiants et professionnels'],
                'chapters'     => [
                    [
                        'title'   => 'Prise en main de Word',
                        'lessons' => [
                            ['title' => 'Découverte de l\'interface', 'duration' => 360],
                            ['title' => 'Saisir et corriger du texte', 'duration' => 480],
                            ['title' => 'Enregistrer et exporter en PDF', 'duration' => 300],
                        ],
                        'quiz' => [
                            'title'      => 'QCM — Les bases de Word',
                            'pass_score' => 70,
                            'questions'  => [
                                [
                                    'question'       => 'Quel raccourci permet d\'enregistrer un document ?',
                                    'options'        => ['Ctrl + S', 'Ctrl + P', 'Ctrl + Z', 'Ctrl + A'],
                                    'correct_answer' => 'Ctrl + S',
                                ],
                                [
                                    'question'       => 'Quel format conserve la mise en page pour le partage ?',
                                    'options'        => ['.txt', '.pdf', '.csv', '.bmp'],
                                    'correct_answer' => '.pdf',
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'   => 'Mise en forme avancée',
                        'lessons' => [
                            ['title' => 'Les styles de paragraphe', 'duration' => 540],
                            ['title' => 'Insérer un sommaire automatique', 'duration' => 420],
                            ['title' => 'Tableaux et images', 'duration' => 600],
                        ],
                        'exercise' => [
                            'title'             => 'Rédiger un rapport structuré',
                            'instructions' => '<p>Créez un rapport de 3 pages avec page de garde, sommaire automatique et au moins un tableau.</p>',
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------
            [
                'title'        => 'Excel — Tableaux et formules pour débutants',
                'slug'         => 'excel-tableaux-formules-debutants',
                'category'     => 'bureautique',
                'level'        => 'debutant',
                'price'        => 5000,
                'is_published' => true,
                'description'  => '<p>Apprenez à utiliser <strong>Excel</strong> au quotidien : saisie, formules simples, mise en forme et graphiques.</p><h3>À la fin de ce cours, vous saurez :</h3><ul><li>Créer et organiser un classeur</li><li>Utiliser SOMME, MOYENNE et les références</li><li>Présenter vos données avec un graphique clair</li></ul>',
                'short_description' => 'Maîtrisez Excel au quotidien : formules, mise en forme et graphiques.',
                'prerequisites' => ['Bases de l\'informatique'],
                'target_audience' => ['Débutants Excel', 'Professionnels administratifs'],
                'chapters'     => [
                    [
                        'title'   => 'Prise en main d\'Excel',
                        'lessons' => [
                            ['title' => 'Découvrir l\'interface', 'duration' => 480],
                            ['title' => 'Saisir et formater des cellules', 'duration' => 600],
                            ['title' => 'Enregistrer et partager un fichier', 'duration' => 300],
                        ],
                        'quiz' => [
                            'title'      => 'QCM — Les bases d\'Excel',
                            'pass_score' => 70,
                            'questions'  => [
                                [
                                    'question'       => 'Que contient une cellule Excel ?',
                                    'options'        => ['Uniquement du texte', 'Du texte, des nombres ou des formules', 'Uniquement des images', 'Uniquement des graphiques'],
                                    'correct_answer' => 'Du texte, des nombres ou des formules',
                                ],
                                [
                                    'question'       => 'Quel raccourci permet d\'enregistrer un classeur ?',
                                    'options'        => ['Ctrl + S', 'Ctrl + P', 'Ctrl + N', 'Ctrl + C'],
                                    'correct_answer' => 'Ctrl + S',
                                ],
                            ],
                        ],
                        'exercise' => [
                            'title'             => 'Créer un budget mensuel',
                            'instructions' => '<p>Créez un tableau de budget (revenus / dépenses) avec totaux et mise en forme.</p>',
                        ],
                    ],
                    [
                        'title'   => 'Formules et visualisation',
                        'lessons' => [
                            ['title' => 'Formules SOMME et MOYENNE', 'duration' => 720],
                            ['title' => 'Références relatives et absolues', 'duration' => 600],
                            ['title' => 'Créer un graphique simple', 'duration' => 540],
                        ],
                        'quiz' => [
                            'title'      => 'QCM — Formules de base',
                            'pass_score' => 70,
                            'questions'  => [
                                [
                                    'question'       => 'Que renvoie =SOMME(A1:A3) ?',
                                    'options'        => ['La moyenne de A1 à A3', 'La somme de A1 à A3', 'Le maximum', 'Le nombre de cellules'],
                                    'correct_answer' => 'La somme de A1 à A3',
                                ],
                                [
                                    'question'       => 'Quel symbole fige une référence de cellule ?',
                                    'options'        => ['#', '$', '%', '&'],
                                    'correct_answer' => '$',
                                ],
                            ],
                        ],
                        'exercise' => [
                            'title'             => 'Tableau de notes',
                            'instructions' => '<p>Calculez la moyenne par élève et ajoutez un graphique en barres.</p>',
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------
            [
                'title'        => 'PowerPoint — Présentations percutantes',
                'slug'         => 'powerpoint-debutant',
                'category'     => 'bureautique',
                'level'        => 'debutant',
                'price'        => 4990,
                'is_published' => true,
                'description'  => '<p>Concevez des présentations <strong>claires et impactantes</strong> : structure, design, animations et transitions maîtrisées.</p>',
                'chapters'     => [
                    [
                        'title'   => 'Construire sa présentation',
                        'lessons' => [
                            ['title' => 'Choisir un modèle et une charte', 'duration' => 360],
                            ['title' => 'Organiser ses diapositives', 'duration' => 480],
                            ['title' => 'Insérer images et icônes', 'duration' => 420],
                        ],
                        'quiz' => [
                            'title'      => 'QCM — Bonnes pratiques',
                            'pass_score' => 70,
                            'questions'  => [
                                [
                                    'question'       => 'Combien d\'idées par diapositive est recommandé ?',
                                    'options'        => ['Une idée principale', 'Le plus possible', 'Au moins cinq', 'Aucune'],
                                    'correct_answer' => 'Une idée principale',
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'   => 'Dynamiser la présentation',
                        'lessons' => [
                            ['title' => 'Transitions et animations', 'duration' => 540],
                            ['title' => 'Mode présentateur', 'duration' => 300],
                        ],
                        'exercise' => [
                            'title'             => 'Pitch de 5 diapositives',
                            'instructions' => '<p>Créez une présentation de 5 diapositives pour présenter un projet, avec une animation par diapositive maximum.</p>',
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------
            [
                'title'        => 'QGIS — Introduction au SIG',
                'slug'         => 'sig-qgis-debutant',
                'category'     => 'sig',
                'level'        => 'debutant',
                'price'        => 9990,
                'is_published' => true,
                'description'  => '<p>Initiez-vous aux <strong>Systèmes d\'Information Géographique</strong> avec QGIS, le logiciel libre de référence.</p><h3>Vous apprendrez à :</h3><ul><li>Charger et afficher des données géographiques</li><li>Symboliser une carte</li><li>Réaliser une mise en page cartographique</li></ul>',
                'chapters'     => [
                    [
                        'title'   => 'Découverte de QGIS',
                        'lessons' => [
                            ['title' => 'Installer et configurer QGIS', 'duration' => 420],
                            ['title' => 'Notions de couches et de projections', 'duration' => 600],
                            ['title' => 'Charger des données vecteur et raster', 'duration' => 540],
                        ],
                        'quiz' => [
                            'title'      => 'QCM — Concepts SIG',
                            'pass_score' => 70,
                            'questions'  => [
                                [
                                    'question'       => 'Qu\'est-ce qu\'une couche vecteur ?',
                                    'options'        => ['Une image satellite', 'Des entités points/lignes/polygones', 'Un fichier texte', 'Un graphique'],
                                    'correct_answer' => 'Des entités points/lignes/polygones',
                                ],
                                [
                                    'question'       => 'Que définit un système de projection (CRS) ?',
                                    'options'        => ['La couleur de la carte', 'La façon de représenter la Terre sur un plan', 'La taille du fichier', 'Le niveau de zoom'],
                                    'correct_answer' => 'La façon de représenter la Terre sur un plan',
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'   => 'Symbologie et mise en page',
                        'lessons' => [
                            ['title' => 'Symboliser une couche par catégorie', 'duration' => 660],
                            ['title' => 'Étiquettes et légendes', 'duration' => 480],
                            ['title' => 'Créer une carte imprimable', 'duration' => 720],
                        ],
                        'exercise' => [
                            'title'             => 'Carte thématique de population',
                            'instructions' => '<p>À partir des données communales fournies, réalisez une carte choroplèthe de la population avec légende et échelle.</p>',
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------
            [
                'title'        => 'Analyse spatiale avancée',
                'slug'         => 'sig-analyse-spatiale',
                'category'     => 'sig',
                'level'        => 'avance',
                'price'        => 14990,
                'is_published' => true,
                'description'  => '<p>Passez au niveau supérieur avec l\'<strong>analyse spatiale</strong> : géotraitements, requêtes spatiales et modélisation.</p><blockquote>Prérequis : maîtriser les bases d\'un logiciel SIG.</blockquote>',
                'chapters'     => [
                    [
                        'title'   => 'Géotraitements essentiels',
                        'lessons' => [
                            ['title' => 'Zones tampons (buffer)', 'duration' => 540],
                            ['title' => 'Intersection et union', 'duration' => 600],
                            ['title' => 'Jointures spatiales', 'duration' => 660],
                        ],
                        'quiz' => [
                            'title'      => 'QCM — Géotraitements',
                            'pass_score' => 80,
                            'questions'  => [
                                [
                                    'question'       => 'À quoi sert un buffer (zone tampon) ?',
                                    'options'        => ['Compresser un fichier', 'Créer une zone à distance définie autour d\'entités', 'Changer la projection', 'Supprimer des doublons'],
                                    'correct_answer' => 'Créer une zone à distance définie autour d\'entités',
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'   => 'Modélisation et automatisation',
                        'lessons' => [
                            ['title' => 'Le modeleur graphique', 'duration' => 720],
                            ['title' => 'Analyse multicritère', 'duration' => 780],
                        ],
                        'exercise' => [
                            'title'             => 'Étude d\'implantation',
                            'instructions' => '<p>Déterminez les zones favorables à l\'implantation d\'un équipement en combinant au moins trois critères spatiaux.</p>',
                        ],
                    ],
                ],
            ],
        ];
    }
}
