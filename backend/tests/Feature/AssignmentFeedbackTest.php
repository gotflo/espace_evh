<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Assignment;
use App\Models\AssignmentComment;
use App\Models\AssignmentResponse;
use App\Models\AssignmentResponseLike;
use App\Models\OutboundMessage;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Monitoring\UserAdmin;
use App\Support\PlatformManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Devoir (DS) : texte enrichi, « j'aime » et commentaires du pasteur.
 * Choix du 18/11 : « j'aime » seulement ; commentaire sans reponse du membre ; vus du membre et du pasteur seulement
 * (pas du patriarche ni de l'AP, qui lisent pourtant la reponse).
 */
class AssignmentFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private User $pastor;

    private User $marie;

    private User $paul;

    private User $patriarch;

    private User $ap;

    private User $pa;

    private Assignment $devoir;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 10:00'));
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        config(['services.webpush.enabled' => true, 'app.url' => 'https://espace.exemple.test', 'mail.test_to' => null, 'services.sms.test_to' => null]);

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->pastor = $this->give($this->person('Florent', 'Pasteur', $this->juda), 'super_admin');
        $this->marie = $this->person('Marie', 'Fidèle', $this->juda);
        $this->paul = $this->person('Paul', 'Lévite', $this->levi);
        $this->patriarch = $this->give($this->person('Pierre', 'Patriarche', $this->juda), 'patriarche', 'tribe', $this->juda->id);
        $this->ap = $this->give($this->person('Abel', 'Assistant', $this->juda), 'assistant_pasteur', 'tribe', $this->juda->id);
        $this->pa = $this->give($this->person('Paola', 'Assistante', $this->juda), 'pasteur_assistant');
        $this->devoir = Assignment::create(['title' => 'La foi qui agit', 'media_type' => 'audio', 'media_url' => 'https://exemple.test/a.mp3',
            'published_at' => now()->subDay(), 'created_by' => $this->pastor->id]);
    }

    // ------------------------------------------------------------------ Texte enrichi

    public function test_rich_text_is_rebuilt_from_an_allow_list_and_its_plain_text_is_kept(): void
    {
        $summary = $this->doc([
            ['type' => 'heading', 'attrs' => ['level' => 1, 'onclick' => 'x'], 'content' => [['type' => 'text', 'text' => 'Idée principale']]],
            ['type' => 'paragraph', 'attrs' => ['style' => 'color:red'], 'content' => [
                ['type' => 'text', 'text' => 'La foi ', 'marks' => [['type' => 'bold'], ['type' => 'bold']]],
                ['type' => 'text', 'text' => 'agit', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#C0392B', 'fontFamily' => 'Comic']], ['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
                ['type' => 'text', 'text' => ' <img src=x onerror=alert(1)>', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#123456']], ['type' => 'highlight', 'attrs' => ['color' => '#fff3a3']]]],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => "Fin\u{202E}cachée"],
            ]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Prier chaque matin']]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Lire Jacques']]]]],
            ]],
            ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Trois']]]]],
            ]],
            ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Citation']]]]],
            ['type' => 'paragraph'],
            ['type' => 'paragraph'],
        ]);
        $this->as($this->marie);
        $this->putJson("/api/me/assignments/{$this->devoir->id}/response", ['summary_doc' => $summary, 'actions_doc' => $this->doc([$this->p('Je prierai chaque matin.')])])->assertOk();

        $r = AssignmentResponse::sole();
        $this->assertSame("Idée principale\nLa foi agit <img src=x onerror=alert(1)>\nFincachée\n- Prier chaque matin\n- Lire Jacques\n3. Trois\n> Citation", $r->summary);
        $this->assertSame('Je prierai chaque matin.', $r->actions);
        $doc = $r->summary_doc;
        $this->assertSame(['level' => 2], $doc['content'][0]['attrs'], 'titre ramene au niveau 2, attribut inconnu retire');
        $this->assertArrayNotHasKey('attrs', $doc['content'][1], 'aucun style libre sur un paragraphe');
        $this->assertSame([['type' => 'bold']], $doc['content'][1]['content'][0]['marks'], 'style en double garde une fois');
        $this->assertSame([['type' => 'textStyle', 'attrs' => ['color' => '#c0392b']]], $doc['content'][1]['content'][1]['marks'], 'lien et police retires, couleur de la palette gardee');
        $this->assertSame([['type' => 'highlight', 'attrs' => ['color' => '#fff3a3']]], $doc['content'][1]['content'][2]['marks'], 'couleur hors palette retiree');
        $this->assertSame(['start' => 3], $doc['content'][3]['attrs']);
        $this->assertCount(5, $doc['content'], 'paragraphes vides de la fin retires');
        $this->assertStringNotContainsString('javascript', json_encode($doc));
        $this->assertStringNotContainsString('onclick', json_encode($doc));

        // Le membre retrouve son texte enrichi et son texte brut.
        $mine = $this->getJson('/api/me/assignments')->assertOk()->json('assignments.0.response');
        $this->assertSame($doc, $mine['summary_doc']);
        $this->assertSame(['likes' => [], 'comments' => []], $mine['feedback']);
    }

    public function test_what_does_not_fit_is_refused_and_old_plain_text_still_works(): void
    {
        $this->as($this->marie);
        $url = "/api/me/assignments/{$this->devoir->id}/response";
        $actions = $this->doc([$this->p('Prier chaque jour')]);
        // Blocs inconnus, document qui n'en est pas un, imbrication anormale : refuses.
        foreach ([
            $this->doc([['type' => 'image', 'attrs' => ['src' => 'https://pirate.test/x.png']]]),
            $this->doc([['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'echo 1;']]]]),
            $this->doc([['type' => 'paragraph', 'content' => [['type' => 'paragraph']]]]),
            $this->doc([['type' => 'bulletList', 'content' => [['type' => 'paragraph']]]]),
            ['type' => 'paragraph'],
            ['type' => 'doc', 'content' => 'texte'],
            $this->doc([['type' => 'text', 'text' => 'Texte hors paragraphe, assez long']]),
        ] as $bad) {
            $this->putJson($url, ['summary_doc' => $bad, 'actions_doc' => $actions])->assertStatus(422)->assertJsonValidationErrors(['summary']);
        }
        // Trop court ou trop long, mesure sur le texte (pas sur la mise en forme).
        $this->putJson($url, ['summary_doc' => $this->doc([$this->p('Court')]), 'actions_doc' => $actions])->assertStatus(422)->assertJsonValidationErrors(['summary']);
        $this->putJson($url, ['summary_doc' => $this->doc([$this->p(str_repeat('a', 5001))]), 'actions_doc' => $actions])->assertStatus(422)->assertJsonValidationErrors(['summary']);
        $this->putJson($url, ['summary_doc' => $this->doc([$this->p('Un résumé suffisant')]), 'actions_doc' => $this->doc([['type' => 'paragraph']])])
            ->assertStatus(422)->assertJsonValidationErrors(['actions']);
        $deep = $this->p('Profond, très profond');
        for ($i = 0; $i < 10; $i++) {
            $deep = ['type' => 'blockquote', 'content' => [$deep]];
        }
        $this->putJson($url, ['summary_doc' => $this->doc([$deep]), 'actions_doc' => $actions])->assertStatus(422);
        $this->assertSame(0, AssignmentResponse::count());

        // Ancienne version de l'application (texte simple) : toujours acceptee, sans document.
        $this->putJson($url, ['summary' => "Ligne un\r\nLigne deux", 'actions' => 'Prier'])->assertOk()
            ->assertJsonPath('response.summary', "Ligne un\nLigne deux")->assertJsonPath('response.summary_doc', null);
        $this->putJson($url, ['summary' => 'trop court', 'actions' => ''])->assertStatus(422)->assertJsonValidationErrors(['actions']);
    }

    // ------------------------------------------------------------------ Commentaires et « j'aime »

    public function test_the_pastor_comments_and_the_member_is_told_in_the_app_on_the_phone_and_by_email_without_the_text(): void
    {
        $response = $this->answer($this->marie);
        $this->as($this->pastor);
        $comment = $this->postJson("/api/admin/assignment-responses/{$response->id}/comments", ['body_doc' => $this->doc([$this->p('Très beau résumé, continue ainsi !')])])
            ->assertCreated()->assertJsonPath('comment.author', 'Florent Pasteur')->assertJsonPath('comment.mine', true)
            ->assertJsonPath('comment.body', 'Très beau résumé, continue ainsi !')->json('comment');

        // Cloche : titre et lien vers le devoir, sans le texte du commentaire.
        $note = UserNotification::where('user_id', $this->marie->id)->sole();
        $this->assertSame(['assignment_comment', 'Commentaire de Florent Pasteur', "/mon-devoir?d={$this->devoir->id}"], [$note->type, $note->title, $note->url]);
        $this->assertStringNotContainsString('beau', $note->title.$note->body);
        // Telephone : meme chose.
        $push = DB::table('push_outbox')->sole();
        $this->assertSame([$this->marie->id], json_decode($push->user_ids, true));
        $this->assertStringNotContainsString('beau', $push->payload);
        // Courriel : en file, avec le lien, sans le texte.
        $mail = OutboundMessage::where('kind', 'assignment_comment')->sole();
        $this->assertSame(['marie.fidele@exemple.test', 'assignment-comment', 'Florent Pasteur a commenté votre devoir'], [$mail->recipient, $mail->view, $mail->subject]);
        $this->assertStringNotContainsString('beau', json_encode($mail->payload));
        $this->assertSame("https://espace.exemple.test/mon-devoir?d={$this->devoir->id}", $mail->payload['url']);
        $html = (new NotificationMail($mail->subject, $mail->view, $mail->payload))->render();
        $this->assertStringContainsString('La foi qui agit', $html);

        // Le membre le lit dans son devoir ; il ne peut ni repondre, ni commenter, ni aimer.
        $this->as($this->marie);
        $mine = $this->getJson('/api/me/assignments')->assertOk()->json('assignments.0.response.feedback');
        $this->assertSame('Très beau résumé, continue ainsi !', $mine['comments'][0]['body']);
        $this->assertSame([false, false], [$mine['comments'][0]['mine'], $mine['comments'][0]['edited']]);
        $this->postJson("/api/admin/assignment-responses/{$response->id}/comments", ['body' => 'Merci pasteur'])->assertForbidden();
        $this->putJson("/api/admin/assignment-responses/{$response->id}/like", ['liked' => true])->assertForbidden();
        $this->putJson("/api/admin/assignment-comments/{$comment['id']}", ['body' => 'Changé'])->assertForbidden();
        $this->deleteJson("/api/admin/assignment-comments/{$comment['id']}")->assertForbidden();
        // Ouvrir le devoir (adresse de la notification) la marque lue.
        $this->postJson('/api/me/notifications/read-url', ['url' => "/mon-devoir?d={$this->devoir->id}"])->assertOk()->assertJsonPath('count', 1);
    }

    public function test_one_like_per_pastor_notified_once_and_withdrawn_with_the_like(): void
    {
        $response = $this->answer($this->marie);
        $this->as($this->pastor);
        $url = "/api/admin/assignment-responses/{$response->id}/like";
        $this->putJson($url, ['liked' => true])->assertOk()->assertJsonPath('feedback.liked', true)->assertJsonPath('feedback.likes.0.name', 'Florent Pasteur');
        $this->putJson($url, ['liked' => true])->assertOk();
        $this->assertSame(1, AssignmentResponseLike::count());
        $note = UserNotification::where('user_id', $this->marie->id)->sole();
        $this->assertSame(['assignment_like', 'Florent Pasteur aime votre devoir', '« La foi qui agit »'], [$note->type, $note->title, $note->body]);

        // Retire : plus de « j'aime », et l'avis non lu disparait (il ne serait plus vrai) ; remis : pas de nouvel avis.
        $this->putJson($url, ['liked' => false])->assertOk()->assertJsonPath('feedback.liked', false)->assertJsonPath('feedback.likes', []);
        $this->assertSame(0, UserNotification::count());
        $this->putJson($url, ['liked' => true])->assertOk();
        $this->assertSame(0, UserNotification::count());
        $this->putJson($url, [])->assertStatus(422);

        $this->as($this->marie);
        $this->assertSame([['name' => 'Florent Pasteur', 'photo_url' => null, 'is_me' => false]], $this->getJson('/api/me/assignments')->json('assignments.0.response.feedback.likes'));
    }

    public function test_only_the_member_and_the_pastor_see_the_feedback_not_the_patriarch_nor_the_ap(): void
    {
        $response = $this->answer($this->marie);
        $this->as($this->pastor);
        $this->postJson("/api/admin/assignment-responses/{$response->id}/comments", ['body' => 'SECRET-COMMENTAIRE'])->assertCreated();
        $this->putJson("/api/admin/assignment-responses/{$response->id}/like", ['liked' => true])->assertOk();
        $list = $this->getJson("/api/admin/assignments/{$this->devoir->id}/responses")->assertOk()->assertJsonPath('can_feedback', true);
        $this->assertSame('SECRET-COMMENTAIRE', $list->json('responses.0.feedback.comments.0.body'));
        $this->getJson("/api/admin/members/{$this->marie->id}/assignments")->assertOk()->assertJsonPath('can_feedback', true)
            ->assertJsonPath('responses.0.feedback.liked', true);

        // Patriarche, AP et PA (sans le droit) lisent la reponse, jamais les retours ; ils ne peuvent rien donner.
        foreach ([$this->patriarch, $this->ap, $this->pa] as $leader) {
            $this->as($leader);
            $res = $this->getJson("/api/admin/assignments/{$this->devoir->id}/responses")->assertOk()->assertJsonPath('can_feedback', false);
            $this->assertSame('Un résumé suffisamment long', $res->json('responses.0.summary'));
            $this->assertNull($res->json('responses.0.feedback'));
            $this->assertStringNotContainsString('SECRET-COMMENTAIRE', $res->getContent());
            $this->assertStringNotContainsString('SECRET-COMMENTAIRE', $this->getJson("/api/admin/members/{$this->marie->id}/assignments")->assertOk()->getContent());
            $this->postJson("/api/admin/assignment-responses/{$response->id}/comments", ['body' => 'Bravo'])->assertForbidden();
            $this->putJson("/api/admin/assignment-responses/{$response->id}/like", ['liked' => true])->assertForbidden();
        }
        // Un autre membre ne voit que ses propres devoirs.
        $this->answer($this->paul);
        $this->as($this->paul);
        $this->assertStringNotContainsString('SECRET-COMMENTAIRE', $this->getJson('/api/me/assignments')->assertOk()->getContent());
        // Compte de gestion : rien.
        Sanctum::actingAs(PlatformManager::activate('+22893471703')[0]);
        $this->postJson("/api/admin/assignment-responses/{$response->id}/comments", ['body' => 'Bravo'])->assertForbidden();
        $this->assertSame(1, AssignmentComment::count());
    }

    public function test_the_right_can_be_given_but_only_within_the_tribes_one_follows(): void
    {
        $permission = Permission::where('key', 'assignments.feedback')->value('id');
        Role::where('key', 'patriarche')->firstOrFail()->permissions()->attach($permission);
        Role::where('key', 'pasteur_assistant')->firstOrFail()->permissions()->attach($permission);
        $inJuda = $this->answer($this->marie);
        $inLevi = $this->answer($this->paul);

        // Patriarche de Juda : sa tribu oui, Levi non (comme s'il n'existait pas).
        $this->as($this->patriarch);
        $this->postJson("/api/admin/assignment-responses/{$inJuda->id}/comments", ['body' => 'Bien'])->assertCreated();
        $this->postJson("/api/admin/assignment-responses/{$inLevi->id}/comments", ['body' => 'Bien'])->assertNotFound();
        $this->putJson("/api/admin/assignment-responses/{$inLevi->id}/like", ['liked' => true])->assertNotFound();
        $this->postJson('/api/admin/assignment-responses/999999/comments', ['body' => 'Bien'])->assertNotFound();
        // Le PA (toute l'eglise) voit les commentaires du patriarche, mais ne peut ni les modifier ni les supprimer.
        $this->as($this->pa);
        $id = $this->getJson("/api/admin/assignments/{$this->devoir->id}/responses")->assertOk()->json('responses.1.feedback.comments.0.id')
            ?? $this->getJson("/api/admin/assignments/{$this->devoir->id}/responses")->json('responses.0.feedback.comments.0.id');
        $this->assertNotNull($id);
        $this->putJson("/api/admin/assignment-comments/{$id}", ['body' => 'Piraté'])->assertForbidden();
        $this->deleteJson("/api/admin/assignment-comments/{$id}")->assertForbidden();
        $this->postJson("/api/admin/assignment-responses/{$inLevi->id}/comments", ['body' => 'Bien'])->assertCreated();
        // Sa propre reponse : jamais.
        $own = $this->answer($this->pa);
        $this->as($this->pa);
        $this->postJson("/api/admin/assignment-responses/{$own->id}/comments", ['body' => 'Moi'])->assertNotFound();
        $this->putJson("/api/admin/assignment-responses/{$own->id}/like", ['liked' => true])->assertNotFound();
    }

    public function test_editing_and_deleting_a_comment(): void
    {
        $response = $this->answer($this->marie);
        $this->as($this->pastor);
        $id = $this->postJson("/api/admin/assignment-responses/{$response->id}/comments", ['body' => 'Premier jet'])->json('comment.id');
        // Commentaire vide ou trop long : refuse.
        $this->postJson("/api/admin/assignment-responses/{$response->id}/comments", ['body_doc' => $this->doc([['type' => 'paragraph']])])->assertStatus(422)->assertJsonValidationErrors(['body']);
        $this->postJson("/api/admin/assignment-responses/{$response->id}/comments", [])->assertStatus(422);
        $this->postJson("/api/admin/assignment-responses/{$response->id}/comments", ['body' => str_repeat('x', 2001)])->assertStatus(422);

        $this->putJson("/api/admin/assignment-comments/{$id}", ['body_doc' => $this->doc([$this->p('Version relue')])])->assertOk()
            ->assertJsonPath('comment.edited', true)->assertJsonPath('comment.body', 'Version relue');
        $this->assertSame(1, UserNotification::count(), 'modifier ne previent pas une seconde fois');

        // Supprime : il disparait pour le membre, avec son avis non lu et son courriel pas encore parti.
        $this->deleteJson("/api/admin/assignment-comments/{$id}")->assertOk();
        $this->assertSame(0, AssignmentComment::count());
        $this->assertSame(0, UserNotification::count());
        $this->assertSame(0, OutboundMessage::where('kind', 'assignment_comment')->count());
        $this->deleteJson("/api/admin/assignment-comments/{$id}")->assertNotFound();
        $this->as($this->marie);
        $this->assertSame([], $this->getJson('/api/me/assignments')->json('assignments.0.response.feedback.comments'));
    }

    public function test_a_withdrawn_assignment_takes_no_more_feedback(): void
    {
        $response = $this->answer($this->marie);
        $this->devoir->delete();
        $this->as($this->pastor);
        $this->postJson("/api/admin/assignment-responses/{$response->id}/comments", ['body' => 'Bien'])->assertStatus(422);
        $this->putJson("/api/admin/assignment-responses/{$response->id}/like", ['liked' => true])->assertStatus(422);
        $this->assertNull($this->getJson("/api/admin/members/{$this->marie->id}/assignments")->assertOk()->json('responses.0.feedback'));
    }

    public function test_the_member_page_updates_by_itself_and_everything_leaves_with_an_account(): void
    {
        $response = $this->answer($this->marie);
        $this->as($this->marie);
        $before = $this->getJson('/api/me/pulse')->json('versions.mine');
        $this->as($this->pastor);
        $this->postJson("/api/admin/assignment-responses/{$response->id}/comments", ['body' => 'Bien'])->assertCreated();
        $this->as($this->marie);
        $afterComment = $this->getJson('/api/me/pulse')->json('versions.mine');
        $this->assertNotSame($before, $afterComment, 'commentaire : la page « Mon devoir » se relit');
        $this->as($this->pastor);
        $this->putJson("/api/admin/assignment-responses/{$response->id}/like", ['liked' => true])->assertOk();
        $this->as($this->marie);
        $this->assertNotSame($afterComment, $this->getJson('/api/me/pulse')->json('versions.mine'));

        // Compte du pasteur supprime : le commentaire reste pour le membre, sans nom ; son « j'aime » part.
        $this->assertSame(1, collect(UserAdmin::impact($this->marie)['deleted'])->firstWhere('label', 'Commentaires du pasteur sur ses devoirs')['count']);
        UserAdmin::delete($this->pastor->fresh(), 'test');
        $this->as($this->marie);
        $feedback = $this->getJson('/api/me/assignments')->assertOk()->json('assignments.0.response.feedback');
        $this->assertSame(['Ancien pasteur', []], [$feedback['comments'][0]['author'], $feedback['likes']]);
        // Compte du membre supprime : sa reponse et tout ce qui la concerne partent.
        UserAdmin::delete($this->marie->fresh(), 'test');
        $this->assertSame([0, 0], [AssignmentComment::count(), AssignmentResponseLike::count()]);
    }

    // ------------------------------------------------------------------ Outils

    private function answer(User $member): AssignmentResponse
    {
        $this->as($member);
        $this->putJson("/api/me/assignments/{$this->devoir->id}/response", ['summary' => 'Un résumé suffisamment long', 'actions' => 'Prier chaque jour'])->assertOk();

        return AssignmentResponse::where('user_id', $member->id)->sole();
    }

    private function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    private function p(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function person(string $first, string $last, ?Tribe $tribe): User
    {
        $n = ++$this->n;
        $user = User::create(['phone' => '+1418559'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'last_login_at' => now()]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'femme',
            'email' => Str::lower(Str::ascii($first)).'.'.Str::lower(Str::ascii($last)).'@exemple.test',
            'is_completed' => true, 'tribe_id' => $tribe?->id]);
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
}
