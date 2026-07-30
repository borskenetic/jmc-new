<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_strands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->timestamps();
        });

        foreach (config('patron.shs_strands', []) as $name) {
            DB::table('school_strands')->insertOrIgnore([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_strands');
    }
};
