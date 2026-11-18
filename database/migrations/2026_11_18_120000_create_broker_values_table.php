<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Values taken out of text that aren't one person's, encrypted, so their placeholders ([id v-…]) can be turned back.
        Schema::create('broker_values', function (Blueprint $table) {
            $table->string('code', 12)->primary();
            $table->text('value');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_values');
    }
};
