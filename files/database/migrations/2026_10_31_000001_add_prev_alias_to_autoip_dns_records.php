<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('autoip_dns_records') && !Schema::hasColumn('autoip_dns_records', 'prev_alias')) {
            Schema::table('autoip_dns_records', function (Blueprint $table) {
                $table->string('prev_alias', 191)->nullable()->after('record_ids');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('autoip_dns_records', 'prev_alias')) {
            Schema::table('autoip_dns_records', function (Blueprint $table) {
                $table->dropColumn('prev_alias');
            });
        }
    }
};
