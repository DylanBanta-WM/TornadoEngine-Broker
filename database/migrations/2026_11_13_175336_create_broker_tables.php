<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who counts as a student: an OU, a Google Group, or an email pattern (regular expression).
        Schema::create('broker_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('value');
            $table->boolean('sub_ous')->default(true);
            $table->timestamps();
        });

        // The broker's copy of the directory: each person's pseudonym and every value that identifies them.
        Schema::create('broker_people', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('email')->unique();
            $table->string('pseudonym')->unique();
            $table->json('values');
            $table->timestamps();
        });

        // Every call Claude makes through the broker, with the real command, for admins.
        Schema::create('broker_log', function (Blueprint $table) {
            $table->id();
            $table->string('tool');
            $table->json('command');
            $table->integer('exit')->nullable();
            $table->boolean('ok');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_log');
        Schema::dropIfExists('broker_people');
        Schema::dropIfExists('broker_rules');
    }
};
