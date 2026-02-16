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
        Schema::create('panic_mode_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        
        // Insert default settings
        DB::table('panic_mode_settings')->insert([
            [
                'key' => 'discord_webhook_url',
                'value' => '',
                'description' => 'Discord webhook URL for Panic Mode alerts (encrypted)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'bandwidth_threshold_mbps',
                'value' => '1',
                'description' => 'Bandwidth threshold in Mbps that triggers Panic Mode',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'enabled',
                'value' => '0',
                'description' => 'Whether Panic Mode is enabled (1) or disabled (0)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'cooldown_minutes',
                'value' => '15',
                'description' => 'Cooldown period in minutes between alerts for the same server',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'embed_color',
                'value' => '16711680',
                'description' => 'Discord embed color in decimal format (default: 16711680 - red)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'embed_title',
                'value' => '🚨 PANIC MODE ALERT: High Bandwidth Usage',
                'description' => 'Title for the Discord embed alert',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'embed_description',
                'value' => 'A server has exceeded the bandwidth threshold',
                'description' => 'Description for the Discord embed alert',
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
        Schema::dropIfExists('panic_mode_settings');
    }
};
