<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Who gets a pseudonym is now decided by roles (Access); student rules live in the access modules.
    public function up(): void
    {
        Schema::dropIfExists('broker_rules');
        DB::table('broker_people')->delete();
        DB::table('settings')->whereIn('key', ['broker.refreshed_at', 'broker.groups', 'broker.missed_by_primary', 'broker.report'])->delete();
    }

    public function down(): void {}
};
