<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentResponse;
use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\LeaderReport;
use App\Models\MemberRequest;
use App\Models\MonthlyResolution;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SecurityLog;
use App\Models\SpiritualEntry;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Models\VertumetreForm;
use App\Support\PermissionCatalog;
use App\Support\Phone;
use App\Support\PlatformManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Compte de gestion principal (+22893471703) : numero reserve normalise, activation OTP + code a usage
 * unique, droits de gestion sans donnees confidentielles, absent des listes, journal de securite prive.
 */
class PlatformManagerTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+22893471703';

    private Tribe $juda;

    private User $pastor;

    private User $marie;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));
        Http::preventStrayRequests();
        config(['app.expose_otp' => true, 'services.sms.driver' => 'log', 'platform.manager.phone' => self::PHONE]);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->pastor = $this->member('+14185570010', 'Pasteur', 'Résident');
        $this->pastor->roles()->attach(Role::where('key', 'super_admin')->value('id'));
        $this->marie = $this->member('+14185570000', 'Marie', 'Tremblay');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, string $first, string $last): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'femme',
            'is_completed' => true, 'tribe_id' => $this->juda->id, 'matricule' => 'VHC'.substr($phone, -4)]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());
    }

    private function manager(): User
    {
        [$user] = PlatformManager::activate(self::PHONE);

        return $user;
    }

    /** Saisie du numero sur la page de connexion habituelle (avec le jeton de l'appareil s'il en a un). */
    private function enterNumber(?string $device = null, string $phone = '93 47 17 03', string $country = 'TG')
    {
        $this->app['auth']->forgetGuards();
        Carbon::setTestNow(now()->addSeconds(13)); // limites par adresse

        return $this->postJson('/api/auth/request-otp', array_filter(['phone' => $phone, 'country' => $country, 'device_token' => $device]))->assertOk();
    }

    /** Autorisation de l'appareil avec le code genere sur le serveur. */
    private function authorize(string $code)
    {
        $this->app['auth']->forgetGuards();
        Carbon::setTestNow(now()->addSeconds(13));

        return $this->postJson('/api/auth/manager-activation', ['phone' => self::PHONE, 'activation_code' => $code]);
    }

    private function issueCode(): string
    {
        Artisan::call('app:manager-activation-code');
        preg_match('/Code d\'autorisation : ([A-Z0-9-]+)/', Artisan::output(), $m);

        return $m[1];
    }

    public function test_reserved_number_is_normalized_before_comparison(): void
    {
        foreach (['+22893471703', '+228 93 47 17 03', '+228-93-47-17-03', '0022893471703'] as $input) {
            $this->assertTrue(PlatformManager::isReservedPhone(Phone::normalize($input, 'FR')), $input);
        }
        $this->assertTrue(PlatformManager::isReservedPhone(Phone::normalize('93 47 17 03', 'TG')));
        $this->assertFalse(PlatformManager::isReservedPhone(Phone::normalize('+22893471704')));
        $this->assertFalse(PlatformManager::isReservedPhone(Phone::normalize('93 47 17 03', 'CA')));
        $this->assertFalse(PlatformManager::isReservedPhone(null));
    }

    public function test_the_number_never_goes_through_the_sms_code_and_alone_does_not_open_an_unknown_device(): void
    {
        $users = User::count();
        $this->enterNumber()
            ->assertJsonPath('manager_device_required', true)->assertJsonPath('phone', self::PHONE)
            ->assertJsonMissingPath('dev_code')->assertJsonMissingPath('token');
        // Aucun code SMS cree ni envoye, aucun compte ordinaire cree.
        $this->assertDatabaseMissing('otp_codes', ['phone' => self::PHONE]);
        $this->assertSame($users, User::count());
        // La voie du code SMS est fermee a ce numero.
        $this->postJson('/api/auth/verify-otp', ['phone' => self::PHONE, 'code' => '123456'])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->assertDatabaseHas('security_logs', ['action' => 'manager.otp_refused', 'level' => 'warning']);
        // Un faux jeton d'appareil ne donne rien.
        $this->enterNumber(str_repeat('a', 64))->assertJsonPath('manager_device_required', true)->assertJsonMissingPath('token');
        $this->assertSame(0, User::whereNotNull('platform_manager_at')->count());
    }

    public function test_a_trusted_device_enters_with_the_number_alone(): void
    {
        $code = $this->issueCode();
        $this->authorize('AAAA-BBBB-CCCC')->assertStatus(422)->assertJsonValidationErrors(['activation_code']);
        $this->assertDatabaseHas('security_logs', ['action' => 'manager.activation_failed', 'level' => 'alert']);
        $this->assertSame(0, User::whereNotNull('platform_manager_at')->count());

        $first = $this->authorize(strtolower($code))->assertOk()
            ->assertJsonPath('is_platform_manager', true)->assertJsonPath('is_super_admin', false)
            ->assertJsonPath('profile_completed', true)->assertJsonPath('roles', []);
        $device = $first->json('device_token');
        $this->assertSame(64, strlen($device));
        $this->assertEqualsCanonicalizing(PermissionCatalog::managementKeys(), $first->json('permissions'));
        foreach (['members.view_all', 'spiritual.view', 'reports.view', 'fiss.review', 'audit.view_details', 'exercises.view_responses'] as $confidential) {
            $this->assertNotContains($confidential, $first->json('permissions'));
        }
        // Seule l'empreinte du jeton est gardee.
        $this->assertDatabaseMissing('manager_devices', ['token_hash' => $device]);
        $this->assertDatabaseHas('security_logs', ['action' => 'manager.activated']);
        $this->assertDatabaseHas('security_logs', ['action' => 'manager.device_authorized']);

        // Ensuite, sur cet appareil : le numero suffit, sur la page habituelle (ni SMS, ni code).
        $login = $this->enterNumber($device)->assertJsonPath('is_platform_manager', true)->assertJsonMissingPath('manager_device_required');
        $this->app['auth']->forgetGuards();
        $this->withToken($login->json('token'))->getJson('/api/me')->assertOk()->assertJsonPath('is_platform_manager', true);
        $this->withToken($login->json('token'))->getJson('/api/admin/platform/overview')->assertOk()->assertJsonPath('accounts.members', 2);
        $this->assertDatabaseHas('security_logs', ['action' => 'manager.login']);
        // Autre numero avec ce jeton : rien de special (code SMS habituel).
        $this->enterNumber($device, '418 555 7000', 'CA')->assertJsonMissingPath('token')->assertJsonMissingPath('manager_device_required');

        // Le code ne sert qu'une fois ; un deuxieme appareil demande un nouveau code et le premier reste connecte.
        $this->authorize($code)->assertStatus(422);
        $second = $this->authorize($this->issueCode())->assertOk()->json('device_token');
        $this->assertNotSame($device, $second);
        $this->enterNumber($device)->assertJsonPath('is_platform_manager', true);
        $this->enterNumber($second)->assertJsonPath('is_platform_manager', true);
    }

    public function test_wrong_codes_lock_the_authorization_and_codes_expire(): void
    {
        $device = $this->authorize($this->issueCode())->assertOk()->json('device_token');
        $code = $this->issueCode();
        foreach (range(1, 4) as $attempt) {
            $this->authorize('ZZZZ-ZZZZ-ZZZ'.$attempt)->assertStatus(422);
        }
        $this->authorize('ZZZZ-ZZZZ-ZZZ5')->assertStatus(422)->assertJsonPath('errors.activation_code.0', 'Code d\'autorisation invalide. Compte verrouillé 15 min.');
        // Verrouille : meme le bon code est refuse, et le numero n'ouvre plus rien, meme sur l'appareil de confiance.
        $this->authorize($code)->assertStatus(429);
        $this->enterNumber($device)->assertJsonPath('manager_device_required', true)->assertJsonMissingPath('token');
        // Apres 15 minutes, tout revient.
        Carbon::setTestNow(now()->addMinutes(16));
        $this->enterNumber($device)->assertJsonPath('is_platform_manager', true);
        $this->authorize($code)->assertOk();

        // Code expire (24 h).
        $code = $this->issueCode();
        Carbon::setTestNow(now()->addHours(25));
        $this->authorize($code)->assertStatus(422);
    }

    public function test_an_existing_account_with_the_number_is_only_taken_over_after_authorization(): void
    {
        // Compte ordinaire cree avant la mise en place (meme avec le role de Pasteur Resident).
        $old = $this->member(self::PHONE, 'Ancien', 'Compte');
        $old->roles()->attach(Role::where('key', 'super_admin')->value('id'));
        $oldToken = $old->createToken('mobile')->plainTextToken;

        // Le numero seul ne reconnecte pas ce compte (ni code SMS, ni session).
        $this->enterNumber()->assertJsonPath('manager_device_required', true)->assertJsonMissingPath('token');

        $this->authorize($this->issueCode())->assertOk();

        $old->refresh();
        $this->assertTrue($old->isPlatformManager());
        $this->assertFalse($old->isSuperAdmin());
        $this->assertSame(0, $old->roles()->count());
        $this->assertFalse((bool) $old->profile->is_completed);
        // Les anciennes sessions sont fermees.
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/me')->assertUnauthorized();
        $log = SecurityLog::where('action', 'manager.activated')->sole();
        $this->assertTrue($log->details['existing_account']);
        $this->assertSame('super_admin', collect($log->details['roles'])->pluck('key')->sort()->last());
    }

    public function test_an_ordinary_user_cannot_become_the_manager(): void
    {
        $this->actAs($this->marie);
        // Champs inconnus ignores : rien ne permet de s'attribuer le statut depuis le navigateur.
        $this->putJson('/api/profile', ['first_name' => 'Marie', 'last_name' => 'Tremblay', 'gender' => 'femme', 'tribe_id' => $this->juda->id,
            'platform_manager_at' => now()->toIso8601String(), 'phone' => self::PHONE, 'is_platform_manager' => true])->assertOk();
        $this->assertNull($this->marie->fresh()->platform_manager_at);
        $this->assertSame('+14185570000', $this->marie->fresh()->phone);
        $this->getJson('/api/admin/platform/overview')->assertForbidden();

        // Meme marque en base, un compte dont le numero n'est pas le numero reserve n'a aucun droit de gestion.
        $this->marie->forceFill(['platform_manager_at' => now()])->save();
        $this->assertFalse($this->marie->fresh()->isPlatformManager());
        $this->actAs($this->marie);
        $this->getJson('/api/admin/roles')->assertForbidden();

        // La commande d'attribution du role de pasteur refuse le numero reserve.
        $this->assertSame(1, Artisan::call('user:make-admin', ['phone' => self::PHONE]));
    }

    public function test_the_manager_reaches_every_management_module(): void
    {
        $this->actAs($this->manager());
        foreach ([
            '/api/admin/platform/overview', '/api/admin/accounts', '/api/admin/roles', '/api/admin/permissions', '/api/admin/verses',
            '/api/admin/service-schedule', '/api/admin/resolution-resources', '/api/admin/assignments', '/api/admin/announcements',
            '/api/admin/events', '/api/admin/exercises', '/api/admin/organization', '/api/admin/gems', '/api/admin/audit',
        ] as $url) {
            $this->getJson($url)->assertOk();
        }
        // GEMs : toutes les tribus de l'eglise (organisation globale).
        $this->getJson('/api/admin/gems')->assertOk()->assertJsonPath('tribes.0.name', 'Juda');
        // Lecture automatique des notifications : pas une operation a tracer.
        $this->postJson('/api/me/notifications/read-url', ['url' => '/admin/gestion'])->assertOk();
        $this->assertDatabaseMissing('security_logs', ['action' => 'manager.request', 'details->route' => 'POST api/me/notifications/read-url']);
        // Devoirs : la liste, sans aucun chiffre sur les membres.
        Assignment::create(['title' => 'La foi', 'media_type' => 'video', 'media_url' => 'https://youtu.be/dQw4w9WgXcQ', 'youtube_id' => 'dQw4w9WgXcQ', 'published_at' => now()]);
        $list = $this->getJson('/api/admin/assignments')->assertOk()->assertJsonPath('can_track', false)->assertJsonPath('can_manage', true);
        $this->assertArrayNotHasKey('responses_count', $list->json('assignments.0'));
        $this->assertArrayNotHasKey('members_count', $list->json('assignments.0'));
    }

    public function test_confidential_member_data_stays_out_of_reach(): void
    {
        SpiritualHealthForm::create(['user_id' => $this->marie->id, 'period' => '2026-10', 'meditation' => 15, 'priere' => 14, 'jeune' => 10,
            'comment' => 'SECRET-FISS', 'submitted_at' => now(), 'locked_at' => now()]);
        VertumetreForm::create(['user_id' => $this->marie->id, 'period' => '2026-10', 'answers' => ['patience' => 3], 'submitted_at' => now(), 'locked_at' => now()]);
        MonthlyResolution::create(['user_id' => $this->marie->id, 'period' => '2026-10', 'meditation' => 'SECRET-RM']);
        $assignment = Assignment::create(['title' => 'La foi', 'media_type' => 'video', 'media_url' => 'https://youtu.be/dQw4w9WgXcQ', 'youtube_id' => 'dQw4w9WgXcQ', 'published_at' => now()]);
        AssignmentResponse::create(['assignment_id' => $assignment->id, 'user_id' => $this->marie->id, 'summary' => 'SECRET-DS', 'actions' => 'x', 'submitted_at' => now()]);
        Evaluation::create(['user_id' => $this->marie->id, 'type' => 'autre', 'title' => 'SECRET-NOTE', 'score' => 15, 'max_score' => 20, 'evaluated_on' => now(), 'created_by' => $this->pastor->id]);
        SpiritualEntry::create(['member_user_id' => $this->marie->id, 'author_user_id' => $this->pastor->id, 'type' => 'note', 'entry_date' => now(), 'note' => 'SECRET-SUIVI']);
        MemberRequest::create(['user_id' => $this->marie->id, 'category' => 'priere', 'subject' => 'SECRET-DEMANDE', 'message' => 'x', 'status' => 'new']);
        $report = LeaderReport::create(['kind' => 'tribe', 'scope_id' => $this->juda->id, 'scope_name' => 'Juda', 'period' => '2026-09',
            'author_user_id' => $this->pastor->id, 'status' => 'submitted', 'answers' => ['projets' => 'SECRET-RAPPORT'], 'souls' => [], 'submitted_at' => now()]);

        $this->actAs($this->manager());
        $id = $this->marie->id;
        foreach ([
            '/api/admin/members', "/api/admin/members/{$id}", "/api/admin/members/{$id}/fiss", "/api/admin/members/{$id}/fiss-history",
            "/api/admin/members/{$id}/vertumetre", "/api/admin/members/{$id}/resolutions", "/api/admin/members/{$id}/assignments",
            "/api/admin/members/{$id}/spiritual", "/api/admin/members/{$id}/evaluations", "/api/admin/members/{$id}/exercises",
            '/api/admin/vertumetre', '/api/admin/resolutions', "/api/admin/assignments/{$assignment->id}/responses",
            '/api/admin/reports?scope=church', '/api/admin/reports/members?scope=church', '/api/admin/reports/export?kind=members&scope=church', '/api/admin/reports/export?kind=report&scope=church', '/api/admin/stats',
            '/api/admin/requests', '/api/admin/attendance', "/api/admin/leader-reports/{$report->id}", '/api/admin/leaders',
            '/api/admin/new-members',
        ] as $url) {
            $response = $this->getJson($url);
            $this->assertContains($response->status(), [403, 404], "{$url} -> {$response->status()}");
        }
        // Listes qui repondent sans erreur : jamais de contenu confidentiel.
        foreach (['/api/admin/leader-reports', '/api/admin/validations', '/api/admin/gem-reports', '/api/admin/appointments'] as $url) {
            $response = $this->getJson($url);
            foreach (['SECRET', 'Marie'] as $needle) {
                $this->assertStringNotContainsString($needle, $response->getContent(), $url);
            }
        }
        // Exports : refuses cote serveur.
        $this->getJson("/api/admin/leader-reports/{$report->id}/export")->assertForbidden();
        // Le tableau de gestion ne montre que des chiffres globaux.
        $overview = $this->getJson('/api/admin/platform/overview')->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRET', $overview);
        $this->assertStringNotContainsString('Marie', $overview);
        // La liste des comptes : identite et fonctions seulement, telephone masque.
        $accounts = $this->getJson('/api/admin/accounts')->assertOk();
        $this->assertStringNotContainsString('+14185570000', $accounts->getContent());
        $this->assertStringNotContainsString('SECRET', $accounts->getContent());
    }

    public function test_the_manager_has_no_member_space(): void
    {
        $this->actAs($this->manager());
        $this->getJson('/api/me')->assertOk()->assertJsonPath('is_platform_manager', true)->assertJsonPath('profile', null);
        foreach (['GET /api/me/fiss', 'GET /api/me/home', 'GET /api/me/vertumetre', 'GET /api/me/resolutions', 'GET /api/me/exercises', 'POST /api/me/requests'] as $call) {
            [$method, $url] = explode(' ', $call);
            $this->json($method, $url)->assertForbidden();
        }
        $this->putJson('/api/profile', ['first_name' => 'X', 'last_name' => 'Y', 'gender' => 'homme', 'tribe_id' => $this->juda->id])->assertForbidden();
        $this->assertFalse((bool) Profile::where('user_id', User::where('phone', self::PHONE)->value('id'))->value('is_completed'));
    }

    public function test_the_manager_never_appears_in_ordinary_member_lists(): void
    {
        $manager = $this->manager();
        Profile::where('user_id', $manager->id)->update(['first_name' => 'Gestion', 'last_name' => 'Principale', 'tribe_id' => $this->juda->id]);

        $this->actAs($this->pastor);
        $members = $this->getJson('/api/admin/members?per_page=100')->assertOk();
        $this->assertNotContains($manager->id, collect($members->json('members'))->pluck('user_id')->all());
        $this->assertStringNotContainsString('Principale', $members->getContent());
        $this->getJson("/api/admin/members/{$manager->id}")->assertForbidden();
        $this->assertStringNotContainsString('Principale', $this->getJson('/api/admin/accounts')->getContent());
        $this->assertStringNotContainsString('Principale', $this->getJson('/api/admin/accounts?q=Principale')->getContent());
        $this->assertSame(2, $this->getJson('/api/admin/stats')->assertOk()->json('total'));
        $this->assertStringNotContainsString('Principale', $this->getJson('/api/admin/reports/export?kind=members&scope=church')->getContent());

        // Recherche de famille par un membre.
        $this->actAs($this->marie);
        $this->assertStringNotContainsString('Principale', $this->getJson('/api/me/family/search?q=Principale')->getContent());

        // Les comptes ordinaires avec des privileges eleves restent visibles.
        $this->actAs($this->pastor);
        $this->assertContains($this->pastor->id, collect($this->getJson('/api/admin/members?per_page=100')->json('members'))->pluck('user_id')->all());
    }

    public function test_manager_operations_go_to_the_private_security_log_not_the_activity_journal(): void
    {
        $manager = $this->manager();
        $this->actAs($manager);
        $verse = $this->postJson('/api/admin/verses', ['label' => 'Parole', 'text' => 'Le texte donné par le pasteur.', 'reference' => 'Jean 3.16', 'status' => 'published'])
            ->assertCreated()->json('verse');
        $this->assertSame(PlatformManager::DISPLAY_NAME, $verse['author']);
        $this->postJson("/api/admin/members/{$this->marie->id}/roles", ['role_key' => 'communication'])->assertOk();
        $this->putJson('/api/admin/roles/'.Role::where('key', 'communication')->value('id'), [
            'name' => 'Communication', 'scope_kind' => 'none', 'permission_keys' => ['announcements.publish', 'exercises.respond'],
        ])->assertOk();

        $this->assertSame(0, AuditLog::where('user_id', $manager->id)->count());
        foreach (['verse.created', 'role.assigned', 'role.updated', 'manager.request'] as $action) {
            $this->assertDatabaseHas('security_logs', ['user_id' => $manager->id, 'action' => $action]);
        }

        // Le journal ordinaire (pasteur) et l'historique du texte n'en montrent rien.
        $this->actAs($this->pastor);
        $journal = $this->getJson('/api/admin/audit')->assertOk();
        $this->assertSame([], collect($journal->json('logs'))->whereIn('action', ['verse.created', 'role.assigned', 'role.updated'])->values()->all());
        $this->assertSame([], $this->getJson("/api/admin/verses/{$verse['id']}/history")->assertOk()->json('history'));

        // Aucune route de l'application n'expose le journal de securite.
        $this->assertSame([], collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'security'))->values()->all());
        $this->assertSame([], collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains((string) $r->getActionName(), 'SecurityLog'))->values()->all());
    }

    public function test_role_assignment_rules_for_the_manager(): void
    {
        $manager = $this->manager();
        $this->actAs($manager);
        // Tout role ordinaire, avec sa portee.
        $this->postJson("/api/admin/members/{$this->marie->id}/roles", ['role_key' => 'patriarche', 'scope_id' => $this->juda->id])->assertOk();
        $this->postJson("/api/admin/members/{$this->marie->id}/roles", ['role_key' => 'pasteur_assistant'])->assertOk();
        // Le role de Pasteur Resident reste aux mains du Pasteur Resident.
        $this->postJson("/api/admin/members/{$this->marie->id}/roles", ['role_key' => 'super_admin'])->assertForbidden();
        $prAssignment = $this->pastor->roles()->where('key', 'super_admin')->first()->pivot->id;
        $this->deleteJson("/api/admin/members/{$this->pastor->id}/roles/{$prAssignment}")->assertForbidden();
        // Le compte de gestion ne recoit aucun role (ni de lui-meme, ni du pasteur).
        $this->postJson("/api/admin/members/{$manager->id}/roles", ['role_key' => 'super_admin'])->assertNotFound();
        $this->actAs($this->pastor);
        $this->postJson("/api/admin/members/{$manager->id}/roles", ['role_key' => 'pasteur_assistant'])->assertNotFound();
        $this->assertSame(0, $manager->roles()->count());
        $this->assertDatabaseHas('security_logs', ['action' => 'manager.role_assign_refused', 'level' => 'warning']);
        // Le role du Pasteur Resident ne se modifie pas.
        $this->actAs($manager);
        $this->putJson('/api/admin/roles/'.Role::where('key', 'super_admin')->value('id'), ['name' => 'PR', 'scope_kind' => 'none', 'permission_keys' => []])
            ->assertForbidden();
    }

    public function test_other_administrators_do_not_get_manager_privileges(): void
    {
        $pa = $this->member('+14185570009', 'Pasteur', 'Assistant');
        $pa->roles()->attach(Role::where('key', 'pasteur_assistant')->value('id'));
        $admin = $this->member('+14185570011', 'Admin', 'Rôles');
        $admin->roles()->attach(Role::where('key', 'administrateur')->value('id'));
        $this->manager();

        foreach ([$pa, $admin] as $user) {
            $this->assertFalse($user->fresh()->isPlatformManager());
            $this->actAs($user);
            $this->getJson('/api/me')->assertJsonPath('is_platform_manager', false);
            $this->getJson('/api/admin/platform/overview')->assertForbidden();
            $this->getJson('/api/admin/resolution-resources')->assertForbidden();
        }
        // Le Pasteur Resident garde tout, sans etre le compte de gestion.
        $this->actAs($this->pastor);
        $this->getJson('/api/me')->assertJsonPath('is_super_admin', true)->assertJsonPath('is_platform_manager', false);
        $this->getJson("/api/admin/members/{$this->marie->id}/fiss")->assertOk();
    }

    public function test_the_manager_status_can_be_revoked_from_the_server(): void
    {
        $manager = $this->manager();
        $token = $manager->createToken('mobile')->plainTextToken;
        $this->assertSame(0, Artisan::call('app:platform-manager', ['--revoke' => true]));
        $this->assertFalse($manager->fresh()->isPlatformManager());
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->assertDatabaseHas('security_logs', ['action' => 'manager.revoked', 'level' => 'alert']);
        $this->assertSame(0, Artisan::call('app:security-log', ['--limit' => 5]));
    }

    public function test_a_blocked_reserved_account_cannot_sign_in(): void
    {
        $device = $this->authorize($this->issueCode())->assertOk()->json('device_token');
        $code = $this->issueCode();
        User::where('phone', self::PHONE)->first()->forceFill(['blocked_at' => now()])->save();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/request-otp', ['phone' => '93 47 17 03', 'country' => 'TG', 'device_token' => $device])->assertStatus(422);
        $this->authorize($code)->assertForbidden();
    }

    public function test_revoking_the_manager_removes_its_trusted_devices(): void
    {
        $device = $this->authorize($this->issueCode())->assertOk()->json('device_token');
        $this->assertSame(0, Artisan::call('app:platform-manager', ['--revoke' => true]));
        $this->enterNumber($device)->assertJsonPath('manager_device_required', true)->assertJsonMissingPath('token');
    }
}
