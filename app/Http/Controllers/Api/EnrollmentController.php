<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Support\Notify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnrollmentController extends Controller
{
    /**
     * Inscription / achat d'un cours (paiement simulé Nita / Amana).
     */
    public function checkout(Request $request, string $slug)
    {
        $user = $request->user();

        if ($user->blocked_at) {
            return response()->json(['message' => 'Votre compte est bloqué.'], 403);
        }

        $course = Course::where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        if ($course->isEnrolled($user)) {
            return response()->json([
                'message'    => 'Vous êtes déjà inscrit à ce cours.',
                'enrollment' => true,
                'course'     => ['id' => $course->id, 'slug' => $course->slug, 'title' => $course->title],
            ], 409);
        }

        $price = (float) $course->price;
        $isFree = $price <= 0;

        $validated = $request->validate([
            'method' => $isFree ? 'nullable|in:nita,amana,other' : 'required|in:nita,amana,other',
        ]);

        $enrollment = DB::transaction(function () use ($user, $course, $price, $isFree, $validated) {
            $payment = null;

            if (! $isFree) {
                $payment = Payment::create([
                    'user_id'   => $user->id,
                    'course_id' => $course->id,
                    'amount'    => $price,
                    'method'    => $validated['method'],
                    'status'    => 'paid',
                    'reference' => 'PAY-'.Str::upper(Str::random(10)),
                ]);
            }

            $enrollment = Enrollment::create([
                'user_id'    => $user->id,
                'course_id'  => $course->id,
                'type'       => 'one_time',
                'expires_at' => null,
            ]);

            return compact('enrollment', 'payment');
        });

        Notify::send(
            $user,
            'enrollment',
            'Vous êtes inscrit au cours « '.$course->title.' ». Commencez dès maintenant depuis votre espace.'
        );

        if (PlatformSetting::get('newEnrollmentAlert', true)) {
            Notify::toAdmins(
                'new_enrollment',
                "Nouvelle inscription : {$user->name} ({$user->email}) → « {$course->title} »."
            );
        }

        return response()->json([
            'message'    => $isFree ? 'Inscription réussie.' : 'Paiement confirmé. Accès au cours débloqué.',
            'enrollment' => $enrollment['enrollment'],
            'payment'    => $enrollment['payment'],
            'course'     => [
                'id'    => $course->id,
                'slug'  => $course->slug,
                'title' => $course->title,
            ],
        ], 201);
    }
}
