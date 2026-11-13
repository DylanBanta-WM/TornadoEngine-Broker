<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The rule that should cover every student; the others are checked against it.
        Schema::table('broker_rules', function (Blueprint $table) {
            $table->boolean('primary')->default(false)->after('sub_ous');
        });
    }

    public function down(): void
    {
        Schema::table('broker_rules', function (Blueprint $table) {
            $table->dropColumn('primary');
        });
    }
};
