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
        Schema::create('product_variant_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('product_option_value_id')
                ->constrained()
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['product_variant_id', 'product_option_value_id'],
                'pvov_variant_id_option_value_id_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variant_option_values');
    }
};
