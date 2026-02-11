<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('short_links', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
            $table->string('utm_source')->nullable()->after('long_url');
            $table->string('utm_medium')->nullable()->after('utm_source');
            $table->string('utm_campaign')->nullable()->after('utm_medium');
            $table->string('utm_term')->nullable()->after('utm_campaign');
            $table->string('utm_content')->nullable()->after('utm_term');

            $table->index('user_id', 'idx_short_links_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('short_links', function (Blueprint $table): void {
            $table->dropIndex('idx_short_links_user_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn([
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_term',
                'utm_content',
            ]);
        });
    }
};
