<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Chapter;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Models\Quiz;
use App\Support\Audit;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CourseController extends Controller
{
    // Liste tous les cours (publiés et non publiés)
    public function index()
    {
        $courses = Course::withCount(['chapters', 'enrollments'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($courses);
    }

    // Créer un cours
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'                => 'required|string|max:255',
            'short_description'    => 'nullable|string|max:500',
            'description'          => 'nullable|string',
            'learning_objectives'  => 'nullable|array',
            'learning_objectives.*'=> 'nullable|string|max:500',
            'prerequisites'        => 'nullable|array',
            'prerequisites.*'      => 'nullable|string|max:500',
            'target_audience'      => 'nullable|array',
            'target_audience.*'    => 'nullable|string|max:500',
            'category'             => 'required|in:bureautique,sig',
            'level'                => 'required|in:debutant,moyen,avance',
            'price'                => 'required|numeric|min:0',
            'is_published'         => 'boolean',
        ]);

        $validated['learning_objectives'] = array_values(array_filter(
            $validated['learning_objectives'] ?? [],
            fn ($item) => filled(trim((string) $item))
        ));
        $validated['prerequisites'] = array_values(array_filter(
            $validated['prerequisites'] ?? [],
            fn ($item) => filled(trim((string) $item))
        ));
        $validated['target_audience'] = array_values(array_filter(
            $validated['target_audience'] ?? [],
            fn ($item) => filled(trim((string) $item))
        ));

        $validated['slug'] = Str::slug($validated['title']) . '-' . Str::random(4);

        $course = Course::create($validated);

        Audit::log(
            'course.create',
            "Création du cours « {$course->title} »",
            $course,
            ['category' => $course->category, 'level' => $course->level]
        );

        return response()->json($course, 201);
    }

    // Détail d'un cours avec chapitres et leçons
    public function show(Course $course)
    {
        $course->load(['chapters.lessons.resources', 'chapters.quiz.questions', 'chapters.exercise']);
        $course->loadCount('enrollments');
        return response()->json($course);
    }

    // Modifier un cours
    public function update(Request $request, Course $course)
    {
        $validated = $request->validate([
            'title'                => 'sometimes|string|max:255',
            'short_description'    => 'nullable|string|max:500',
            'description'          => 'nullable|string',
            'learning_objectives'  => 'nullable|array',
            'learning_objectives.*'=> 'nullable|string|max:500',
            'prerequisites'        => 'nullable|array',
            'prerequisites.*'      => 'nullable|string|max:500',
            'target_audience'      => 'nullable|array',
            'target_audience.*'    => 'nullable|string|max:500',
            'category'             => 'sometimes|in:bureautique,sig',
            'level'                => 'sometimes|in:debutant,moyen,avance',
            'price'                => 'sometimes|numeric|min:0',
            'is_published'         => 'boolean',
        ]);

        if (array_key_exists('learning_objectives', $validated)) {
            $validated['learning_objectives'] = array_values(array_filter(
                $validated['learning_objectives'] ?? [],
                fn ($item) => filled(trim((string) $item))
            ));
        }
        if (array_key_exists('prerequisites', $validated)) {
            $validated['prerequisites'] = array_values(array_filter(
                $validated['prerequisites'] ?? [],
                fn ($item) => filled(trim((string) $item))
            ));
        }
        if (array_key_exists('target_audience', $validated)) {
            $validated['target_audience'] = array_values(array_filter(
                $validated['target_audience'] ?? [],
                fn ($item) => filled(trim((string) $item))
            ));
        }

        if (isset($validated['title']) && $validated['title'] !== $course->title) {
            $validated['slug'] = Str::slug($validated['title']) . '-' . Str::random(4);
        }

        $course->update($validated);

        Audit::log(
            'course.update',
            "Modification du cours « {$course->title} »",
            $course
        );

        return response()->json($course);
    }

    // Supprimer un cours
    public function destroy(Course $course)
    {
        $title = $course->title;
        $id = $course->id;

        if ($course->thumbnail) {
            Storage::disk('public')->delete($course->thumbnail);
        }

        $course->delete();

        Audit::log(
            'course.delete',
            "Suppression du cours « {$title} »",
            null,
            ['course_id' => $id, 'title' => $title]
        );

        return response()->json(['message' => 'Cours supprimé.']);
    }

    // Upload / remplacement de la miniature
    public function uploadThumbnail(Request $request, Course $course)
    {
        $request->validate([
            'thumbnail' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:5120',
        ]);

        if ($course->thumbnail) {
            Storage::disk('public')->delete($course->thumbnail);
        }

        $path = $request->file('thumbnail')->store('course-thumbnails', 'public');
        $course->update(['thumbnail' => $path]);

        return response()->json($course);
    }

    // Publier / dépublier
    public function togglePublish(Course $course)
    {
        $wasPublished = $course->is_published;
        $course->update(['is_published' => ! $course->is_published]);

        if (! $wasPublished && $course->is_published) {
            \App\Support\Notify::toLearners(
                'new_course',
                'Nouveau cours disponible : « '.$course->title.' ». Découvrez-le dans le catalogue.'
            );
        }

        Audit::log(
            $course->is_published ? 'course.publish' : 'course.unpublish',
            ($course->is_published ? 'Publication' : 'Dépublication')." du cours « {$course->title} »",
            $course
        );

        return response()->json($course);
    }

    // Ajouter un chapitre
    public function addChapter(Request $request, Course $course)
    {
        $request->validate([
            'title'    => 'required|string',
            'duration' => 'nullable|integer|min:0',
        ]);

        $order   = $course->chapters()->max('order') + 1;
        $chapter = $course->chapters()->create([
            'title'    => $request->title,
            'duration' => $request->duration ?? 0,
            'order'    => $order,
        ]);

        return response()->json($chapter, 201);
    }

    // Ajouter une vidéo à un chapitre
    public function addChapterVideo(Request $request, Chapter $chapter)
    {
        $request->validate([
            'video' => 'required|file|mimetypes:video/mp4,video/mpeg,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/webm|max:512000',
        ]);

        if ($chapter->video_path) {
            Storage::disk('public')->delete($chapter->video_path);
        }

        $path = $request->file('video')->store('chapter-videos', 'public');
        $chapter->update(['video_path' => $path]);

        return response()->json($chapter);
    }

    // Ajouter une leçon à un chapitre
    public function addLesson(Request $request, Course $course, Chapter $chapter)
    {
        $request->validate([
            'title'         => 'required|string',
            'introduction'  => 'nullable|string',
            'duration'      => 'nullable|integer',
        ]);

        $order  = $chapter->lessons()->max('order') + 1;
        $lesson = $chapter->lessons()->create([
            'title'         => $request->title,
            'introduction'  => $request->introduction,
            'duration'      => $request->duration ?? 0,
            'order'         => $order,
        ]);

        return response()->json($lesson, 201);
    }

    // Ajouter un support à une leçon
    public function addLessonResource(Request $request, Lesson $lesson)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'file'  => 'required|file|mimes:pdf|mimetypes:application/pdf|max:20480',
        ]);

        $path = $request->file('file')->store('lesson-resources', 'public');

        $resource = $lesson->resources()->create([
            'title'     => ($validated['title'] ?? null) ?: $request->file('file')->getClientOriginalName(),
            'file_path' => $path,
        ]);

        return response()->json($resource, 201);
    }

    // Télécharger un support avec son nom d'origine
    public function downloadLessonResource(LessonResource $resource)
    {
        if (! Storage::disk('public')->exists($resource->file_path)) {
            abort(404, 'Fichier introuvable. Ré-uploadez ce support dans le cours.');
        }

        // Sur R2 : redirection vers l'URL publique (plus fiable via Render)
        if (Media::usingCloud()) {
            $base = rtrim((string) config('filesystems.disks.public.url'), '/');
            if ($base !== '') {
                return redirect()->away($base.'/'.ltrim($resource->file_path, '/'));
            }
        }

        return Media::download($resource->file_path, $resource->title);
    }

    // Supprimer un support de leçon (+ fichier R2/local)
    public function destroyLessonResource(LessonResource $resource)
    {
        if ($resource->file_path) {
            Storage::disk('public')->delete($resource->file_path);
        }

        $resource->delete();

        return response()->json(['message' => 'Support supprimé.']);
    }

    // Modifier un chapitre
    public function updateChapter(Request $request, Chapter $chapter)
    {
        $validated = $request->validate([
            'title'    => 'sometimes|string|max:255',
            'duration' => 'nullable|integer|min:0',
            'order'    => 'sometimes|integer|min:0',
        ]);

        $chapter->update($validated);

        return response()->json($chapter);
    }

    // Supprimer un chapitre
    public function destroyChapter(Chapter $chapter)
    {
        if ($chapter->video_path) {
            Storage::disk('public')->delete($chapter->video_path);
        }

        $chapter->delete();

        return response()->json(['message' => 'Chapitre supprimé.']);
    }

    // Modifier une leçon
    public function updateLesson(Request $request, Lesson $lesson)
    {
        $validated = $request->validate([
            'title'         => 'sometimes|string|max:255',
            'introduction'  => 'nullable|string',
            'duration'      => 'nullable|integer|min:0',
            'order'         => 'sometimes|integer|min:0',
        ]);

        $lesson->update($validated);

        return response()->json($lesson);
    }

    // Supprimer une leçon
    public function destroyLesson(Lesson $lesson)
    {
        $lesson->load('resources');
        foreach ($lesson->resources as $resource) {
            if ($resource->file_path) {
                Storage::disk('public')->delete($resource->file_path);
            }
        }

        $lesson->delete();

        return response()->json(['message' => 'Leçon supprimée.']);
    }

    // Créer un exercice
    public function storeExercise(Request $request, Chapter $chapter)
    {
        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'instructions' => 'nullable|string',
            'file'         => 'nullable|file|max:20480',
        ]);

        $path = null;
        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('exercise-files', 'public');
        }

        $exercise = $chapter->exercises()->create([
            'title'             => $validated['title'],
            'instructions'      => $validated['instructions'] ?? null,
            'instructions_file' => $path,
        ]);

        return response()->json($exercise, 201);
    }

    // Modifier un exercice
    public function updateExercise(Request $request, Exercise $exercise)
    {
        $validated = $request->validate([
            'title'        => 'sometimes|string|max:255',
            'instructions' => 'nullable|string',
            'file'         => 'nullable|file|max:20480',
        ]);

        if (array_key_exists('title', $validated)) {
            $exercise->title = $validated['title'];
        }
        if (array_key_exists('instructions', $validated)) {
            $exercise->instructions = $validated['instructions'];
        }

        if ($request->hasFile('file')) {
            if ($exercise->instructions_file) {
                Storage::disk('public')->delete($exercise->instructions_file);
            }
            $exercise->instructions_file = $request->file('file')->store('exercise-files', 'public');
        }

        $exercise->save();

        return response()->json($exercise);
    }

    // Supprimer un exercice
    public function destroyExercise(Exercise $exercise)
    {
        if ($exercise->instructions_file) {
            Storage::disk('public')->delete($exercise->instructions_file);
        }

        $exercise->delete();

        return response()->json(['message' => 'Exercice supprimé.']);
    }

    // Télécharger le fichier joint d'un exercice
    public function downloadExerciseFile(Exercise $exercise)
    {
        abort_unless($exercise->instructions_file, 404);
        abort_unless(Storage::disk('public')->exists($exercise->instructions_file), 404);

        return Media::download(
            $exercise->instructions_file,
            basename($exercise->instructions_file)
        );
    }

    // Créer ou mettre à jour le QCM d'un chapitre
    public function storeQuiz(Request $request, Chapter $chapter)
    {
        $validated = $request->validate([
            'title'                       => 'required|string|max:255',
            'pass_score'                  => 'nullable|integer|min:0|max:100',
            'questions'                   => 'required|array|min:1',
            'questions.*.question'        => 'required|string',
            'questions.*.options'         => 'required|array|min:2',
            'questions.*.options.*'       => 'required|string',
            'questions.*.correct_answer'  => 'required|string',
        ]);

        foreach ($validated['questions'] as $index => $question) {
            $options = [];
            foreach ($question['options'] as $option) {
                $trimmed = trim((string) $option);
                if ($trimmed !== '') {
                    $options[] = $trimmed;
                }
            }

            $correct = trim((string) $question['correct_answer']);
            if ($correct === '' || ! in_array($correct, $options, true)) {
                throw ValidationException::withMessages([
                    "questions.$index.correct_answer" => 'La bonne réponse doit faire partie des options.',
                ]);
            }

            $validated['questions'][$index]['options'] = $options;
            $validated['questions'][$index]['correct_answer'] = $correct;
        }

        $quiz = $chapter->quiz;
        $status = $quiz ? 200 : 201;
        $payload = [
            'title'      => $validated['title'],
            'pass_score' => $validated['pass_score'] ?? 70,
        ];

        if ($quiz) {
            $quiz->update($payload);
            $quiz->questions()->delete();
        } else {
            $quiz = $chapter->quiz()->create($payload);
        }

        foreach ($validated['questions'] as $question) {
            $quiz->questions()->create([
                'question'       => $question['question'],
                'options'        => array_values($question['options']),
                'correct_answer' => $question['correct_answer'],
            ]);
        }

        return response()->json($quiz->load('questions'), $status);
    }

    // Supprimer un QCM
    public function destroyQuiz(Quiz $quiz)
    {
        $quiz->delete();

        return response()->json(['message' => 'QCM supprimé.']);
    }
}
