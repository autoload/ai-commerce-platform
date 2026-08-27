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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('pending');
            $table->string('status_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_platform_admin_id')
                ->nullable()
                ->constrained('platform_admins')
                ->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_platform_admin_id')
                ->nullable()
                ->constrained('platform_admins')
                ->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by_platform_admin_id')
                ->nullable()
                ->constrained('platform_admins')
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
