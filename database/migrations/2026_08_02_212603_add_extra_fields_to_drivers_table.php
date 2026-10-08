<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {

            $table->string('email')->nullable()->after('contact_number');

            $table->unsignedTinyInteger('experience')
                  ->nullable()
                  ->after('email');

            $table->text('address')
                  ->nullable()
                  ->after('experience');

            $table->string('emergency_contact')
                  ->nullable()
                  ->after('address');

            $table->text('notes')
                  ->nullable()
                  ->after('emergency_contact');

            $table->string('photo')
                  ->nullable()
                  ->after('notes');

        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {

            $table->dropColumn([
                'email',
                'experience',
                'address',
                'emergency_contact',
                'notes',
                'photo',
            ]);

        });
    }
};
