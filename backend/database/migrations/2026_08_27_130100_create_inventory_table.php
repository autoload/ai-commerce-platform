<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->integer('quantity_on_hand')->default(0);
            $table->integer('low_stock_threshold')->nullable();
            $table->timestamps();

            $table->unique('product_variant_id');
        });

        DB::statement('ALTER TABLE inventory ADD CONSTRAINT inventory_quantity_on_hand_non_negative CHECK (quantity_on_hand >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
