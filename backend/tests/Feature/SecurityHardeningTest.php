<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentJoinRequest;
use App\Models\Evaluation;
use App\Models\Gem;
use App\Models\MemberDeletionRequest;
use App\Models\MemberRequest;
use App\Models\Milestone;
use App\Models\OtpCode;
use App\Models\Profile;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\SpiritualEntry;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\DepartmentLeadership;
use App\Support\PlatformManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit de securite du 2026-10-16 : chaque faille trouvee a un test qui l'empeche de revenir
 * (elevation de privileges, portees melangees, fuites, fraude aux SMS, abus de notifications...).
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private User $pastor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        Http::preventStrayRequests();
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->pastor = $this->give($this->member('+14185560000', null, 'Pasteur'), 'super_admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, ?Tribe $tribe = null, string $first = 'Membre', string $last = 'T'): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'femme',
            'is_completed' => true, 'tribe_id' => $tribe?->id]);

        return $user;
    }

    private function give(User $user, string $role, ?string $kind = null, ?int $id = null): User
    {
        $user->roles()->attach(Role::where('key', $role)->value('id'), ['scope_kind' => $kind, 'scope_id' => $id]);

        return $user->fresh();
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());
    }

    // ---------------------------------------------------------------- Roles : pas d'elevation de privileges

    public function test_an_administrator_cannot_give_himself_or_others_access_he_does_not_have(): void
    {
        $admin = $this->give($this->member('+14185560001', $this->juda, 'Admin'), 'administrateur');
        $marie = $this->member('+14185560002', $this->levi, 'Marie');
        $this->as($admin);

        // Jamais a soi-meme ; jamais un role confidentiel (suivi spirituel...) qu'il n'a pas.
        $this->postJson("/api/admin/members/{$admin->id}/roles", ['role_key' => 'accompagnateur', 'scope_id' => $marie->id])->assertForbidden();
        $refused = $this->postJson("/api/admin/members/{$marie->id}/roles", ['role_key' => 'patriarche', 'scope_id' => $this->juda->id])->assertForbidden();
        $this->assertStringStartsWith('Ce rôle donne accès à des données confidentielles que vous n', (string) $refused->json('message'));
        $this->assertSame(0, DB::table('role_user')->where('user_id', $marie->id)->count());
        // Un role sans acces confidentiel reste attribuable.
        $this->postJson("/api/admin/members/{$marie->id}/roles", ['role_key' => 'communication'])->assertOk();

        // Roles : l'acces a tous les membres ne s'ajoute que par le Pasteur Resident, et pas de combinaison.
        $fidele = Role::where('key', 'fidele')->first();
        $this->putJson("/api/admin/roles/{$fidele->id}", ['name' => $fidele->name, 'scope_kind' => 'none',
            'permission_keys' => ['exercises.respond', 'members.view_all']])->assertStatus(422);
        $this->assertNotContains('members.view_all', $fidele->permissions()->pluck('key')->all());

        $this->as($this->pastor);
        $this->putJson("/api/admin/roles/{$fidele->id}", ['name' => $fidele->name, 'scope_kind' => 'none',
            'permission_keys' => ['exercises.respond']])->assertOk();
    }

    public function test_the_management_account_assigns_roles_but_never_adds_confidential_permissions(): void
    {
        [$manager] = PlatformManager::activate('+22893471703');
        $this->as($manager);
        $garde = Role::where('key', 'garde')->first();
        $keys = $garde->permissions()->pluck('key')->all();
        $this->putJson("/api/admin/roles/{$garde->id}", ['name' => $garde->name, 'scope_kind' => 'gem',
            'permission_keys' => array_merge($keys, ['fiss.review'])])->assertStatus(422);
        $this->putJson("/api/admin/roles/{$garde->id}", ['name' => $garde->name, 'scope_kind' => 'gem',
            'permission_keys' => array_values(array_diff($keys, ['spiritual.view']))])->assertStatus(422);
    }

    public function test_a_department_leader_cannot_name_leaders_through_organisation(): void
    {
        $choir = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        $leader = $this->member('+14185560003', $this->juda, 'Chef');
        $friend = $this->member('+14185560004', $this->juda, 'Ami');
        $this->as($this->pastor);
        $this->postJson("/api/admin/members/{$leader->id}/roles", ['role_key' => DepartmentLeadership::ROLE, 'scope_id' => $choir->id])->assertOk();
        // Les pasteurs ont donne « gerer les departements » a ce role (comme en ligne) : le rang l'arrete quand meme.
        Role::where('key', DepartmentLeadership::ROLE)->first()->permissions()->attach(DB::table('permissions')->where('key', 'departments.manage')->value('id'));

        $this->as($leader);
        $this->putJson("/api/admin/departments/{$choir->id}/leaders", ['user_ids' => [$leader->id, $friend->id]])->assertForbidden();
        $this->assertSame([$leader->id], $choir->leaders()->pluck('users.id')->map(fn ($id) => (int) $id)->all());
    }

    // ---------------------------------------------------------------- Portees : une permission ne vaut que la ou son role s'applique

    public function test_a_gem_permission_does_not_extend_to_the_members_of_a_department_he_leads(): void
    {
        $bethel = Gem::create(['name' => 'Béthel', 'tribe_id' => $this->juda->id]);
        $choir = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        $garde = $this->give($this->member('+14185560010', $this->juda, 'Garde'), 'garde', 'gem', $bethel->id);
        $this->as($this->pastor);
        $this->postJson("/api/admin/members/{$garde->id}/roles", ['role_key' => DepartmentLeadership::ROLE, 'scope_id' => $choir->id])->assertOk();

        $gemMember = $this->member('+14185560011', $this->juda, 'Béthel');
        Profile::where('user_id', $gemMember->id)->update(['gem_id' => $bethel->id]);
        $singer = $this->member('+14185560012', $this->levi, 'Choriste');
        $choir->members()->attach($singer->profile->id);
        $prayer = MemberRequest::create(['user_id' => $singer->id, 'category' => 'priere', 'message' => 'Priez pour ma famille']);
        $gemRequest = MemberRequest::create(['user_id' => $gemMember->id, 'category' => 'question', 'message' => 'Une question']);

        $this->as($garde);
        $ids = collect($this->getJson('/api/admin/requests')->assertOk()->json('requests'))->pluck('id')->all();
        $this->assertSame([$gemRequest->id], $ids);
        $this->postJson("/api/admin/requests/{$prayer->id}/reply", ['reply' => 'Je prie'])->assertForbidden();
        $this->postJson("/api/admin/requests/{$gemRequest->id}/reply", ['reply' => 'Voici'])->assertOk();
    }

    public function test_own_requests_never_appear_in_the_leaders_list(): void
    {
        $patriarch = $this->give($this->member('+14185560013', $this->juda, 'Patriarche'), 'patriarche', 'tribe', $this->juda->id);
        MemberRequest::create(['user_id' => $patriarch->id, 'category' => 'question', 'message' => 'Ma propre demande']);
        $this->as($patriarch);
        $this->assertSame([], $this->getJson('/api/admin/requests')->assertOk()->json('requests'));
    }

    // ---------------------------------------------------------------- Fuites et integrite

    public function test_the_reason_of_a_deletion_request_stays_between_the_pastor_and_its_author(): void
    {
        $patriarch = $this->give($this->member('+14185560020', $this->juda, 'Patriarche'), 'patriarche', 'tribe', $this->juda->id);
        $marie = $this->member('+14185560021', $this->juda, 'Marie');
        MemberDeletionRequest::create(['user_id' => $marie->id, 'member_name' => 'Marie T', 'requested_by' => $patriarch->id,
            'reason' => 'Motif confidentiel', 'status' => 'pending']);

        $this->as($this->member('+14185560022', $this->levi, 'Curieux'));
        $this->getJson("/api/admin/members/{$marie->id}/deletion")->assertForbidden();
        $this->as($patriarch);
        $this->getJson("/api/admin/members/{$marie->id}/deletion")->assertOk()->assertJsonPath('pending_request.reason', 'Motif confidentiel');
        $this->as($this->pastor);
        $this->getJson("/api/admin/members/{$marie->id}/deletion")->assertOk()->assertJsonPath('pending_request.reason', 'Motif confidentiel');
    }

    public function test_notes_and_entries_are_removed_only_by_their_author_or_a_pastor(): void
    {
        $bethel = Gem::create(['name' => 'Béthel', 'tribe_id' => $this->juda->id]);
        $garde = $this->give($this->member('+14185560030', $this->juda, 'Garde'), 'garde', 'gem', $bethel->id);
        $marie = $this->member('+14185560031', $this->juda, 'Marie');
        Profile::where('user_id', $marie->id)->update(['gem_id' => $bethel->id]);
        $entry = SpiritualEntry::create(['member_user_id' => $marie->id, 'author_user_id' => $this->pastor->id, 'type' => 'besoin',
            'entry_date' => '2026-10-10', 'note' => 'Note du pasteur']);
        $evaluation = Evaluation::create(['user_id' => $marie->id, 'created_by' => $this->pastor->id, 'type' => 'autre',
            'title' => 'Note', 'score' => 15, 'evaluated_on' => '2026-10-10']);
        Milestone::create(['member_user_id' => $marie->id, 'milestone_key' => array_key_first(\App\Support\SpiritualCatalog::MILESTONES),
            'reached_at' => '2026-01-01', 'recorded_by' => $this->pastor->id]);

        $this->as($garde);
        $this->deleteJson("/api/admin/members/{$marie->id}/spiritual/entries/{$entry->id}")->assertForbidden();
        $this->deleteJson("/api/admin/evaluations/{$evaluation->id}")->assertForbidden();
        // Le membre ne retire pas une etape notee par un responsable.
        $this->as($marie);
        $this->postJson('/api/me/spiritual/milestones', ['milestone_key' => array_key_first(\App\Support\SpiritualCatalog::MILESTONES), 'reached' => false])->assertForbidden();
        $this->assertSame(1, Milestone::count());

        $this->as($this->pastor);
        $this->deleteJson("/api/admin/members/{$marie->id}/spiritual/entries/{$entry->id}")->assertOk();
        $this->deleteJson("/api/admin/evaluations/{$evaluation->id}")->assertOk();
    }

    public function test_a_companion_cannot_move_his_member_into_departments_outside_his_scope(): void
    {
        $choir = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        $marie = $this->member('+14185560040', $this->juda, 'Marie');
        $companion = $this->give($this->member('+14185560041', $this->levi, 'Accompagnateur'), 'accompagnateur', 'member', $marie->id);
        $this->as($companion);
        $this->patchJson("/api/admin/members/{$marie->id}/belonging", ['tribe_id' => $this->juda->id, 'department_ids' => [$choir->id]])->assertStatus(422);
        $this->assertSame(0, $choir->members()->count());
    }

    public function test_a_leader_who_leaves_his_department_is_no_longer_its_leader(): void
    {
        $choir = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        $leader = $this->member('+14185560042', $this->juda, 'Chef');
        $this->as($this->pastor);
        $this->postJson("/api/admin/members/{$leader->id}/roles", ['role_key' => DepartmentLeadership::ROLE, 'scope_id' => $choir->id])->assertOk();

        $this->as($leader);
        $this->deleteJson("/api/me/services/{$choir->id}")->assertOk();
        $this->assertSame(0, $choir->leaders()->count());
        $this->assertSame(0, DB::table('role_user')->where('user_id', $leader->id)->where('scope_kind', 'department')->count());
    }

    // ---------------------------------------------------------------- Abus de notifications et surcharge

    public function test_designating_family_members_cannot_flood_someone_or_reach_closed_accounts(): void
    {
        $me = $this->member('+14185560050', $this->juda, 'Moi');
        $victim = $this->member('+14185560051', $this->juda, 'Cible');
        $blocked = $this->member('+14185560052', $this->juda, 'Bloqué');
        $blocked->forceFill(['blocked_at' => now()])->save();
        $this->as($me);
        $this->putJson('/api/me/family/spouse', ['user_id' => $blocked->id])->assertStatus(422);
        foreach (range(1, 4) as $i) {
            $this->putJson('/api/me/family/spouse', ['user_id' => $victim->id])->assertOk();
            $this->putJson('/api/me/family/spouse', ['user_id' => null, 'name' => null])->assertOk();
        }
        $this->assertSame(1, UserNotification::where('user_id', $victim->id)->count());
    }

    public function test_joining_and_cancelling_a_service_in_a_loop_alerts_the_leader_once(): void
    {
        $choir = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        $leader = $this->member('+14185560053', $this->juda, 'Chef');
        $choir->leaders()->attach($leader->id);
        $member = $this->member('+14185560054', $this->juda, 'Boucle');
        $this->as($member);
        foreach (range(1, 3) as $i) {
            $this->postJson("/api/me/services/{$choir->id}/join")->assertOk();
            $this->deleteJson("/api/me/services/{$choir->id}")->assertOk();
        }
        $this->postJson("/api/me/services/{$choir->id}/join")->assertStatus(429);
        $this->assertSame(1, UserNotification::where('user_id', $leader->id)->count());
        $this->assertSame(3, DepartmentJoinRequest::count());
    }

    public function test_a_personal_agenda_has_a_ceiling(): void
    {
        $member = $this->member('+14185560055', $this->juda, 'Agenda');
        foreach (range(1, 200) as $i) {
            DB::table('events')->insert(['title' => 'RDV '.$i, 'starts_at' => now()->addDays($i), 'recurrence' => 'none', 'category' => 'autre',
                'is_personal' => true, 'created_by' => $member->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->as($member);
        $this->postJson('/api/me/events', ['title' => 'Un de trop', 'starts_at' => now()->addDay()->format('Y-m-d H:i')])->assertStatus(422);
    }

    public function test_an_account_keeps_ten_push_devices_at_most(): void
    {
        $member = $this->member('+14185560056', $this->juda, 'Appareils');
        $this->as($member);
        foreach (range(1, 12) as $i) {
            Carbon::setTestNow(now()->addSecond());
            $this->withHeader('User-Agent', 'Navigateur '.$i)->postJson('/api/me/push/subscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'.$i,
                'keys' => ['p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22)]])->assertOk();
        }
        $this->assertSame(10, PushSubscription::where('user_id', $member->id)->count());
    }

    public function test_a_flood_of_browser_errors_is_capped(): void
    {
        config(['monitoring.enabled' => true]);
        foreach (range(1, 25) as $i) {
            $this->postJson('/api/monitor/client-errors', ['kind' => 'error', 'message' => 'Boum', 'stack' => "Error: Boum\n at x (app.js:1:1)"]);
            Cache::forget('illuminate:cache:flexible:created:x'); // aucun effet : seul le plafond par empreinte compte
        }
        $this->assertSame(20, DB::table('monitor_events')->where('service', 'browser')->count());
    }

    public function test_a_tiny_but_huge_image_is_refused_before_being_decoded(): void
    {
        $member = $this->member('+14185560057', $this->juda, 'Photo');
        $this->as($member);
        // 6000 x 6000 = 36 millions de pixels (au-dela du plafond de 30), quelques Ko seulement.
        $image = imagecreatetruecolor(6000, 6000);
        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        $file = UploadedFile::fake()->createWithContent('bombe.png', $png);
        $this->post('/api/profile', ['first_name' => 'Photo', 'last_name' => 'T', 'gender' => 'femme', 'tribe_id' => $this->juda->id, 'photo' => $file],
            ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.photo.0', 'Cette image est trop grande (plus de 30 millions de pixels) : réduisez-la ou choisissez une autre photo.');
    }

    public function test_bad_parameter_types_get_a_clean_error_not_a_crash(): void
    {
        $patriarch = $this->give($this->member('+14185560058', $this->juda, 'Patriarche'), 'patriarche', 'tribe', $this->juda->id);
        $this->as($patriarch);
        $this->getJson('/api/admin/attendance?event[]=x')->assertStatus(422);
    }

    // ---------------------------------------------------------------- Connexion : fraude aux SMS, force brute, jeux d'essai

    public function test_codes_only_go_to_mobile_numbers_of_accepted_countries_under_a_global_ceiling(): void
    {
        config(['services.sms.driver' => 'log', 'app.expose_otp' => true, 'services.sms.max_per_hour' => 3]);
        $this->postJson('/api/auth/request-otp', ['phone' => '+33612345678'])->assertStatus(422);       // France : pays non accepte
        $this->postJson('/api/auth/request-otp', ['phone' => '+19005551234'])->assertStatus(422);       // numero surtaxe
        $this->assertSame(0, OtpCode::count());
        foreach (['+14185561001', '+14185561002', '+14185561003'] as $phone) {
            $this->postJson('/api/auth/request-otp', ['phone' => $phone])->assertOk();
        }
        // Plafond global atteint : plus aucun code, alerte critique.
        $this->postJson('/api/auth/request-otp', ['phone' => '+14185561004'])->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'Trop de demandes de code en ce moment : réessayez dans quelques minutes.');
    }

    public function test_wrong_codes_are_counted_per_number_across_new_codes(): void
    {
        config(['services.sms.driver' => 'log', 'app.expose_otp' => true]);
        $phone = '+14185561010';
        foreach (range(1, 2) as $round) {
            $this->postJson('/api/auth/request-otp', ['phone' => $phone])->assertOk();
            foreach (range(1, 5) as $i) {
                $this->postJson('/api/auth/verify-otp', ['phone' => $phone, 'code' => '000000'])->assertStatus(422);
            }
            Carbon::setTestNow(now()->addMinute());
        }
        // Dix codes faux dans l'heure : meme le bon code d'un nouvel envoi est refuse.
        $code = $this->postJson('/api/auth/request-otp', ['phone' => $phone])->assertOk()->json('dev_code');
        $this->postJson('/api/auth/verify-otp', ['phone' => $phone, 'code' => $code])->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Trop de codes erronés pour ce numéro : réessayez dans une heure.');
        Carbon::setTestNow(now()->addMinutes(61));
        $code = $this->postJson('/api/auth/request-otp', ['phone' => $phone])->assertOk()->json('dev_code');
        $this->postJson('/api/auth/verify-otp', ['phone' => $phone, 'code' => $code])->assertOk();
    }

    public function test_the_test_code_is_never_shown_on_a_real_domain(): void
    {
        config(['services.sms.driver' => 'log', 'app.expose_otp' => true]);
        $this->postJson('/api/auth/request-otp', ['phone' => '+14185561021'])->assertOk()->assertJsonStructure(['dev_code']);
        $this->postJson('https://espace.vasesdhonneurchicoutimi.org/api/auth/request-otp', ['phone' => '+14185561020'])
            ->assertOk()->assertJsonMissingPath('dev_code');
    }

    // ---------------------------------------------------------------- AP de plusieurs tribus

    public function test_an_assistant_pastor_of_several_tribes_sees_and_acts_only_on_his_tribes(): void
    {
        $dan = Tribe::create(['name' => 'Dan', 'slug' => 'dan']);
        $ap = $this->member('+14185562000', null, 'AP');
        $this->give($ap, 'assistant_pasteur', 'tribe', $this->juda->id);
        $ap = $this->give($ap, 'assistant_pasteur', 'tribe', $this->levi->id);
        $inJuda = $this->member('+14185562001', $this->juda, 'Juda');
        $inLevi = $this->member('+14185562002', $this->levi, 'Levi');
        $inDan = $this->member('+14185562003', $dan, 'Dan');
        $gemDan = Gem::create(['name' => 'GEM Dan', 'tribe_id' => $dan->id]);
        Gem::create(['name' => 'GEM Juda', 'tribe_id' => $this->juda->id]);
        foreach ([$inJuda, $inLevi, $inDan] as $m) {
            MemberRequest::create(['user_id' => $m->id, 'category' => 'priere', 'message' => 'Priez pour moi']);
            \App\Models\SpiritualHealthForm::create(['user_id' => $m->id, 'period' => '2026-10', 'meditation' => 10, 'submitted_at' => now(), 'locked_at' => now()]);
        }
        $this->as($ap);

        // Listes : ses deux tribus, jamais Dan.
        $listed = collect($this->getJson('/api/admin/members')->assertOk()->json('members'))->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing([$inJuda->id, $inLevi->id], array_values(array_intersect($listed, [$inJuda->id, $inLevi->id, $inDan->id])));
        $requests = collect($this->getJson('/api/admin/requests')->assertOk()->json('requests'))->pluck('user_id')->all();
        $this->assertNotContains($inDan->id, $requests);
        $this->assertCount(2, $requests);
        $roster = collect($this->getJson('/api/admin/attendance')->assertOk()->json('members'))->pluck('user_id')->all();
        $this->assertNotContains($inDan->id, $roster);
        $gems = collect($this->getJson('/api/admin/gems')->assertOk()->json('gems'))->pluck('name')->all();
        $this->assertNotContains('GEM Dan', $gems);

        // Rapports : « Mes tribus » = Juda + Lévi ; Dan refusee.
        $options = collect($this->getJson('/api/admin/reports/options')->assertOk()->json('tribes'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Juda', 'Lévi'], $options);
        $rows = collect($this->getJson('/api/admin/reports/members?scope=mine&filter=all')->assertOk()->json('members'))->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing([$inJuda->id, $inLevi->id], $rows);
        $this->getJson('/api/admin/reports?scope=tribe:'.$dan->id)->assertForbidden();

        // Fiches, FISS, actions : Juda et Lévi oui, Dan non.
        $this->getJson("/api/admin/members/{$inLevi->id}/fiss")->assertOk();
        $this->getJson("/api/admin/members/{$inDan->id}")->assertForbidden();
        $this->getJson("/api/admin/members/{$inDan->id}/fiss")->assertForbidden();
        $this->postJson("/api/admin/members/{$inDan->id}/evaluations", ['type' => 'autre', 'title' => 'Note', 'score' => 12, 'evaluated_on' => '2026-10-15'])->assertForbidden();
        $this->postJson("/api/admin/members/{$inJuda->id}/evaluations", ['type' => 'autre', 'title' => 'Note', 'score' => 12, 'evaluated_on' => '2026-10-15'])->assertCreated();
        $this->putJson("/api/admin/gems/{$gemDan->id}", ['name' => 'Pris', 'tribe_id' => $dan->id])->assertForbidden();
        $danRequest = MemberRequest::where('user_id', $inDan->id)->first();
        $this->postJson("/api/admin/requests/{$danRequest->id}/reply", ['reply' => 'Non'])->assertForbidden();
    }

    // ---------------------------------------------------------------- Sessions

    public function test_a_session_still_in_use_is_extended_and_expired_ones_are_cleaned(): void
    {
        $member = $this->member('+14185561030', $this->juda, 'Session');
        $token = $member->createToken('app');
        DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['created_at' => now()->subDays(40)]);
        $this->withToken($token->plainTextToken)->getJson('/api/me')->assertOk();
        $this->assertTrue(Carbon::parse(DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->value('created_at'))->isToday());

        $old = $member->createToken('ancien');
        DB::table('personal_access_tokens')->where('id', $old->accessToken->id)->update(['created_at' => now()->subDays(70)]);
        OtpCode::create(['phone' => '+14185561031', 'code_hash' => 'x', 'expires_at' => now()->subDays(3)])->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->artisan('app:housekeeping')->assertSuccessful();
        $this->assertNull(DB::table('personal_access_tokens')->where('id', $old->accessToken->id)->first());
        $this->assertSame(0, OtpCode::count());
    }
}
