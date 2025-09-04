<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSyncStatusTable extends Migration
{
    public function up()
    {
        Schema::create('sync_status', function (Blueprint $table) {
            $table->id();
            $table->string('file_path')->unique();
            $table->bigInteger('last_modified');
            $table->string('file_hash');
            $table->timestamp('last_sync');
            $table->integer('sync_count')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('sync_status');
    }
}