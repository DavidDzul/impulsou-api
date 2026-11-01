<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/catalogo-bancos PR1: administrable bank catalog.
 *
 * `code` is the 3-digit Banxico code (the CLABE prefix). `is_system` marks the
 * locked batch bank (BBVA, 012): its code cannot change and it cannot be
 * deactivated or deleted. Purely additive, safe to run before the API deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
