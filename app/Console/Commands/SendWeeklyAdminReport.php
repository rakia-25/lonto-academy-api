<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Console\Command;

class SendWeeklyAdminReport extends Command
{
    protected $signature = 'lonto:weekly-report';

    protected $description = 'Envoie le rapport hebdomadaire aux administrateurs si activé';

    public function handle(): int
    {
        if (! PlatformSetting::get('weeklyReport', false)) {
            $this->info('Rapport hebdomadaire désactivé.');

            return self::SUCCESS;
        }

        $from = now()->subDays(7)->startOfDay();
        $to = now();

        $newLearners = User::where('role', 'learner')
            ->where('created_at', '>=', $from)
            ->count();

        $enrollments = Enrollment::where('created_at', '>=', $from)->count();

        $revenue = (float) Payment::where('status', 'paid')
            ->where('created_at', '>=', $from)
            ->sum('amount');

        $certificates = Certificate::where(function ($q) use ($from) {
            $q->where('issued_at', '>=', $from)
                ->orWhere(function ($q2) use ($from) {
                    $q2->whereNull('issued_at')->where('created_at', '>=', $from);
                });
        })->count();

        $brand = PlatformSetting::get('platformName', 'Lonto Academy');
        $currency = PlatformSetting::get('currency', 'XOF');
        $period = $from->format('d/m/Y').' → '.$to->format('d/m/Y');

        $message = "Rapport hebdomadaire {$brand} ({$period}) : "
            ."{$newLearners} nouvel".($newLearners > 1 ? 's' : '')." apprenant".($newLearners > 1 ? 's' : '').", "
            ."{$enrollments} inscription".($enrollments > 1 ? 's' : '').", "
            .number_format($revenue, 0, ',', ' ')." {$currency} de revenus, "
            ."{$certificates} certificat".($certificates > 1 ? 's' : '')." émis.";

        Notify::toAdmins('weekly_report', $message);

        $this->info($message);

        return self::SUCCESS;
    }
}
