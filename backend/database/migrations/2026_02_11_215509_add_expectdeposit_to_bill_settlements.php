<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_settlements', function (Blueprint $table) {

            // After unpaidbalance
            $table->date('expectdepositdate')
                ->nullable()
                ->after('unpaidbalance');

            $table->decimal('expectdepositamount', 15, 2)
                ->nullable()
                ->after('expectdepositdate');

        });
    }

    public function down(): void
    {
        Schema::table('bill_settlements', function (Blueprint $table) {

            $table->dropColumn([
                'expectdepositdate',
                'expectdepositamount'
            ]);

        });
    }
};
