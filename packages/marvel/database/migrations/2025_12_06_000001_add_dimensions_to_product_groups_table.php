<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDimensionsToProductGroupsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('product_groups', 'height')) {
            Schema::table('product_groups', function (Blueprint $table) {
                $table->string('height')->nullable()->after('short_description');
            });
        }

        if (!Schema::hasColumn('product_groups', 'length')) {
            Schema::table('product_groups', function (Blueprint $table) {
                $table->string('length')->nullable()->after('height');
            });
        }

        if (!Schema::hasColumn('product_groups', 'width')) {
            Schema::table('product_groups', function (Blueprint $table) {
                $table->string('width')->nullable()->after('length');
            });
        }

        if (!Schema::hasColumn('product_groups', 'weight')) {
            Schema::table('product_groups', function (Blueprint $table) {
                $table->decimal('weight', 10, 2)->nullable()->after('width');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $columns = array_values(array_filter(
            ['height', 'length', 'width', 'weight'],
            fn ($column) => Schema::hasColumn('product_groups', $column)
        ));

        if ($columns !== []) {
            Schema::table('product_groups', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
}

