<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Exercise;
use App\Models\ExerciseResponse;
use App\Models\Gem;
use App\Models\MeditationSheet;
use App\Models\OtpCode;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualHealthForm;
use App\Models\SpiritualProfile;
use App\Models\Tribe;
use App\Models\User;
use App\Services\OtpService;
use App\Services\ReportService;
use App\Services\VertumetreService;
use App\Support\Recipients;
use App\Support\VertumetreCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Constats de l'audit du 31 octobre 2026 : cumul de roles, portee des reponses, connexion par SMS, journal. */
class SecurityAudit20261031Test extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private Gem $bethel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->bethel = Gem::create(['name' => 'GEM Béthel', 'tribe_id' => $this->juda->id, 'is_active' => true]);
    }

    private int $n = 0;

    private function member(?Tribe $tribe = null, ?Gem $gem = null): User
    {
        $user = User::create(['phone' => '+14185558'.str_pad((string) ++$this->n, 3, '0', STR_PAD_LEFT), 'last_login_at' => now()]);
        Profile::create(['user_id' => $user->id, 'first_name' => 'M'.$this->n, 'last_name' => 'T', 'gender' => 'femme', 'is_completed' => true,
            'tribe_id' => ($tribe ?? $this->juda)->id, 'gem_id' => $gem?->id]);

        return $user;
    }

    private function give(User $user, string $key, ?string $kind = null, ?int $id = null): void
    {
        $user->roles()->attach(Role::where('key', $key)->value('id'), ['scope_kind' => $kind, 'scope_id' => $id]);
    }

    /** Administrateur (voit tous les membres, aucun droit spirituel) ET Garde d'un GEM. */
    private function adminAndGarde(): User
    {
        $u = $this->member();
        $this->give($u, 'administrateur');
        $this->give($u, 'garde', 'gem', $this->bethel->id);

        return User::find($u->id);
    }

    public function test_a_permission_of_a_gem_does_not_extend_to_the_whole_church_with_view_all(): void
    {
        $inGem = $this->member($this->juda, $this->bethel);
        $outside = $this->member($this->levi);
        $viewer = $this->adminAndGarde();

        $this->assertTrue($viewer->canViewMember($inGem, 'spiritual.view'));
        $this->assertFalse($viewer->canViewMember($outside, 'spiritual.view'));
        $this->assertTrue($viewer->canViewMember($outside), 'il voit toujours la fiche de tous les membres');
        $this->assertFalse($viewer->canViewFissOf($outside));
        $this->assertFalse($viewer->canViewFissOf($inGem), 'un Garde ne lit pas les FISS');

        Sanctum::actingAs($viewer);
        $this->getJson("/api/admin/members/{$outside->id}/fiss")->assertForbidden();
        $this->getJson("/api/admin/members/{$outside->id}/spiritual")->assertForbidden();
    }

    public function test_a_patriarch_with_view_all_reports_on_his_tribe_only(): void
    {
        $viewer = $this->member();
        $this->give($viewer, 'administrateur');
        $this->give($viewer, 'patriarche', 'tribe', $this->juda->id);
        $viewer = User::find($viewer->id);

        $options = ReportService::options($viewer);
        $this->assertFalse($options['church']);
        $this->assertSame([$this->juda->id], array_column($options['tribes'], 'id'));

        Sanctum::actingAs($viewer);
        $this->getJson('/api/admin/reports/options')->assertOk()->assertJsonPath('church', false);
    }

    public function test_a_pastor_keeps_the_whole_church(): void
    {
        $outside = $this->member($this->levi);
        $pa = $this->member();
        $this->give($pa, 'pasteur_assistant');
        $pa = User::find($pa->id);

        $this->assertTrue($pa->canViewMember($outside, 'spiritual.view'));
        $this->assertTrue($pa->canViewFissOf($outside));
        $this->assertTrue($pa->canViewMeditationsOf($outside));
    }

    public function test_a_patriarch_with_view_all_reads_meditations_of_his_tribe_only(): void
    {
        $inTribe = $this->member($this->juda);
        $outside = $this->member($this->levi);
        MeditationSheet::create(['user_id' => $outside->id, 'verse' => 'V', 'observation' => 'O', 'rhema' => 'R', 'action' => 'A', 'prayer' => 'P', 'submitted_at' => now()]);
        $u = $this->member();
        $this->give($u, 'administrateur');
        $this->give($u, 'patriarche', 'tribe', $this->juda->id);
        $u = User::find($u->id);

        $this->assertTrue($u->canViewMeditationsOf($inTribe));
        $this->assertFalse($u->canViewMeditationsOf($outside));
        $this->assertTrue($u->canViewFissOf($inTribe));
        $this->assertFalse($u->canViewFissOf($outside));
    }

    public function test_requests_are_limited_to_the_scope_that_gives_the_right(): void
    {
        $inGem = $this->member($this->juda, $this->bethel);
        $outside = $this->member($this->levi);
        DB::table('member_requests')->insert([
            ['user_id' => $inGem->id, 'category' => 'question', 'subject' => 'dedans', 'message' => 'm', 'status' => 'nouvelle', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $outside->id, 'category' => 'priere', 'subject' => 'dehors', 'message' => 'm', 'status' => 'nouvelle', 'created_at' => now(), 'updated_at' => now()],
        ]);
        Sanctum::actingAs($this->adminAndGarde());

        $body = $this->getJson('/api/admin/requests')->assertOk()->getContent();
        $this->assertStringContainsString('dedans', $body);
        $this->assertStringNotContainsString('dehors', $body);
    }

    public function test_the_spiritual_profile_requires_the_right_to_read_it(): void
    {
        $outside = $this->member($this->levi);
        SpiritualProfile::create(['user_id' => $outside->id, 'last_prayer_subject' => 'Sujet confidentiel']);
        $admin = $this->member();
        $this->give($admin, 'administrateur');
        Sanctum::actingAs(User::find($admin->id));

        $response = $this->getJson("/api/admin/members/{$outside->id}")->assertOk();
        $this->assertNull($response->json('spiritual_profile'));
        $this->assertStringNotContainsString('Sujet confidentiel', $response->getContent());

        $pa = $this->member();
        $this->give($pa, 'pasteur_assistant');
        Sanctum::actingAs(User::find($pa->id));
        $this->assertSame('Sujet confidentiel', $this->getJson("/api/admin/members/{$outside->id}")->json('spiritual_profile.last_prayer_subject'));
    }

    public function test_exercise_answers_stay_in_the_scope_of_the_right(): void
    {
        $inGem = $this->member($this->juda, $this->bethel);
        $outside = $this->member($this->levi);
        $author = $this->member();
        $this->give($author, 'pasteur_assistant');
        $exercise = Exercise::create(['title' => 'Exo', 'type' => 'reflexion', 'content' => 'c', 'requires_response' => true, 'is_active' => true, 'created_by' => $author->id]);
        $exercise->syncScopes([['type' => 'church', 'id' => null]]);
        ExerciseResponse::create(['exercise_id' => $exercise->id, 'user_id' => $inGem->id, 'response' => 'reponse-dedans', 'completed_at' => now()]);
        ExerciseResponse::create(['exercise_id' => $exercise->id, 'user_id' => $outside->id, 'response' => 'reponse-dehors', 'completed_at' => now()]);

        // Diffusion a toute l'eglise + Garde : il publie partout, mais ne lit que les reponses de son GEM.
        $u = $this->member();
        $this->give($u, 'communication');
        $this->give($u, 'garde', 'gem', $this->bethel->id);
        Sanctum::actingAs(User::find($u->id));

        $body = $this->getJson("/api/admin/exercises/{$exercise->id}/responses")->assertOk()->getContent();
        $this->assertStringContainsString('reponse-dedans', $body);
        $this->assertStringNotContainsString('reponse-dehors', $body);
        $this->getJson("/api/admin/members/{$outside->id}/exercises")->assertForbidden();

        Sanctum::actingAs(User::find($author->id));
        $this->assertStringContainsString('reponse-dehors', $this->getJson("/api/admin/exercises/{$exercise->id}/responses")->getContent());
    }

    public function test_recipients_are_the_same_without_loading_every_leader(): void
    {
        $member = $this->member($this->juda, $this->bethel);
        $garde = $this->member();
        $this->give($garde, 'garde', 'gem', $this->bethel->id);
        $patriarch = $this->member();
        $this->give($patriarch, 'patriarche', 'tribe', $this->juda->id);
        $otherPatriarch = $this->member($this->levi);
        $this->give($otherPatriarch, 'patriarche', 'tribe', $this->levi->id);
        $pa = $this->member();
        $this->give($pa, 'pasteur_assistant');

        $followers = Recipients::followersOf(User::find($member->id), 'requests.handle');
        sort($followers);
        $this->assertSame([$garde->id, $patriarch->id, $pa->id], $followers);
        $this->assertSame([$garde->id, $patriarch->id], array_values(array_map('intval', Recipients::leadersOf(User::find($member->id)))));

        // Meme resultat dans un traitement par lots (tous les responsables deja charges).
        $inBatch = Recipients::remember(fn () => Recipients::followersOf(User::find($member->id), 'requests.handle'));
        sort($inBatch);
        $this->assertSame($followers, $inBatch);
    }

    // ---------------------------------------------------------------- Connexion

    private function requestCode(string $phone, string $ip = '203.0.113.5')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/request-otp', ['phone' => $phone]);
    }

    public function test_a_number_is_limited_per_hour_and_a_locked_number_receives_no_more_sms(): void
    {
        config(['services.sms.driver' => 'log', 'app.expose_otp' => true]);
        $phone = '+14185559001';
        for ($i = 0; $i < OtpService::MAX_CODES_PER_NUMBER_HOUR; $i++) {
            OtpCode::create(['phone' => $phone, 'code_hash' => 'x', 'expires_at' => now()->addMinutes(10)])
                ->forceFill(['created_at' => now()->subMinutes(5)])->save();
        }
        $this->requestCode($phone)->assertStatus(422);

        $other = '+14185559002';
        for ($i = 0; $i < OtpService::MAX_FAILURES_PER_HOUR; $i++) {
            OtpCode::where('phone', $other)->delete();
            OtpCode::create(['phone' => $other, 'code_hash' => bcrypt('111111'), 'expires_at' => now()->addMinutes(10)]);
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/auth/verify-otp', ['phone' => $other, 'code' => '000000'])->assertStatus(422);
        }
        OtpCode::where('phone', $other)->update(['created_at' => now()->subMinutes(10)]);
        $before = OtpCode::where('phone', $other)->count();
        $this->requestCode($other, '203.0.113.9')->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'Trop de codes erronés pour ce numéro : réessayez dans une heure.');
        $this->assertSame($before, OtpCode::where('phone', $other)->count(), 'aucun SMS de plus');
    }

    public function test_false_codes_from_one_address_do_not_lock_the_member_elsewhere(): void
    {
        config(['services.sms.driver' => 'log']);
        $phone = '+14185559003';
        $otp = app(OtpService::class);
        request()->server->set('REMOTE_ADDR', '198.51.100.7');
        for ($i = 0; $i < OtpService::MAX_FAILURES_PER_HOUR; $i++) {
            OtpCode::where('phone', $phone)->delete();
            OtpCode::create(['phone' => $phone, 'code_hash' => bcrypt('111111'), 'expires_at' => now()->addMinutes(10)]);
            $otp->verify($phone, '000000');
        }
        $this->assertTrue($otp->isLocked($phone));

        request()->server->set('REMOTE_ADDR', '198.51.100.8');
        $this->assertFalse($otp->isLocked($phone), 'une autre adresse peut toujours se connecter');
    }

    public function test_part_of_the_sms_ceiling_is_kept_for_members(): void
    {
        config(['services.sms.driver' => 'log', 'services.sms.max_per_hour' => 10, 'services.sms.max_per_day' => 300]);
        for ($i = 0; $i < 8; $i++) {
            OtpCode::create(['phone' => '+1418555'.(9100 + $i), 'code_hash' => 'x', 'expires_at' => now()->addMinutes(10)]);
        }
        $otp = app(OtpService::class);
        $member = $this->member();

        $this->assertNotNull($otp->smsRefusal('+14185559900'), 'un numero inconnu est refuse a 80 %');
        $this->assertNull($otp->smsRefusal($member->phone), 'un membre peut encore recevoir son code');
    }

    // ---------------------------------------------------------------- Journal

    public function test_the_journal_never_keeps_the_inner_life_in_clear(): void
    {
        $member = $this->member();
        Sanctum::actingAs(User::find($member->id));
        $secret = 'Texte confidentiel de vie intérieure';
        $payload = array_merge(['meditation' => 12, 'priere' => 12, 'jeune' => 12, 'sanctification_corps' => 'bien', 'sanctification_ame' => 'bien', 'sanctification_esprit' => 'bien',
            'situation_financiere' => 12, 'situation_familiale' => 12], array_fill_keys(SpiritualHealthForm::INNER_LIFE, $secret));
        $this->postJson('/api/me/fiss', $payload)->assertSuccessful();

        $logged = AuditLog::where('action', 'fiss.created')->firstOrFail();
        $this->assertStringNotContainsString($secret, json_encode($logged->getAttributes(), JSON_UNESCAPED_UNICODE));
        $this->assertSame($secret, SpiritualHealthForm::where('user_id', $member->id)->value('addiction'), 'la fiche elle-meme est intacte');
    }

    public function test_the_journal_never_keeps_free_answers_of_the_vertumetre_in_clear(): void
    {
        $clean = ['soutien_priere' => 'Sujet confidentiel', 'priere_frequence' => '1x'];
        $journal = VertumetreCatalog::forJournal($clean);
        $this->assertSame('1x', $journal['priere_frequence']);
        $this->assertStringStartsWith('texte #', $journal['soutien_priere']);
        $this->assertNotSame($journal['soutien_priere'], VertumetreCatalog::forJournal(['soutien_priere' => 'Autre texte'])['soutien_priere']);
        $this->assertTrue(method_exists(VertumetreService::class, 'update'));
    }

    // ---------------------------------------------------------------- Rendez-vous

    public function test_a_pastor_does_not_see_the_list_of_a_colleague(): void
    {
        $pr = $this->member();
        $this->give($pr, 'super_admin');
        $colleague = $this->member();
        $role = Role::create(['key' => 'hote', 'name' => 'Hôte', 'scope_kind' => 'none', 'rank' => 90, 'is_system' => false]);
        $role->permissions()->attach(DB::table('permissions')->where('key', 'appointments.host')->pluck('id'));
        $colleague->roles()->attach($role->id);

        Sanctum::actingAs(User::find($colleague->id));
        $data = $this->getJson('/api/admin/appointments')->assertOk()->json();
        $this->assertSame([$colleague->id], collect($data['hosts'])->pluck('id')->all());
        $this->getJson('/api/admin/appointments?host='.$pr->id)->assertNotFound();

        Sanctum::actingAs(User::find($pr->id));
        $this->assertCount(2, $this->getJson('/api/admin/appointments')->assertOk()->json('hosts'));
    }

    public function test_the_directory_search_starts_at_three_letters(): void
    {
        $this->member();
        $me = $this->member();
        Sanctum::actingAs(User::find($me->id));
        $this->assertCount(0, $this->getJson('/api/me/family/search?q=M1')->assertOk()->json('results') ?? []);
    }
}
