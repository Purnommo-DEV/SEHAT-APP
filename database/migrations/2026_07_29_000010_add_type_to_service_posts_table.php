<?php

use App\Enums\ServicePostType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_posts', function (Blueprint $table): void {
            $table->string('type', 20)->default(ServicePostType::Custom->value)->after('description')->index();
        });
    }

    public function down(): void
    {
        Schema::table('service_posts', function (Blueprint $table): void {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
