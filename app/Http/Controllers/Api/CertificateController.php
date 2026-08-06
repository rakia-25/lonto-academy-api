<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Exam;
use App\Support\Audit;
use App\Support\CertificateDesign;
use App\Support\CertificatePdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CertificateController extends Controller
{
    /**
     * Vérification publique d'un certificat.
     */
    public function verify(string $code)
    {
        $certificate = Certificate::with(['user:id,name', 'course:id,title,slug,category,level'])
            ->where('verification_code', $code)
            ->first();

        if (! $certificate) {
            return response()->json([
                'valid'   => false,
                'message' => 'Certificat introuvable.',
            ], 404);
        }

        return response()->json([
            'valid'       => true,
            'certificate' => [
                'verification_code' => $certificate->verification_code,
                'issued_at'         => $certificate->issued_at,
                'type'              => $certificate->type ?? 'certificat',
                'learner_name'      => $certificate->user->name,
                'course'            => $certificate->course,
            ],
        ]);
    }

    /**
     * Téléchargement PDF du certificat (propriétaire uniquement).
     */
    public function download(Request $request, string $code)
    {
        $certificate = Certificate::with(['user', 'course'])
            ->where('verification_code', $code)
            ->firstOrFail();

        if ($request->user()->id !== $certificate->user_id && $request->user()->role !== 'admin') {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        if (! $certificate->course) {
            return response()->json(['message' => 'Cours associé introuvable.'], 404);
        }

        $design = $this->resolveDesign($certificate);
        $type = ($certificate->type ?? 'certificat') === 'attestation' ? 'attestation' : 'certificat';

        [$pdf] = CertificatePdf::make(
            $certificate,
            $certificate->user,
            $certificate->course,
            $design
        );

        Audit::log(
            'certificate.download',
            "Téléchargement du certificat « {$certificate->course->title} »",
            $certificate,
            ['code' => $certificate->verification_code]
        );

        $filename = ($type === 'attestation' ? 'attestation-' : 'certificat-')
            .(Str::slug($certificate->course->title) ?: 'cours').'.pdf';

        return $pdf->download($filename);
    }

    private function resolveDesign(Certificate $certificate): array
    {
        $type = $certificate->type ?? 'certificat';

        if (! empty($certificate->design_snapshot) && is_array($certificate->design_snapshot)) {
            return CertificateDesign::normalize($certificate->design_snapshot, $type);
        }

        $exam = Exam::where('course_id', $certificate->course_id)->first();

        return CertificateDesign::forExam($exam);
    }
}
