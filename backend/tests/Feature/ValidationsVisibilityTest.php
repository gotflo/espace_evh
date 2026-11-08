<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentJoinRequest;
use App\Models\FissEditRequest;
use App\Models\Gem;
use App\Models\MemberDeletionRequest;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\TribeChangeRequest;
use App\Models\User;
use App\Models\VertumetreEditRequest;
use App\Models\VertumetreForm;
use App\Support\DepartmentLeadership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Validations : chaque demande n'est vue (liste, pastille du menu, bandeau de l'accueil) et traitee que par les
 * personnes concernees, avec les roles de production. Une demande de chaque sorte attend en meme temps.
 */
class ValidationsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $u = [];

    private int $n = 0;

    private Tribe $juda;

    private Tribe $levi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-09 10:00'));
        Http::preventStrayRequests();
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        config(['app.url' => 'https://espace.exemple.test', 'mail.test_to' => null, 'services.sms.test_to' => null]);
        // Roles tels qu'en production (7-8 novembre 2026).
        $set = function (string $key, array $perms, string $scope = 'none', int $rank = 30) {
            Role::firstOrCreate(['key' => $key], ['name' => $key, 'scope_kind' => $scope, 'rank' => $rank, 'is_system' => true])
                ->permissions()->sync(Permission::whereIn('key', $perms)->pluck('id'));
        };
        $set('administrateur', ['accounts.view', 'departments.approve', 'departments.manage', 'exercises.respond', 'roles.assign', 'roles.manage'], 'none', 80);
        $set(DepartmentLeadership::ROLE, ['announcements.delete', 'announcements.publish', 'departments.manage', 'events.delete', 'events.manage',
            'events.participants', 'members.edit', 'members.export', 'members.view_scope', 'spiritual.view'], 'department', 30);
        Role::where('key', 'patriarche')->firstOrFail()->permissions()->detach(Permission::where('key', 'tribes.transfer')->value('id'));

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $bethel = Gem::create(['name' => 'Béthel', 'tribe_id' => $this->juda->id, 'is_active' => true]);
        $chorale = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        Department::create(['name' => 'Accueil', 'slug' => 'accueil', 'is_active' => true]);

        $this->u['pr'] = $this->give($this->person('PR', $this->juda), 'super_admin');
        $this->u['pa'] = $this->give($this->person('PA', $this->juda), 'pasteur_assistant');
        $this->u['admin'] = $this->give($this->person('Admin', $this->juda), 'administrateur');
        $this->u['patriJuda'] = $this->give($this->person('PatriJuda', $this->juda), 'patriarche', 'tribe', $this->juda->id);
        $this->u['patriLevi'] = $this->give($this->person('PatriLevi', $this->levi), 'patriarche', 'tribe', $this->levi->id);
        $this->u['apJuda'] = $this->give($this->person('ApJuda', $this->juda), 'assistant_pasteur', 'tribe', $this->juda->id);
        $this->u['apLevi'] = $this->give($this->person('ApLevi', $this->levi), 'assistant_pasteur', 'tribe', $this->levi->id);
        $this->u['garde'] = $this->give($this->person('Garde', $this->juda, $bethel), 'garde', 'gem', $bethel->id);
        $bethel->forceFill(['leader_user_id' => $this->u['garde']->id])->save();
        $this->u['choir'] = $this->lead($this->person('Choir', $this->levi), $chorale);
        $this->u['accueil'] = $this->lead($this->person('Accueil', $this->juda), Department::where('slug', 'accueil')->first());
        $this->u['fidele'] = $this->person('Fidele', $this->juda, $bethel);

        // Une demande de chaque sorte, toutes d'un membre de Juda (GEM Bethel).
        $marie = $this->person('Marie', $this->juda, $bethel);
        $fiss = SpiritualHealthForm::create(['user_id' => $marie->id, 'period' => '2026-11', 'meditation' => 10, 'submitted_at' => now(), 'locked_at' => now()]);
        $this->as($marie);
        $this->postJson("/api/me/fiss/{$fiss->id}/edit-requests", ['reason' => 'Erreur'])->assertCreated();
        $vertu = VertumetreForm::create(['user_id' => $marie->id, 'period' => '2026-11', 'answers' => [], 'submitted_at' => now(), 'locked_at' => now()]);
        $this->postJson("/api/me/vertumetre/{$vertu->id}/edit-requests", ['reason' => 'Erreur'])->assertCreated();
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->levi->id, 'reason' => 'Déménagement'])->assertCreated();
        $this->postJson("/api/me/services/{$chorale->id}/join")->assertOk();
        MemberDeletionRequest::create(['user_id' => $marie->id, 'member_name' => 'Marie T', 'requested_by' => $this->u['patriJuda']->id,
            'reason' => 'Départ', 'status' => 'pending']);
        $newcomer = $this->person('Nouveau', $this->juda);
        $newcomer->forceFill(['approval_status' => User::PENDING, 'approval_requested_at' => now()])->save();
    }

    public function test_each_validation_is_seen_and_counted_only_by_those_concerned(): void
    {
        $kinds = ['services' => 'services', 'fiss' => 'fiss', 'vertumetre' => 'vertumetre', 'tribes' => 'tribes',
            'deletions' => 'deletions', 'memberships' => 'memberships'];
        $seen = [];
        foreach ($this->u as $who => $user) {
            $this->as($user);
            $list = $this->getJson('/api/admin/validations')->assertOk()->json();
            $count = $this->getJson('/api/admin/validations/count')->assertOk()->json();
            // La pastille et le bandeau disent exactement ce que montre l'ecran Validations.
            $this->assertSame($list['count'], $count['count'], "total de {$who}");
            foreach ($kinds as $by => $section) {
                $this->assertSame(count($list[$section] ?? []), $count['by'][$by], "{$by} pour {$who}");
            }
            $seen[$who] = array_filter($count['by']);
        }

        // Qui voit quoi (roles de production).
        $this->assertSame([], $seen['fidele'], 'un fidele ne voit aucune demande');
        $this->assertSame([], $seen['garde'], 'le Garde ne valide rien');
        $this->assertSame([], $seen['accueil'], 'le responsable de l\'Accueil ne voit pas une inscription a la Chorale');
        $this->assertSame([], $seen['patriLevi'], 'le patriarche de Levi ne voit pas les demandes d\'un membre de Juda');
        $this->assertSame(['services' => 1], $seen['choir'], 'le responsable de la Chorale : seulement son inscription');
        $this->assertSame(['fiss' => 1, 'vertumetre' => 1], $seen['patriJuda'], 'le patriarche de Juda : FISS et Vertumetre de son membre');
        $this->assertArrayHasKey('tribes', $seen['apJuda'], 'l\'AP de Juda valide le depart de sa tribu');
        $this->assertArrayHasKey('tribes', $seen['apLevi'], 'l\'AP de Levi valide l\'arrivee dans sa tribu');
        $this->assertArrayNotHasKey('fiss', $seen['apLevi']);
        $this->assertSame(1, $seen['pr']['deletions'] ?? 0, 'la suppression : le Pasteur Resident');
        foreach (['pa', 'admin', 'patriJuda', 'apJuda', 'choir'] as $who) {
            $this->assertArrayNotHasKey('deletions', $seen[$who], "{$who} ne decide pas d'une suppression");
        }
        $this->assertSame(1, $seen['pr']['memberships'] ?? 0);
        $this->assertArrayNotHasKey('memberships', $seen['choir']);
        $this->assertArrayNotHasKey('memberships', $seen['garde']);
    }

    public function test_those_not_concerned_cannot_act_on_a_validation(): void
    {
        $service = DepartmentJoinRequest::sole();
        $fiss = FissEditRequest::sole();
        $vertu = VertumetreEditRequest::sole();
        $tribe = TribeChangeRequest::sole();
        $deletion = MemberDeletionRequest::sole();

        foreach (['accueil', 'garde', 'fidele', 'patriLevi', 'patriJuda'] as $who) {
            $this->as($this->u[$who]);
            $this->postJson("/api/admin/validations/services/{$service->id}", ['decision' => 'approved'])->assertForbidden();
        }
        foreach (['garde', 'choir', 'patriLevi', 'apLevi', 'fidele'] as $who) {
            $this->as($this->u[$who]);
            $this->postJson("/api/admin/validations/fiss/{$fiss->id}", ['decision' => 'approved'])->assertForbidden();
            $this->postJson("/api/admin/validations/vertumetre/{$vertu->id}", ['decision' => 'approved'])->assertForbidden();
        }
        foreach (['garde', 'choir', 'patriJuda', 'fidele', 'admin'] as $who) {
            $this->as($this->u[$who]);
            $this->postJson("/api/admin/validations/tribe/{$tribe->id}", ['decision' => 'approved'])->assertForbidden();
        }
        foreach (['pa', 'admin', 'patriJuda', 'choir', 'garde'] as $who) {
            $this->as($this->u[$who]);
            $this->postJson("/api/admin/validations/deletions/{$deletion->id}", ['decision' => 'approved'])->assertForbidden();
        }
        // Rien n'a bouge.
        $this->assertSame('pending', $service->fresh()->status);
        $this->assertSame('pending', $fiss->fresh()->status);
        $this->assertSame('pending', $vertu->fresh()->status);
        $this->assertSame('pending', $tribe->fresh()->status);
        $this->assertSame('pending', $deletion->fresh()->status);
    }

    private function person(string $first, Tribe $tribe, ?Gem $gem = null): User
    {
        $n = ++$this->n;
        $user = User::create(['phone' => '+1418559'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'gender' => 'femme', 'is_completed' => true,
            'tribe_id' => $tribe->id, 'gem_id' => $gem?->id]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $key, ?string $kind = null, ?int $id = null): User
    {
        $user->roles()->attach(Role::where('key', $key)->value('id'), ['scope_kind' => $kind, 'scope_id' => $id]);

        return $user->fresh();
    }

    private function lead(User $user, Department $department): User
    {
        $this->give($user, DepartmentLeadership::ROLE, 'department', $department->id);
        DepartmentLeadership::appoint($department, $user->id, null);

        return $user->fresh();
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user->fresh());
    }
}
