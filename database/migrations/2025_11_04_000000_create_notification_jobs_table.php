<?php
// database/migrations/2025_11_04_000000_create_notification_jobs_table.php (snippet)
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('notification_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('channel');
            $table->string('recipient');
            $table->text('message');
            $table->enum('status', ['PENDING','PROCESSING','RETRY','SUCCESS','FAILED'])->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('notification_jobs');
    }
};
