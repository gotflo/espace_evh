<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rendez-vous avec le pasteur :
 * - appointment_settings : reglages d'un pasteur (duree d'un rendez-vous, rendez-vous ouverts ou non) ;
 * - appointment_windows : plages de disponibilite, chaque semaine (weekday) ou a une date precise (date) ;
 * - appointment_blocks : seance ou creneau bloque a une date (pas de rendez-vous) ;
 * - appointments : demandes et rendez-vous (slot_key unique = un seul rendez-vous par creneau).
 * Permissions : appointments.host (recevoir des rendez-vous), appointments.organize (organiser, protocole) ;
 * role « Équipe du protocole » a attribuer aux membres du protocole.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('slot_minutes')->default(15);
            $table->boolean('accepting')->default(true);
            $table->timestamps();
        });

        Schema::create('appointment_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday')->nullable(); // 0 = dimanche … 6 = samedi (chaque semaine)
            $table->date('date')->nullable(); // plage ajoutee a une date precise
            $table->string('starts_at', 5); // HH:MM
            $table->string('ends_at', 5);
            $table->timestamps();

            $table->index(['host_id', 'weekday']);
            $table->index(['host_id', 'date']);
        });

        Schema::create('appointment_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->string('starts_at', 5)->nullable(); // vide = toute la journee
            $table->string('ends_at', 5)->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['host_id', 'date']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->default('pending'); // pending | confirmed | done | no_show | cancelled
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('slot_key', 40)->nullable()->unique(); // host|Y-m-d H:i tant que confirme
            $table->string('preference', 12)->default('slot'); // slot | session | first
            $table->date('preferred_date')->nullable();
            $table->string('urgency', 10)->default('normal'); // normal | important | urgent
            $table->text('message')->nullable(); // lu par le pasteur seulement
            $table->string('staff_note', 500)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['host_id', 'status', 'starts_at']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'preferred_date']);
        });

        // Permissions et role du protocole (sans relancer le seeder en production).
        $now = now();
        $permissions = [
            'appointments.host' => ['Recevoir des rendez-vous (pasteur)', 'Rendez-vous'],
            'appointments.organize' => ['Organiser les rendez-vous avec le pasteur (protocole)', 'Rendez-vous'],
        ];
        foreach ($permissions as $key => [$name, $group]) {
            if (! DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert(['key' => $key, 'name' => $name, 'group' => $group, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $roleId = DB::table('roles')->where('key', 'protocole')->value('id') ?? DB::table('roles')->insertGetId([
            'key' => 'protocole', 'name' => 'Équipe du protocole',
            'description' => 'Consulte les demandes de rendez-vous avec le pasteur et organise les rencontres après les cultes.',
            'scope_kind' => 'none', 'rank' => 30, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach (['appointments.organize', 'exercises.respond'] as $key) {
            $pid = DB::table('permissions')->where('key', $key)->value('id');
            if ($pid && ! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $pid])->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $pid]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('appointment_blocks');
        Schema::dropIfExists('appointment_windows');
        Schema::dropIfExists('appointment_settings');

        $roleIds = DB::table('roles')->where('key', 'protocole')->pluck('id');
        DB::table('role_user')->whereIn('role_id', $roleIds)->delete();
        DB::table('permission_role')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();
        $permIds = DB::table('permissions')->whereIn('key', ['appointments.host', 'appointments.organize'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('id', $permIds)->delete();
    }
};
