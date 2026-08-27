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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('store_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'email']);
            $table->unique('stripe_customer_id');
            $table->index('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
