<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with([
            'user:id,name,email',
            'course:id,title,slug',
        ])->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('method')) {
            $query->where('method', $request->method);
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('course', function ($cq) use ($search) {
                        $cq->where('title', 'like', "%{$search}%");
                    });
            });
        }

        $payments = $query->paginate(min(100, max(10, (int) $request->get('per_page', 25))));

        $summary = [
            'total_paid'    => (float) Payment::where('status', 'paid')->sum('amount'),
            'total_pending' => (float) Payment::where('status', 'pending')->sum('amount'),
            'count_paid'    => Payment::where('status', 'paid')->count(),
            'count_all'     => Payment::count(),
        ];

        return response()->json([
            'data'    => $payments->items(),
            'meta'    => [
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
                'per_page'     => $payments->perPage(),
                'total'        => $payments->total(),
            ],
            'summary' => $summary,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = Payment::with([
            'user:id,name,email',
            'course:id,title',
        ])->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('method')) {
            $query->where('method', $request->method);
        }

        $filename = 'paiements-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 Excel
            fputcsv($out, [
                'ID', 'Date', 'Référence', 'Apprenant', 'Email',
                'Cours', 'Montant', 'Méthode', 'Statut',
            ], ';');

            $query->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $p) {
                    fputcsv($out, [
                        $p->id,
                        optional($p->created_at)->format('Y-m-d H:i'),
                        $p->reference,
                        $p->user?->name,
                        $p->user?->email,
                        $p->course?->title,
                        number_format((float) $p->amount, 2, '.', ''),
                        $p->method,
                        $p->status,
                    ], ';');
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
