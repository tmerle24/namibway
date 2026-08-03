<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->foreignId('region_id')->nullable()->after('image')->constrained()->cascadeOnDelete();
        });

        $regionIds = DB::table('regions')->pluck('id', 'name');

        foreach (DB::table('destinations')->whereNotNull('region_name')->get(['id', 'region_name']) as $destination) {
            DB::table('destinations')
                ->where('id', $destination->id)
                ->update(['region_id' => $regionIds[$destination->region_name] ?? null]);
        }

        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn('region_name');
            $table->unsignedBigInteger('region_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->string('region_name')->nullable()->after('image');
        });

        $regionNames = DB::table('regions')->pluck('name', 'id');

        foreach (DB::table('destinations')->get(['id', 'region_id']) as $destination) {
            DB::table('destinations')
                ->where('id', $destination->id)
                ->update(['region_name' => $regionNames[$destination->region_id] ?? null]);
        }

        Schema::table('destinations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('region_id');
        });
    }
};
