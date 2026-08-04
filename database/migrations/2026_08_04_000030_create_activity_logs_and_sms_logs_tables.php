<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('action', 120);
            $table->string('description')->nullable();
            $table->string('method', 16)->nullable();
            $table->string('route_name')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['action']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient', 32);
            $table->text('message');
            $table->string('status', 32)->default('pending');
            $table->string('source', 64)->default('direct');
            $table->string('error', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['status']);
            $table->index(['source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('activity_logs');
    }
};
