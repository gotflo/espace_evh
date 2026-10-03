<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesures collectees par l'agent de supervision et lues par la console (projet separe
 * evh_monitoring). Aucune donnee privee : ni corps de requete, ni parametres d'URL, ni code
 * de connexion ; les routes sont enregistrees sous leur forme generique (api/admin/members/{user}).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Compteurs des requetes de l'API par tranche de 5 minutes (une ligne par route et statut).
        Schema::create('monitor_request_stats', function (Blueprint $table) {
            $table->id();
            $table->dateTime('bucket');
            $table->string('service', 40);
            $table->string('route', 160);
            $table->string('method', 8);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);
            $table->unsignedInteger('max_ms')->default(0);
            $table->unsignedInteger('slow_hits')->default(0);
            // Repartition des durees : <= 100 ms, <= 300 ms, <= 1 s, <= 3 s (le reste : > 3 s).
            $table->unsignedInteger('h_100')->default(0);
            $table->unsignedInteger('h_300')->default(0);
            $table->unsignedInteger('h_1000')->default(0);
            $table->unsignedInteger('h_3000')->default(0);
            $table->unsignedBigInteger('queries')->default(0);
            $table->unsignedBigInteger('db_ms')->default(0);

            $table->unique(['bucket', 'route', 'method', 'status'], 'monitor_request_stats_unique');
            $table->index(['bucket', 'service']);
        });

        // Detail des seules requetes notables : erreurs serveur, refus (401/403), limites (429), lenteurs.
        Schema::create('monitor_requests', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->nullable();
            $table->string('request_id', 32);
            $table->string('reason', 12);
            $table->string('method', 8);
            $table->string('route', 160);
            $table->string('service', 40);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('queries')->default(0);
            $table->unsignedInteger('db_ms')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip', 45)->nullable();

            $table->index('created_at');
            $table->index('request_id');
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Journal central : erreurs et avertissements du serveur, du navigateur, des integrations
        // et des taches, connexions et evenements de securite.
        Schema::create('monitor_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->nullable();
            $table->string('level', 10);
            $table->string('service', 30);
            $table->string('type', 50);
            $table->string('outcome', 12)->nullable();
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->string('fingerprint', 40)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('request_id', 32)->nullable();
            $table->string('route', 160)->nullable();
            $table->string('ip', 45)->nullable();

            $table->index('created_at');
            $table->index(['type', 'created_at']);
            $table->index(['level', 'created_at']);
            $table->index(['service', 'created_at']);
            $table->index(['fingerprint', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('request_id');
        });

        // Jours d'utilisation par membre (membres actifs par jour, semaine, mois).
        Schema::create('monitor_user_days', function (Blueprint $table) {
            $table->date('day');
            $table->unsignedBigInteger('user_id');

            $table->primary(['day', 'user_id']);
            $table->index('user_id');
        });

        // Appels aux services externes (Twilio Verify, envoi des notifications push) par heure.
        Schema::create('monitor_integration_stats', function (Blueprint $table) {
            $table->id();
            $table->dateTime('bucket');
            $table->string('integration', 30);
            $table->string('operation', 40);
            $table->unsignedInteger('ok')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);

            $table->unique(['bucket', 'integration', 'operation'], 'monitor_integration_stats_unique');
        });

        // Passages des taches planifiees (automatismes, boite d'envoi push).
        Schema::create('monitor_job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command', 40);
            $table->string('trigger', 12);
            $table->timestamp('started_at')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status', 10);
            $table->json('summary')->nullable();

            $table->index(['command', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        foreach (['monitor_job_runs', 'monitor_integration_stats', 'monitor_user_days', 'monitor_events', 'monitor_requests', 'monitor_request_stats'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
