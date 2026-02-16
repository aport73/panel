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
        Schema::create('sql_history', function (Blueprint $table) {
            $table->id();
            $table->string('server_uuid');
            $table->string('database_id');
            $table->string('database_name');
            $table->unsignedBigInteger('user_id')->nullable(); // Pterodactyl user ID
            $table->text('query');
            $table->text('query_hash'); // SHA256 hash for deduplication
            $table->enum('query_type', ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'OTHER']);
            $table->integer('execution_time_ms')->nullable(); // Query execution time in milliseconds
            $table->integer('rows_affected')->nullable();
            $table->enum('status', ['success', 'error'])->default('success');
            $table->text('error_message')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->string('favorite_name')->nullable();
            $table->text('description')->nullable();
            $table->json('tags')->nullable(); // Array of tags for organization
            $table->timestamps();
            
            $table->index(['server_uuid', 'database_id']);
            $table->index(['user_id', 'server_uuid']);
            $table->index(['query_type', 'status']);
            $table->index(['is_favorite', 'user_id']);
            $table->index('query_hash');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sql_history');
    }
};
