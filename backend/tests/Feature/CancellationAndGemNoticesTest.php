<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Gem;
use App\Models\OutboundMessage;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\TribeChangeRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\VertumetreForm;
use App\Support\DepartmentLeadership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Demandes du 7 novembre 2026 :
 * 1. le Garde est prevenu quand un membre quitte son GEM parce qu'il change de tribu ;
 * 2. une demande annulee par le membre (tribu, FISS, Vertumetre, service) est annoncee a ceux qui en avaient ete prevenus,
 *    sur le meme canal (cloche ; courriel seulement pour ceux qui avaient recu un courriel) ;
 * 3. le membre voit son GEM et son Garde (nom et photo seulement).
 */
class CancellationAndGemNoticesTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private Gem $bethel;

    private Department $chorale;

    private User $pr;

    private User $judaPatriarch;

    private User $judaAp;

    private User $leviPatriarch;

    private User $leviAp;

    private User $garde;

    private User $choirLeader;

    private User $marie;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-09 10:00'));
        Http::preventStrayRequests();
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        config(['app.url' => 'https://espace.exemple.test', 'mail.test_to' => null, 'services.sms.test_to' => null]);
        // Comme en production : le changement de tribu est valide par l'AP, les patriarches sont informes.
        Role::where('key', 'patriarche')->firstOrFail()->permissions()->detach(Permission::where('key', 'tribes.transfer')->value('id'));

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->bethel = Gem::create(['name' => 'Béthel', 'tribe_id' => $this->juda->id, 'is_active' => true]);
        $this->chorale = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);

        $this->pr = $this->give($this->person('Pierre', 'Pasteur', null), 'super_admin');
        $this->judaPatriarch = $this->give($this->person('Paul', 'Patriarche', $this->juda), 'patriarche', 'tribe', $this->juda->id);
        $this->judaAp = $this->give($this->person('Abel', 'Assistant', $this->juda), 'assistant_pasteur', 'tribe', $this->juda->id);
        $this->leviPatriarch = $this->give($this->person('Laban', 'Patriarche', $this->levi), 'patriarche', 'tribe', $this->levi->id);
        $this->leviAp = $this->give($this->person('Samuel', 'Assistant', $this->levi), 'assistant_pasteur', 'tribe', $this->levi->id);
        $this->garde = $this->give($this->person('Gaston', 'Garde', $this->juda, $this->bethel), 'garde', 'gem', $this->bethel->id);
        $this->bethel->forceFill(['leader_user_id' => $this->garde->id])->save();
        $this->choirLeader = $this->give($this->person('Chantal', 'Chantre', $this->juda), DepartmentLeadership::ROLE, 'department', $this->chorale->id);
        DepartmentLeadership::appoint($this->chorale, $this->choirLeader->id, $this->pr->id);
        $this->marie = $this->person('Marie', 'Fidèle', $this->juda, $this->bethel);

        UserNotification::query()->delete();
        OutboundMessage::query()->delete();
    }

    // ------------------------------------------------------------------ 1. Depart du GEM par changement de tribu

    public function test_the_garde_is_told_when_a_member_leaves_his_gem_through_a_validated_tribe_change(): void
    {
        $this->as($this->marie);
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->levi->id, 'reason' => 'Déménagement'])->assertCreated();
        $req = TribeChangeRequest::sole();
        $this->as($this->judaAp);
        $this->postJson("/api/admin/validations/tribe/{$req->id}", ['decision' => 'approved'])->assertOk();
        $this->assertSame([], $this->recipients('Départ de votre GEM : Marie Fidèle'), 'rien tant que le changement n\'est pas fait');
        $this->as($this->leviAp);
        $this->postJson("/api/admin/validations/tribe/{$req->id}", ['decision' => 'approved'])->assertOk();

        $this->assertNull($this->marie->profile()->value('gem_id'));
        $this->assertSame([$this->garde->id], $this->recipients('Départ de votre GEM : Marie Fidèle'));
        $note = UserNotification::where('title', 'Départ de votre GEM : Marie Fidèle')->sole();
        $this->assertSame('Marie Fidèle rejoint la tribu Lévi et quitte le GEM Béthel.', $note->body);
        $this->assertSame('/admin/rapport-gem', $note->url);
    }

    public function test_the_garde_is_told_when_a_pastor_moves_the_member_to_another_tribe_from_her_page(): void
    {
        $this->as($this->pr);
        $this->patchJson("/api/admin/members/{$this->marie->id}/belonging", ['tribe_id' => $this->levi->id])->assertOk();
        $this->assertNull($this->marie->profile()->value('gem_id'));
        $this->assertSame([$this->garde->id], $this->recipients('Départ de votre GEM : Marie Fidèle'));

        // Le Garde qui change lui-meme de tribu n'est pas « prevenu » de son propre depart.
        $this->patchJson("/api/admin/members/{$this->garde->id}/belonging", ['tribe_id' => $this->levi->id])->assertOk();
        $this->assertSame([], $this->recipients('Départ de votre GEM : Gaston Garde'));
    }

    // ------------------------------------------------------------------ 2. Annulations

    public function test_a_cancelled_tribe_change_is_announced_to_everyone_who_was_told_by_bell_and_email(): void
    {
        $this->as($this->marie);
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->levi->id, 'reason' => 'Déménagement'])->assertCreated();
        $req = TribeChangeRequest::sole();
        // Prevenus de la demande : les AP des deux tribus (valideurs) et les deux patriarches (pour information).
        $told = $this->ids([$this->judaAp, $this->leviAp, $this->judaPatriarch, $this->leviPatriarch]);
        $this->assertSame($told, $this->sorted(UserNotification::where('title', 'like', 'Changement de tribu demandé%')->pluck('user_id')->all()));
        $this->assertSame($told, $this->sorted(OutboundMessage::whereIn('kind', ['tribe_change_request', 'tribe_change_info'])->pluck('user_id')->all()));

        $this->deleteJson("/api/me/tribe-change/{$req->id}")->assertOk();
        $this->deleteJson("/api/me/tribe-change/{$req->id}")->assertStatus(409);

        $this->assertSame($told, $this->recipients('Demande annulée : Marie Fidèle'));
        $note = UserNotification::where('title', 'Demande annulée : Marie Fidèle')->where('user_id', $this->judaAp->id)->sole();
        $this->assertSame('Marie Fidèle a annulé sa demande de changement de tribu (De la tribu Juda vers la tribu Lévi). Plus rien à faire.', $note->body);
        $mails = OutboundMessage::where('kind', 'request_cancelled')->get();
        $this->assertSame($told, $this->sorted($mails->pluck('user_id')->all()));
        $this->assertSame('Demande annulée : Marie Fidèle', $mails->first()->subject);
        $html = view('emails.request-cancelled', $mails->first()->payload + \App\Mail\NotificationMail::common())->render();
        $this->assertStringContainsString('a annulé sa demande de changement de tribu', $html);
        $this->assertStringContainsString('De la tribu Juda vers la tribu Lévi.', $html);
        // Ni le membre, ni personne d'autre (Garde, PR, responsable de service).
        foreach ([$this->marie, $this->garde, $this->pr, $this->choirLeader] as $other) {
            $this->assertNotContains($other->id, $this->recipients('Demande annulée : Marie Fidèle'));
        }
    }

    public function test_a_cancelled_fiss_or_vertumetre_request_is_announced_to_the_reviewer_only(): void
    {
        $fiss = SpiritualHealthForm::create(['user_id' => $this->marie->id, 'period' => '2026-11', 'meditation' => 10, 'submitted_at' => now(), 'locked_at' => now()]);
        $this->as($this->marie);
        $id = $this->postJson("/api/me/fiss/{$fiss->id}/edit-requests", ['reason' => 'Erreur sur le jeûne'])->assertCreated()->json('request.id')
            ?? \App\Models\FissEditRequest::sole()->id;
        $reviewers = $this->sorted(UserNotification::where('title', 'Demande de modification de FISS : Marie Fidèle')->pluck('user_id')->all());
        $this->assertNotEmpty($reviewers);
        $this->deleteJson("/api/me/fiss/edit-requests/{$id}")->assertOk();

        $this->assertSame($reviewers, $this->recipients('Demande annulée : Marie Fidèle'));
        $this->assertSame('Marie Fidèle a annulé sa demande de modification de la FISS de novembre 2026. Plus rien à faire.',
            UserNotification::where('title', 'Demande annulée : Marie Fidèle')->value('body'));
        $this->assertSame(0, OutboundMessage::count(), 'aucun courriel pour la FISS (la demande n\'en avait pas)');

        UserNotification::query()->delete();
        $vertu = VertumetreForm::create(['user_id' => $this->marie->id, 'period' => '2026-11', 'answers' => [], 'submitted_at' => now(), 'locked_at' => now()]);
        $this->postJson("/api/me/vertumetre/{$vertu->id}/edit-requests", ['reason' => 'Mauvaise durée de prière'])->assertCreated();
        $request = \App\Models\VertumetreEditRequest::sole();
        $reviewers = $this->sorted(UserNotification::where('title', 'Demande de modification du Vertumètre : Marie Fidèle')->pluck('user_id')->all());
        $this->assertNotEmpty($reviewers);
        $this->deleteJson("/api/me/vertumetre/edit-requests/{$request->id}")->assertOk();
        $this->assertSame($reviewers, $this->recipients('Demande annulée : Marie Fidèle'));
        $this->assertStringContainsString('modification du Vertumètre de novembre 2026',
            (string) UserNotification::where('title', 'Demande annulée : Marie Fidèle')->value('body'));
    }

    public function test_a_service_request_withdrawn_by_the_member_is_announced_but_not_one_settled_by_a_leader(): void
    {
        $this->as($this->marie);
        $this->postJson("/api/me/services/{$this->chorale->id}/join")->assertOk();
        $deciders = $this->sorted(UserNotification::where('title', 'Inscription au service Chorale : Marie Fidèle')->pluck('user_id')->all());
        $this->assertContains($this->choirLeader->id, $deciders);
        $this->deleteJson("/api/me/services/{$this->chorale->id}")->assertOk();

        $this->assertSame($deciders, $this->recipients('Demande annulée : Marie Fidèle'));
        $this->assertStringContainsString("a retiré sa demande d'inscription au service Chorale",
            (string) UserNotification::where('title', 'Demande annulée : Marie Fidèle')->value('body'));
        $emailed = $this->sorted(OutboundMessage::where('kind', 'service_request')->pluck('user_id')->all());
        $this->assertSame($emailed, $this->sorted(OutboundMessage::where('kind', 'request_cancelled')->pluck('user_id')->all()));

        // Nouvelle demande le lendemain, puis le pasteur l'ajoute directement : rien n'est « annule » par Marie.
        $this->travel(1)->days();
        UserNotification::query()->delete();
        OutboundMessage::query()->delete();
        $this->postJson("/api/me/services/{$this->chorale->id}/join")->assertOk();
        $this->as($this->pr);
        $this->patchJson("/api/admin/members/{$this->marie->id}/belonging", ['department_ids' => [$this->chorale->id]])->assertOk();
        $this->assertSame([], $this->recipients('Demande annulée : Marie Fidèle'));
        $this->assertSame(0, OutboundMessage::where('kind', 'request_cancelled')->count());
    }

    // ------------------------------------------------------------------ 3. Son GEM et son Garde

    public function test_a_member_sees_her_gem_and_her_garde_without_his_contact_details(): void
    {
        $this->as($this->marie);
        $me = $this->getJson('/api/me')->assertOk();
        $me->assertJsonPath('gem.name', 'Béthel')->assertJsonPath('gem.label', 'GEM Béthel')
            ->assertJsonPath('gem.garde.name', 'Gaston Garde')->assertJsonPath('gem.garde.is_me', false)
            ->assertJsonPath('gem.garde.photo_url', null);
        $this->assertSame(['name', 'photo_url', 'is_me'], array_keys($me->json('gem.garde')));
        $this->assertStringNotContainsString($this->garde->phone, $me->getContent());
        $this->assertStringNotContainsString('gaston.garde@exemple.test', $me->getContent());

        $this->as($this->garde);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('gem.label', 'GEM Béthel')->assertJsonPath('gem.garde.is_me', true);

        // Sans GEM : rien ; GEM sans Garde : le GEM seul ; nom commencant par GEM : pas de doublon.
        $this->as($this->judaAp);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('gem', null);
        $silo = Gem::create(['name' => 'GEM Silo', 'tribe_id' => $this->juda->id, 'is_active' => true]);
        Profile::where('user_id', $this->marie->id)->update(['gem_id' => $silo->id]);
        $this->as($this->marie);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('gem.label', 'GEM Silo')->assertJsonPath('gem.garde', null);
    }

    // ------------------------------------------------------------------ Outils

    private function person(string $first, string $last, ?Tribe $tribe, ?Gem $gem = null): User
    {
        $n = ++$this->n;
        $user = User::create(['phone' => '+1418556'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'last_login_at' => now()]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'femme',
            'email' => Str::lower(Str::ascii($first)).'.'.Str::lower(Str::ascii($last)).'@exemple.test',
            'is_completed' => true, 'tribe_id' => $tribe?->id, 'gem_id' => $gem?->id]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $key, ?string $kind = null, ?int $id = null): User
    {
        $user->roles()->attach(Role::where('key', $key)->value('id'), ['scope_kind' => $kind, 'scope_id' => $id]);

        return $user->fresh();
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user->fresh());
    }

    /** @return array<int> destinataires d'un titre, tries (chacun une seule fois, sinon l'egalite echoue). */
    private function recipients(string $title): array
    {
        return $this->sorted(UserNotification::where('title', $title)->pluck('user_id')->all());
    }

    /** @param array<int, User> $users */
    private function ids(array $users): array
    {
        return $this->sorted(array_map(fn (User $u) => $u->id, $users));
    }

    private function sorted(array $ids): array
    {
        $ids = array_map('intval', $ids);
        sort($ids);

        return $ids;
    }
}
