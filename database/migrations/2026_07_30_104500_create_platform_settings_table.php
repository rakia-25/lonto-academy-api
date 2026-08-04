<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->json('data');
            $table->timestamps();
        });

        DB::table('platform_settings')->insert([
            'data' => json_encode([
                'platformName'       => 'Lonto Academy',
                'tagline'            => 'Formation professionnelle certifiante',
                'supportEmail'       => 'contact@lonto-academy.com',
                'supportPhone'       => '',
                'currencyLabel'      => 'F',
                'language'           => 'fr',
                'accentColor'        => '#d4a017',
                'accentDark'         => '#b8860b',
                'sidebarStyle'       => 'navy',
                'density'            => 'comfortable',
                'roundedCorners'     => true,
                'allowRegistration'  => true,
                'showPrices'         => true,
                'maintenanceMode'    => false,
                'emailNotifications' => true,
                'newEnrollmentAlert' => true,
                'weeklyReport'       => false,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
