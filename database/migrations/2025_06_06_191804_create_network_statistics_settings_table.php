<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('network_statistics_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        
        // Insert default settings
        DB::table('network_statistics_settings')->insert([
            [
                'key' => 'collection_interval',
                'value' => '60', // Default to 60 seconds (1 minute)
                'description' => 'Interval in seconds between network statistics collection',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'retention_hours',
                'value' => '24', // Default to 24 hours (1 day)
                'description' => 'Number of hours to retain network statistics data (12-168 hours)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('network_statistics_settings');
    }
};
