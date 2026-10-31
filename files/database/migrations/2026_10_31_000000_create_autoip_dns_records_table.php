<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('autoip_dns_records')) {
            return;
        }
        Schema::create('autoip_dns_records', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id')->unique();
            $table->string('hostname', 191)->index();
            $table->string('zone_id', 64);
            $table->text('record_ids');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autoip_dns_records');
    }
};
