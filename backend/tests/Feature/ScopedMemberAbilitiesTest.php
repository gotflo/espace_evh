<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Evaluation;
use App\Models\FamilyLink;
use App\Models\Gem;
use App\Models\Milestone;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualEntry;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit du 2026-11-07 : sur la fiche d'un membre, l'ecran ne montre que ce que le serveur acceptera pour CE membre
 * (droits recus par un role valables seulement dans sa portee), et les changements faits par un responsable
 * previennent les personnes concernees.
 */
class ScopedMemberAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private Department $choir;

    private Gem $gem;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Comme en production : le role « Responsable de departement » donne members.edit (sur son departement).
        Role::where('key', 'responsable_departement')->first()->permissions()
            ->syncWithoutDetaching([DB::table('permissions')->where('key', 'members.edit')->value('id')]);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->choir = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        $this->gem = Gem::create(['name' => 'GEM A', 'tribe_id' => $this->juda->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, ?Tribe $tribe, string $first, array $extra = []): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now()]);
        Profile::create($extra + ['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'is_completed' => true, 'tribe_id' => $tribe?->id]);

        return $user;
    }

    private function give(User $user, string $roleKey, ?string $kind = null, ?int $scopeId = null): User
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $kind, 'scope_id' => $scopeId]);

        return $user->fresh();
    }

    public function test_garde_who_leads_a_department_sees_only_what_he_can_do_on_each_member(): void
    {
        $garde = $this->give($this->give($this->member('+14185557001', $this->juda, 'Garde', ['gem_id' => $this->gem->id]), 'garde', 'gem', $this->gem->id),
            'responsable_departement', 'department', $this->choir->id);
        $inGem = $this->member('+14185557002', $this->juda, 'DansLeGem', ['gem_id' => $this->gem->id]);
        $singer = $this->member('+14185557003', $this->levi, 'Choriste');
        $singer->profile->departments()->attach($this->choir->id);

        Sanctum::actingAs($garde);
        $this->getJson("/api/admin/members/{$inGem->id}")->assertOk()
            ->assertJsonPath('abilities.evaluations_view', true)->assertJsonPath('abilities.evaluations_manage', true)
            ->assertJsonPath('abilities.spiritual_record', true)->assertJsonPath('abilities.exercises_view', true);
        // Membre de son departement, hors de son GEM : ni notes, ni ecriture du suivi, ni exercices (avant : panneaux refuses).
        $this->getJson("/api/admin/members/{$singer->id}")->assertOk()
            ->assertJsonPath('abilities.evaluations_view', false)->assertJsonPath('abilities.evaluations_manage', false)
            ->assertJsonPath('abilities.spiritual_record', false)->assertJsonPath('abilities.exercises_view', false);
        $this->getJson("/api/admin/members/{$singer->id}/evaluations")->assertForbidden();
        $this->postJson("/api/admin/members/{$singer->id}/spiritual/entries", ['type' => 'note', 'note' => 'x'])->assertForbidden();
        // Sa propre fiche : aucune suppression ni role a gerer.
        $this->getJson("/api/admin/members/{$garde->id}")->assertOk()
            ->assertJsonPath('abilities.deletion', false)->assertJsonPath('abilities.assign_roles', false);
        $this->getJson("/api/admin/members/{$garde->id}/deletion")->assertForbidden();
    }

    public function test_notes_and_journal_say_which_items_can_be_deleted(): void
    {
        $garde = $this->give($this->member('+14185557010', $this->juda, 'Garde', ['gem_id' => $this->gem->id]), 'garde', 'gem', $this->gem->id);
        $other = $this->give($this->member('+14185557011', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $paul = $this->member('+14185557012', $this->juda, 'Paul', ['gem_id' => $this->gem->id]);
        $mine = Evaluation::create(['user_id' => $paul->id, 'type' => 'autre', 'score' => 15, 'max_score' => 20, 'evaluated_on' => now(), 'created_by' => $garde->id]);
        $theirs = Evaluation::create(['user_id' => $paul->id, 'type' => 'autre', 'score' => 12, 'max_score' => 20, 'evaluated_on' => now(), 'created_by' => $other->id]);
        $myEntry = SpiritualEntry::create(['member_user_id' => $paul->id, 'author_user_id' => $garde->id, 'type' => 'note', 'entry_date' => now(), 'note' => 'a']);
        $theirEntry = SpiritualEntry::create(['member_user_id' => $paul->id, 'author_user_id' => $other->id, 'type' => 'note', 'entry_date' => now(), 'note' => 'b']);
        Milestone::create(['member_user_id' => $paul->id, 'milestone_key' => 'bapteme_eau', 'reached_at' => now(), 'recorded_by' => $other->id]);

        Sanctum::actingAs($garde);
        $notes = collect($this->getJson("/api/admin/members/{$paul->id}/evaluations")->assertOk()->json('evaluations'))->keyBy('id');
        $this->assertTrue($notes[$mine->id]['can_delete']);
        $this->assertFalse($notes[$theirs->id]['can_delete']);
        $this->deleteJson("/api/admin/evaluations/{$theirs->id}")->assertForbidden(); // coherent avec le drapeau

        $spiritual = $this->getJson("/api/admin/members/{$paul->id}/spiritual")->assertOk();
        $entries = collect($spiritual->json('entries'))->keyBy('id');
        $this->assertTrue($entries[$myEntry->id]['can_delete']);
        $this->assertFalse($entries[$theirEntry->id]['can_delete']);
        $this->assertFalse(collect($spiritual->json('milestones'))->firstWhere('key', 'bapteme_eau')['can_unset']);
        $this->assertTrue(collect($spiritual->json('milestones'))->firstWhere('key', 'bapteme_esprit')['can_unset']);
    }

    public function test_family_links_open_only_when_the_relative_is_in_scope(): void
    {
        $patriarch = $this->give($this->member('+14185557020', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $wife = $this->member('+14185557021', $this->juda, 'Épouse');
        $husband = $this->member('+14185557022', $this->levi, 'Époux');
        $child = $this->member('+14185557023', $this->juda, 'Enfant');
        FamilyLink::create(['user_id' => $wife->id, 'relation' => 'spouse', 'relative_user_id' => $husband->id, 'status' => 'confirmed', 'confirmed_at' => now()]);
        FamilyLink::create(['user_id' => $wife->id, 'relation' => 'child', 'relative_user_id' => $child->id, 'status' => 'confirmed', 'confirmed_at' => now()]);

        Sanctum::actingAs($patriarch);
        $family = $this->getJson("/api/admin/members/{$wife->id}")->assertOk()->json('family');
        $this->assertFalse($family['spouse']['can_open'], 'conjoint dans une autre tribu : pas de lien vers une page refusee');
        $this->assertTrue($family['children'][0]['can_open']);
        $this->assertArrayNotHasKey('incoming', $family);
        $this->assertArrayNotHasKey('suggestions', $family);
    }

    public function test_changes_made_by_a_leader_notify_the_member_the_garde_and_the_new_tribe(): void
    {
        $pastor = $this->give($this->member('+14185557030', null, 'Pasteur'), 'pasteur_assistant');
        $garde = $this->give($this->member('+14185557031', $this->juda, 'Garde', ['gem_id' => $this->gem->id]), 'garde', 'gem', $this->gem->id);
        $this->gem->forceFill(['leader_user_id' => $garde->id])->save();
        $leviPatriarch = $this->give($this->member('+14185557032', $this->levi, 'PLevi'), 'patriarche', 'tribe', $this->levi->id);
        $paul = $this->member('+14185557033', $this->juda, 'Paul');
        $anne = $this->member('+14185557034', $this->juda, 'Anne');
        $patriarch = $this->give($this->member('+14185557035', $this->juda, 'PJuda'), 'patriarche', 'tribe', $this->juda->id);

        // GEM par la fiche : le membre et le Garde.
        Sanctum::actingAs($patriarch);
        $this->patchJson("/api/admin/members/{$paul->id}/belonging", ['tribe_id' => $this->juda->id, 'gem_id' => $this->gem->id])->assertOk();
        $this->assertSame(1, UserNotification::where('user_id', $paul->id)->where('title', 'Vous faites partie du GEM A')->count());
        $this->assertSame(1, UserNotification::where('user_id', $garde->id)->where('title', 'Nouveau membre dans votre GEM : Paul T')->count());
        $this->assertSame(0, UserNotification::where('user_id', $patriarch->id)->count(), 'jamais pour son propre geste');
        // GEM par la page GEMs : pareil.
        $this->putJson("/api/admin/gems/{$this->gem->id}/members", ['user_ids' => [$paul->id, $anne->id]])->assertOk();
        $this->assertSame(1, UserNotification::where('user_id', $garde->id)->where('title', 'Nouveau membre dans votre GEM : Anne T')->count());
        $this->assertSame(1, UserNotification::where('user_id', $paul->id)->where('title', 'Vous faites partie du GEM A')->count(), 'Paul y etait deja');

        // Tribe et departement par un pasteur : le membre, et la tribu qui l'accueille.
        Sanctum::actingAs($pastor);
        $this->patchJson("/api/admin/members/{$anne->id}/belonging", ['tribe_id' => $this->levi->id, 'department_ids' => [$this->choir->id]])->assertOk();
        $this->assertSame(1, UserNotification::where('user_id', $anne->id)->where('title', 'Bienvenue dans la tribu Lévi !')->count());
        $this->assertSame(1, UserNotification::where('user_id', $leviPatriarch->id)->where('title', 'Nouveau membre dans la tribu : Anne T')->count());
        $this->assertSame(1, UserNotification::where('user_id', $anne->id)->where('title', 'Bienvenue au service Chorale')->count());
    }

    public function test_a_gem_report_always_reaches_someone_and_leaving_a_service_links_nowhere_refused(): void
    {
        // Patriarche qui est aussi le Garde de son GEM, sans AP : le rapport va aux pasteurs qui lisent les rapports.
        $pastor = $this->give($this->member('+14185557040', null, 'Pasteur'), 'pasteur_assistant');
        $patriarch = $this->give($this->give($this->member('+14185557041', $this->juda, 'PJuda', ['gem_id' => $this->gem->id]), 'patriarche', 'tribe', $this->juda->id),
            'garde', 'gem', $this->gem->id);
        $this->gem->forceFill(['leader_user_id' => $patriarch->id])->save();
        Sanctum::actingAs($patriarch);
        $week = Carbon::parse('2026-11-07')->startOfWeek()->subWeek()->toDateString();
        $this->putJson("/api/admin/gem-reports/{$this->gem->id}", ['week_start' => $week, 'meeting_held' => false, 'attendance' => [['user_id' => $patriarch->id, 'culte' => true]]])
            ->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $pastor->id)->where('type', 'gem_report')->count());

        // Quitter un service : la notification aux responsables n'a pas de lien vers une fiche desormais hors portee.
        $leader = $this->give($this->member('+14185557042', $this->levi, 'Chef'), 'responsable_departement', 'department', $this->choir->id);
        $this->choir->leaders()->attach($leader->id);
        $paul = $this->member('+14185557043', $this->juda, 'Paul');
        $paul->profile->departments()->attach($this->choir->id);
        Sanctum::actingAs($paul);
        $this->deleteJson("/api/me/services/{$this->choir->id}")->assertOk();
        $this->assertNull(UserNotification::where('user_id', $leader->id)->where('title', 'like', '%a quitté le service%')->value('url'));
    }
}
