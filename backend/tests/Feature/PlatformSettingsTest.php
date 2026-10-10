<?php

namespace Tests\Feature;

use App\Console\Commands\AutomationTick;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\DepartmentJoinRequest;
use App\Models\MonthlyResolution;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Services\LeaderReportService;
use App\Support\PlatformManager;
use App\Support\PlatformSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reglages de la plateforme : droits, bornes, journalisation, et effet reel sur les automatismes
 * (une valeur absente = comportement d'avant).
 */
class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $pastor;

    private User $marie;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->pastor = $this->member('+14185580010', 'Pasteur');
        $this->pastor->roles()->attach(Role::where('key', 'super_admin')->value('id'));
        $this->marie = $this->member('+14185580000', 'Marie');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, string $first): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'gender' => 'femme', 'is_completed' => true,
            'tribe_id' => Tribe::value('id'), 'matricule' => 'VHC'.substr($phone, -4)]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());
    }

    public function test_defaults_keep_the_previous_behaviour(): void
    {
        $this->assertSame(35, PlatformSettings::int('reminders.service_lead_minutes'));
        $this->assertSame(18, PlatformSettings::int('reminders.digest_hour'));
        $this->assertSame(6, PlatformSettings::int('push.hourly_limit'));
        $this->assertSame(25, PlatformSettings::int('leader_reports.opens_on_day'));
        $this->assertSame(30, PlatformSettings::int('new_members.window_days'));
        foreach (PlatformSettings::definitions() as $key => $def) {
            if (isset($def['step'])) {
                $this->assertSame($def['default'], PlatformSettings::stepEnabled($def['step']), $key);
            }
            if (str_starts_with($key, 'module.')) {
                $this->assertTrue(PlatformSettings::enabled($key), $key);
            }
        }
        // Seul automatisme desactive par defaut : reprendre les RM oubliees (choix du pasteur).
        $this->assertFalse(PlatformSettings::stepEnabled('rm-renew'));
        $this->assertSame(0, PlatformSettings::int('services.auto_approve_after_days'));
        $this->assertSame(3, PlatformSettings::int('members.inactive_after_months'));
        // Les etapes techniques ne sont jamais suspendues.
        $this->assertTrue(PlatformSettings::stepEnabled('push-outbox'));
        $this->assertTrue(PlatformSettings::stepEnabled('prune'));
    }

    public function test_only_those_allowed_can_read_and_change_the_settings(): void
    {
        $this->actAs($this->marie);
        $this->getJson('/api/admin/settings')->assertForbidden();
        $this->putJson('/api/admin/settings', ['values' => ['automation.birthdays' => false]])->assertForbidden();

        // Reserves au Pasteur Resident et au compte de gestion : ni l'Administrateur, ni le PA, ni un patriarche.
        $this->actAs($this->pastor);
        $this->getJson('/api/admin/settings')->assertOk()->assertJsonPath('groups.0.settings.0.key', 'module.vertumetre');
        foreach (['administrateur', 'pasteur_assistant'] as $i => $roleKey) {
            $someone = $this->member('+1418558002'.$i, 'Role'.$i);
            $someone->roles()->attach(Role::where('key', $roleKey)->value('id'));
            $this->actAs($someone);
            $this->getJson('/api/admin/settings')->assertForbidden();
            $this->getJson('/api/me')->assertOk();
            $this->assertNotContains('settings.manage', $this->getJson('/api/me')->json('permissions'));
        }
        $patriarche = $this->member('+14185580030', 'Patriarche');
        $patriarche->roles()->attach(Role::where('key', 'patriarche')->value('id'), ['scope_kind' => 'tribe', 'scope_id' => Tribe::value('id')]);
        $this->actAs($patriarche);
        $this->getJson('/api/admin/settings')->assertForbidden();
    }

    public function test_a_change_is_saved_logged_and_can_be_reset(): void
    {
        $this->actAs($this->pastor);
        $this->putJson('/api/admin/settings', ['values' => ['automation.birthdays' => false, 'reminders.service_lead_minutes' => 20]])
            ->assertOk()->assertJsonPath('message', 'Réglages enregistrés.');
        $this->assertFalse(PlatformSettings::enabled('automation.birthdays'));
        $this->assertSame(20, PlatformSettings::int('reminders.service_lead_minutes'));

        $log = AuditLog::where('action', 'settings.updated')->latest('id')->first();
        $this->assertSame(['automation.birthdays' => true, 'reminders.service_lead_minutes' => 35], $log->old_values);
        $this->assertSame(['automation.birthdays' => false, 'reminders.service_lead_minutes' => 20], $log->new_values);

        $row = collect($this->getJson('/api/admin/settings')->json('groups'))->flatMap(fn ($g) => $g['settings'])->firstWhere('key', 'automation.birthdays');
        $this->assertFalse($row['value']);
        $this->assertTrue($row['changed']);
        $this->assertTrue($row['default']);

        // Retour a la valeur par defaut : la ligne disparait.
        $this->putJson('/api/admin/settings', ['values' => ['automation.birthdays' => null, 'reminders.service_lead_minutes' => 35]])->assertOk();
        $this->assertSame(0, DB::table('platform_settings')->count());
        $this->assertTrue(PlatformSettings::enabled('automation.birthdays'));

        // Rien de change : pas d'entree au journal.
        $count = AuditLog::where('action', 'settings.updated')->count();
        $this->putJson('/api/admin/settings', ['values' => ['automation.fiss' => true]])->assertOk()->assertJsonPath('message', 'Aucun changement.');
        $this->assertSame($count, AuditLog::where('action', 'settings.updated')->count());
    }

    public function test_out_of_range_or_unknown_values_are_refused(): void
    {
        $this->actAs($this->pastor);
        $this->putJson('/api/admin/settings', ['values' => ['reminders.service_lead_minutes' => 5]])->assertStatus(422)
            ->assertJsonValidationErrors(['values.reminders.service_lead_minutes']);
        $this->putJson('/api/admin/settings', ['values' => ['reminders.digest_hour' => 23]])->assertStatus(422);
        $this->putJson('/api/admin/settings', ['values' => ['push.hourly_limit' => 'beaucoup']])->assertStatus(422);
        $this->putJson('/api/admin/settings', ['values' => ['automation.fiss' => 'non']])->assertStatus(422);
        $this->putJson('/api/admin/settings', ['values' => ['app.debug' => true]])->assertStatus(422);
        // Une erreur n'enregistre rien, meme pour les valeurs correctes envoyees avec.
        $this->putJson('/api/admin/settings', ['values' => ['automation.fiss' => false, 'push.hourly_limit' => 99]])->assertStatus(422);
        $this->assertTrue(PlatformSettings::enabled('automation.fiss'));
        $this->assertSame(0, DB::table('platform_settings')->count());
    }

    public function test_a_paused_reminder_is_not_sent_and_settings_change_real_behaviour(): void
    {
        $this->actAs($this->pastor);
        $this->putJson('/api/admin/settings', ['values' => [
            'automation.birthdays' => false, 'leader_reports.opens_on_day' => 20, 'new_members.window_days' => 7,
        ]])->assertOk();

        // Anniversaire aujourd'hui : aucun voeu envoye, l'etape est marquee suspendue.
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $this->marie->profile->forceFill(['birth_day' => 12, 'birth_month' => 10])->save();
        Artisan::call('app:tick');
        $this->assertTrue(Cache::get(AutomationTick::STATUS_KEY)['steps']['birthdays']['paused'] ?? false);
        $this->assertSame(0, $this->marie->notifications()->where('type', 'birthday')->count());
        // Reactive : les voeux partent au passage suivant.
        $this->putJson('/api/admin/settings', ['values' => ['automation.birthdays' => true]])->assertOk();
        Artisan::call('app:tick', ['--only' => 'birthdays']);
        $this->assertSame(1, $this->marie->notifications()->where('type', 'birthday')->count());

        // Rapport mensuel : ouvert des le 20 (au lieu du 25).
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00'));
        $this->assertContains('2026-10', LeaderReportService::openPeriods());

        // Nouveaux membres : periode d'accueil de 7 jours.
        $this->getJson('/api/admin/new-members')->assertOk()->assertJsonPath('window_days', 7);
    }

    public function test_the_platform_manager_changes_settings_into_the_private_security_log(): void
    {
        [$manager] = PlatformManager::activate((string) PlatformManager::phone());
        $this->assertContains('settings.manage', PlatformManager::permissions());
        $this->actAs($manager);
        $this->putJson('/api/admin/settings', ['values' => ['automation.weddings' => false]])->assertOk();
        $this->assertSame(0, AuditLog::where('action', 'settings.updated')->count());
        $this->assertSame(1, DB::table('security_logs')->where('action', 'settings.updated')->count());
    }

    public function test_a_disabled_module_is_closed_everywhere_and_comes_back_intact(): void
    {
        $this->actAs($this->marie);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('settings.modules.vertumetre', true)
            ->assertJsonPath('settings.push_prompt_snooze_days', 3);
        $this->postJson('/api/me/vertumetre', [])->assertStatus(422); // ouvert : la validation repond

        $this->actAs($this->pastor);
        $this->putJson('/api/admin/settings', ['values' => ['module.vertumetre' => false, 'module.exercises' => false]])->assertOk();

        $this->actAs($this->marie);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('settings.modules.vertumetre', false)->assertJsonPath('settings.modules.resolutions', true);
        $this->getJson('/api/me/vertumetre')->assertForbidden()->assertJsonPath('module', 'vertumetre');
        $this->postJson('/api/me/vertumetre', [])->assertForbidden();
        $this->getJson('/api/me/exercises')->assertForbidden();
        // Accueil groupe : meme fermeture ; parcours du mois sans le Vertumetre.
        $home = $this->getJson('/api/me/home?paths[]='.urlencode('/me/exercises').'&paths[]='.urlencode('/me/month'))->assertOk();
        $this->assertSame(403, $home->json('responses./me/exercises.status'));
        $this->assertArrayNotHasKey('vertumetre', $home->json('responses./me/month.body.steps'));
        $this->assertArrayHasKey('resolutions', $home->json('responses./me/month.body.steps'));
        // Rappels du module arretes.
        $this->assertFalse(PlatformSettings::stepEnabled('vertumetre'));
        $this->assertFalse(PlatformSettings::stepEnabled('tasks'));

        $this->actAs($this->pastor);
        $this->putJson('/api/admin/settings', ['values' => ['module.vertumetre' => true, 'module.exercises' => true]])->assertOk();
        $this->actAs($this->marie);
        $this->getJson('/api/me/vertumetre')->assertOk();
        $this->getJson('/api/me/exercises')->assertOk();
        $this->assertTrue(PlatformSettings::stepEnabled('vertumetre'));
    }

    public function test_service_requests_are_followed_up_then_approved_automatically_when_chosen(): void
    {
        $leader = $this->member('+14185580040', 'Responsable');
        $dept = Department::create(['name' => 'Louange', 'slug' => 'louange', 'is_active' => true]);
        $dept->leaders()->attach($leader->id);
        $this->actAs($this->marie);
        $this->postJson("/api/me/services/{$dept->id}/join")->assertOk()->assertJsonPath('status', 'pending');
        $count = fn (User $u, string $like) => $u->notifications()->where('title', 'like', $like)->count();

        // Moins de 2 jours : rien. Apres 2 jours : une seule relance au responsable.
        Carbon::setTestNow(now()->addDay()->setTime(10, 0));
        Artisan::call('app:tick', ['--only' => 'service-joins']);
        $this->assertSame(0, $count($leader, 'En attente%'));
        Carbon::setTestNow(now()->addDays(2)->setTime(10, 0));
        Artisan::call('app:tick', ['--only' => 'service-joins']);
        Artisan::call('app:tick', ['--only' => 'service-joins']);
        $this->assertSame(1, $count($leader, 'En attente%'));
        $this->assertSame('pending', DepartmentJoinRequest::sole()->status);

        // Validation automatique choisie (5 jours) : acceptee, le membre rejoint le service, chacun est prevenu.
        $this->actAs($this->pastor);
        $this->putJson('/api/admin/settings', ['values' => ['services.auto_approve_after_days' => 5]])->assertOk();
        Carbon::setTestNow(now()->addDays(3)->setTime(10, 0));
        Artisan::call('app:tick', ['--only' => 'service-joins']);
        $request = DepartmentJoinRequest::sole();
        $this->assertSame('approved', $request->status);
        $this->assertNull($request->decided_by);
        $this->assertTrue($this->marie->profile->departments()->whereKey($dept->id)->exists());
        $this->assertSame(1, $count($this->marie, 'Bienvenue au service%'));
        $this->assertSame(1, $count($leader, 'Nouveau serviteur%'));
        $this->assertSame(1, AuditLog::where('action', 'service_join.auto_approved')->count());
    }

    public function test_forgotten_resolutions_are_renewed_only_when_chosen(): void
    {
        MonthlyResolution::create(['user_id' => $this->marie->id, 'period' => '2026-09', 'priere' => 'Prier chaque matin']);
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00'));

        // Par defaut : rien n'est repris.
        Artisan::call('app:tick', ['--only' => 'rm-renew']);
        $this->assertSame(0, MonthlyResolution::where('period', '2026-10')->count());

        $this->actAs($this->pastor);
        $this->putJson('/api/admin/settings', ['values' => ['automation.rm_auto_renew' => true]])->assertOk();
        Artisan::call('app:tick', ['--only' => 'rm-renew']);
        $renewed = MonthlyResolution::where('user_id', $this->marie->id)->where('period', '2026-10')->sole();
        $this->assertSame('Prier chaque matin', $renewed->priere);
        $this->assertSame(['priere'], $renewed->renewed);
        $this->assertSame(1, $this->marie->notifications()->where('title', 'Vos résolutions sont reprises')->count());
        // Une seule fois.
        Artisan::call('app:tick', ['--only' => 'rm-renew']);
        $this->assertSame(1, MonthlyResolution::where('period', '2026-10')->count());
    }

    public function test_inactivity_and_absence_delays_follow_the_settings(): void
    {
        $last = now()->subMonths(2)->subDay();
        $this->assertSame('active', User::activityFrom(null, $last));
        $this->actAs($this->pastor);
        $this->putJson('/api/admin/settings', ['values' => ['members.inactive_after_months' => 2]])->assertOk();
        $this->assertSame('inactive', User::activityFrom(null, $last));
    }
}
