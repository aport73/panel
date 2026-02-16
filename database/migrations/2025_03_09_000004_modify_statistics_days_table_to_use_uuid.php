<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        Schema::table('statistics_days', function (Blueprint $table) {
            $table->dropForeign(['server_id']);
            $table->dropIndex(['server_id', 'collected_at']);
        });

        Schema::table('statistics_days', function (Blueprint $table) {
            $table->string('server_uuid')->nullable()->after('server_id');
        });

        DB::statement('UPDATE statistics_days sd 
                      INNER JOIN servers s ON s.id = sd.server_id 
                      SET sd.server_uuid = s.uuid');

        Schema::table('statistics_days', function (Blueprint $table) {
            $table->string('server_uuid')->nullable(false)->change();
            $table->dropColumn('server_id');

            $table->foreign('server_uuid')
                  ->references('uuid')
                  ->on('servers')
                  ->onDelete('cascade');

            $table->index(['server_uuid', 'collected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statistics_days', function (Blueprint $table) {
            $table->dropForeign(['server_uuid']);
            $table->dropIndex(['server_uuid', 'collected_at']);
        });

        Schema::table('statistics_days', function (Blueprint $table) {
            $table->unsignedInteger('server_id')->nullable()->after('id');
        });

        DB::statement('UPDATE statistics_days sd 
                      INNER JOIN servers s ON s.uuid = sd.server_uuid 
                      SET sd.server_id = s.id');

        Schema::table('statistics_days', function (Blueprint $table) {
            $table->unsignedInteger('server_id')->nullable(false)->change();
            $table->dropColumn('server_uuid');

            $table->foreign('server_id')
                  ->references('id')
                  ->on('servers')
                  ->onDelete('cascade');

            $table->index(['server_id', 'collected_at']);
        });
    }
};
