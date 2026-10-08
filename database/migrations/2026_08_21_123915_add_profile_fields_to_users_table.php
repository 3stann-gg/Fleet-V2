<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 60)->nullable()->after('name');
            $table->string('middle_name', 60)->nullable()->after('first_name');
            $table->string('last_name', 60)->nullable()->after('middle_name');

            $table->string('employee_id', 40)
                ->nullable()
                ->unique()
                ->after('email');

            $table->string('department', 120)
                ->nullable()
                ->after('employee_id');

            $table->string('job_title', 120)
                ->nullable()
                ->after('department');

            $table->string('mobile_number', 40)
                ->nullable()
                ->after('job_title');

            $table->string('office_extension', 20)
                ->nullable()
                ->after('mobile_number');

            $table->string('office_location', 200)
                ->nullable()
                ->after('office_extension');

            $table->string('profile_photo')
                ->nullable()
                ->after('office_location');

            $table->timestamp('last_login_at')
                ->nullable()
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_id']);

            $table->dropColumn([
                'first_name',
                'middle_name',
                'last_name',
                'employee_id',
                'department',
                'job_title',
                'mobile_number',
                'office_extension',
                'office_location',
                'profile_photo',
                'last_login_at',
            ]);
        });
    }
};