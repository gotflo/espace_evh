<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Department;
use App\Models\DepartmentJoinRequest;
use App\Models\FamilyLink;
use App\Models\FissEditRequest;
use App\Models\Gem;
use App\Models\MemberRequest;
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
use App\Services\AppointmentService;
use App\Services\Messenger;
use App\Support\DepartmentLeadership;
use App\Support\GemRules;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Parcours de bout en bout lances par un membre, ecran par ecran, avec les memes appels que l'application :
 * chaque action de Marie (fidele de la tribu Juda, GEM Bethel, service Chorale), puis ce que voient et recoivent
 * ses responsables (Garde, patriarche, AP, responsables de service, PA, PR, protocole) par leurs propres ecrans,
 * et ce qui revient a Marie (cloche, courriel). Pour chaque etape : reponse HTTP, donnees, qui est prevenu (et qui
 * ne l'est pas), courriels en file, visibilite depuis les autres roles.
 *
 * Roles tels qu'en production (7 novembre 2026) : le responsable de departement a aussi departments.manage,
 * members.edit, members.export et les annonces ; le patriarche n'a pas tribes.transfer (les AP valident les
 * changements de tribu).
 */
class ChurchJourneysMembersTest extends TestCase
{
    use RefreshDatabase;

    /** Lundi 9 novembre 2026, 10 h. */
    private const NOW = '2026-11-09 10:00:00';

    /** Mercredi suivant : seance de rendez-vous apres le culte (21h50 - 23h20). */
    private const WED = '2026-11-11';

    private Tribe $juda;

    private Tribe $levi;

    private Gem $bethel;

    private Department $chorale;

    private Department $accueil;

    private User $pr;

    private User $pa;

    private User $patriarch;

    private User $ap;

    private User $leviPatriarch;

    private User $leviAp;

    private User $garde;

    private User $marie;

    private User $choirLeader;

    private User $choirCoLeader;

    private User $welcomeLeader;

    private User $protocol;

    private int $n = 0;

    /** @var array<int, string> nom de chaque personne (messages d'echec lisibles) */
    private array $names = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        Http::preventStrayRequests();
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        config(['app.url' => 'https://espace.exemple.test', 'mail.test_to' => null, 'services.sms.test_to' => null]);
        $this->productionRoles();

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->bethel = Gem::create(['name' => 'Béthel', 'tribe_id' => $this->juda->id, 'is_active' => true]);
        $this->chorale = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        $this->accueil = Department::create(['name' => 'Accueil', 'slug' => 'accueil', 'is_active' => true]);

        $this->pr = $this->give($this->person('Pierre', 'Résident', null), 'super_admin');
        $this->pa = $this->give($this->person('Paul', 'Assistant', null), 'pasteur_assistant');
        $this->patriarch = $this->give($this->person('Abraham', 'Juda', $this->juda), 'patriarche', 'tribe', $this->juda->id);
        $this->ap = $this->give($this->person('Aaron', 'Juda', $this->juda), 'assistant_pasteur', 'tribe', $this->juda->id);
        $this->leviPatriarch = $this->give($this->person('Laban', 'Lévi', $this->levi), 'patriarche', 'tribe', $this->levi->id);
        $this->leviAp = $this->give($this->person('Samuel', 'Lévi', $this->levi), 'assistant_pasteur', 'tribe', $this->levi->id);
        $garde = $this->person('Gédéon', 'Garde', $this->juda);
        GemRules::appointLeader($this->bethel, $garde->id, $this->pr->id);
        $this->garde = $garde->fresh();
        $this->marie = $this->person('Marie', 'Fidèle', $this->juda, $this->bethel, 'femme');
        $this->chorale->members()->attach($this->marie->profile->id);
        // Responsables de service : role sur le departement ET nomination dans Organisation (toujours synchronises).
        $this->choirLeader = $this->leader($this->person('Chantal', 'Chantre', $this->levi, null, 'femme'), $this->chorale);
        $this->choirCoLeader = $this->leader($this->person('Céline', 'Soprano', $this->levi, null, 'femme'), $this->chorale);
        $this->welcomeLeader = $this->leader($this->person('Bruno', 'Accueil', $this->juda), $this->accueil);
        $this->protocol = $this->give($this->person('Esther', 'Protocole', $this->juda, null, 'femme'), 'protocole');

        // Les nominations ne comptent pas : chaque parcours part d'une cloche et d'une file d'envoi vides.
        UserNotification::query()->delete();
        OutboundMessage::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================ 1. FISS

    public function test_fiss_marie_fills_it_her_tribe_leaders_read_it_the_garde_never_and_an_edit_goes_through_the_patriarch(): void
    {
        // Ecran « Ma fiche » : novembre est a remplir ; l'envoi verrouille la fiche, sans prevenir personne.
        $this->as($this->marie);
        $this->getJson('/api/me/fiss')->assertOk()->assertJsonPath('period', '2026-11')->assertJsonPath('filled', false);
        $form = $this->postJson('/api/me/fiss', $this->fiss())->assertCreated()
            ->assertJsonPath('message', 'Fiche enregistrée. Merci !')->assertJsonPath('current.locked', true)
            ->assertJsonPath('current.editable', false)->json('current');
        $this->postJson('/api/me/fiss', $this->fiss())->assertStatus(409);
        $this->getJson('/api/me/fiss')->assertOk()->assertJsonPath('filled', true)->assertJsonPath('reminder.level', 'none');
        $this->assertSame(0, UserNotification::count(), 'remplir sa fiche ne previent personne');

        // Patriarche, AP de la tribu, PA, PR : la fiche (vie interieure comprise) sur la fiche du membre.
        foreach ([$this->patriarch, $this->ap, $this->pa, $this->pr] as $reader) {
            $this->as($reader);
            $this->getJson("/api/admin/members/{$this->marie->id}")->assertOk()->assertJsonPath('can_view_fiss', true);
            $this->getJson("/api/admin/members/{$this->marie->id}/fiss")->assertOk()
                ->assertJsonPath('forms.0.period', '2026-11')->assertJsonPath('forms.0.meditation', 14)
                ->assertJsonPath('forms.0.inner_wound', 'Blessure confiée au patriarche')
                ->assertJsonPath('forms.0.comment', 'Mois difficile');
        }
        // Patriarche et AP : liste des membres et rapport de la tribu (fiche du mois remplie, absente des manquantes).
        foreach ([$this->patriarch, $this->ap] as $reader) {
            $this->as($reader);
            $this->assertTrue($this->rowOf($this->getJson('/api/admin/members?per_page=100')->assertOk()->json('members'), $this->marie)['fiss_current']);
            $this->assertTrue($this->rowOf($this->getJson("/api/admin/reports/members?scope=tribe:{$this->juda->id}")->assertOk()->json('members'), $this->marie)['fiss_current']);
            $missing = collect($this->getJson("/api/admin/reports/members?scope=tribe:{$this->juda->id}&filter=fiss_missing")->assertOk()->json('members'))->pluck('user_id');
            $this->assertNotContains($this->marie->id, $missing);
            $this->assertContains($this->garde->id, $missing);
        }

        // Garde et responsable de service : ils suivent Marie mais ne voient jamais sa FISS (ni l'etat du mois).
        foreach ([$this->garde, $this->choirLeader] as $other) {
            $this->as($other);
            $this->getJson("/api/admin/members/{$this->marie->id}")->assertOk()->assertJsonPath('can_view_fiss', false);
            $this->getJson("/api/admin/members/{$this->marie->id}/fiss")->assertForbidden();
            $this->getJson("/api/admin/members/{$this->marie->id}/fiss-history")->assertForbidden();
            $this->assertNull($this->rowOf($this->getJson('/api/admin/members?per_page=100')->assertOk()->json('members'), $this->marie)['fiss_current']);
        }
        $this->as($this->garde);
        $this->getJson("/api/admin/reports/members?scope=tribe:{$this->juda->id}")->assertForbidden();
        // Patriarche d'une autre tribu : ni la fiche du membre, ni sa FISS.
        $this->as($this->leviPatriarch);
        $this->getJson("/api/admin/members/{$this->marie->id}")->assertForbidden();
        $this->getJson("/api/admin/members/{$this->marie->id}/fiss")->assertForbidden();
        $this->getJson("/api/admin/reports/members?scope=tribe:{$this->juda->id}")->assertForbidden();

        // Fiche verrouillee : Marie demande a la modifier. Seul son patriarche est prevenu (priorite haute).
        $this->as($this->marie);
        $this->putJson("/api/me/fiss/{$form['id']}", $this->fiss(['jeune' => 16]))->assertStatus(423);
        $this->postJson("/api/me/fiss/{$form['id']}/edit-requests", ['reason' => 'Erreur sur le jeûne'])->assertCreated()
            ->assertJsonPath('message', 'Demande envoyée à votre patriarche.')
            ->assertJsonPath('form.pending_request.reason', 'Erreur sur le jeûne')->assertJsonPath('form.requests_left', 1);
        $this->assertNotifiedExactly('Demande de modification de FISS : Marie Fidèle', [$this->patriarch], 'fiss_request');
        $note = $this->noteFor($this->patriarch, 'Demande de modification de FISS : Marie Fidèle');
        $this->assertSame(['high', '/admin/validations', 'novembre 2026 · Erreur sur le jeûne'], [$note->priority, $note->url, $note->body]);
        $this->assertSame(1, UserNotification::count(), 'ni l\'AP, ni le Garde, ni les pasteurs ne sont prevenus');

        // Centre de validation : le patriarche (et l'AP de la tribu) la voient ; personne d'autre ne peut decider.
        $this->as($this->patriarch);
        $validations = $this->getJson('/api/admin/validations')->assertOk()->assertJsonPath('count', 1)->json('fiss');
        $this->assertSame(['Marie Fidèle', 'Juda', 'novembre 2026', 'Erreur sur le jeûne', 1],
            [$validations[0]['member']['name'], $validations[0]['member']['tribe'], $validations[0]['period_label'], $validations[0]['reason'], $validations[0]['request_number']]);
        $requestId = $validations[0]['id'];
        $this->as($this->ap);
        $this->assertCount(1, $this->getJson('/api/admin/validations')->assertOk()->json('fiss'));
        foreach ([$this->garde, $this->choirLeader, $this->leviPatriarch, $this->protocol] as $other) {
            $this->as($other);
            $this->assertSame([], $this->getJson('/api/admin/validations')->assertOk()->json('fiss'));
            $this->postJson("/api/admin/validations/fiss/{$requestId}", ['decision' => 'approved'])->assertForbidden();
        }
        $this->as($this->marie);
        $this->postJson("/api/admin/validations/fiss/{$requestId}", ['decision' => 'approved'])->assertForbidden();

        // Le patriarche approuve : Marie est prevenue ; la demande disparait chez l'AP (qui ne peut plus decider).
        $this->as($this->patriarch);
        $this->postJson("/api/admin/validations/fiss/{$requestId}", ['decision' => 'rejected'])->assertStatus(422); // motif du refus obligatoire
        $this->postJson("/api/admin/validations/fiss/{$requestId}", ['decision' => 'approved'])->assertOk()
            ->assertJsonPath('message', 'Demande approuvée : la fiche est déverrouillée pour une modification.');
        $this->assertNotifiedExactly('Modification de votre FISS approuvée', [$this->marie], 'fiss_request');
        $this->as($this->ap);
        $this->assertSame([], $this->getJson('/api/admin/validations')->assertOk()->json('fiss'));
        $this->postJson("/api/admin/validations/fiss/{$requestId}", ['decision' => 'approved'])->assertStatus(409);

        // Marie le voit dans sa cloche, modifie sa fiche une fois, et elle est reverrouillee.
        $this->as($this->marie);
        $bell = $this->getJson('/api/me/notifications')->assertOk()->assertJsonPath('unread', 1)->json('notifications.0');
        $this->assertSame(['fiss_request', 'Modification de votre FISS approuvée', 'Vous pouvez modifier votre fiche de novembre 2026 pendant 7 jours.', '/ma-fiche', false],
            [$bell['type'], $bell['title'], $bell['body'], $bell['url'], $bell['read']]);
        $this->getJson('/api/me/fiss')->assertOk()->assertJsonPath('current.editable', true)->assertJsonPath('current.locked', false)
            ->assertJsonPath('current.last_decision.status', 'approved');
        $this->putJson("/api/me/fiss/{$form['id']}", $this->fiss(['jeune' => 16]))->assertOk()
            ->assertJsonPath('message', 'Fiche modifiée et reverrouillée.')->assertJsonPath('form.locked', true)->assertJsonPath('form.jeune', 16);
        $this->putJson("/api/me/fiss/{$form['id']}", $this->fiss(['jeune' => 18]))->assertStatus(423);
        $this->assertSame('used', FissEditRequest::find($requestId)->status);

        // Seconde demande, puis Marie l'annule : elle sort du centre de validation, la decision n'est plus possible,
        // et l'annulation ne compte pas dans la limite de 2 demandes.
        $second = $this->postJson("/api/me/fiss/{$form['id']}/edit-requests", ['reason' => 'Autre correction'])->assertCreated()
            ->assertJsonPath('form.requests_left', 0)->json('form.pending_request.id');
        $this->assertSame(2, UserNotification::where('user_id', $this->patriarch->id)->where('type', 'fiss_request')->count());
        $this->deleteJson("/api/me/fiss/edit-requests/{$second}")->assertOk()->assertJsonPath('message', 'Demande annulée.');
        // Le patriarche, prevenu de la demande, l'est aussi de son annulation (une fois, personne d'autre).
        $this->assertNotifiedExactly('Demande annulée : Marie Fidèle', [$this->patriarch], 'fiss_request');
        $this->getJson('/api/me/fiss')->assertOk()->assertJsonPath('current.requests_left', 1)->assertJsonPath('current.pending_request', null);
        $this->as($this->patriarch);
        $this->assertSame([], $this->getJson('/api/admin/validations')->assertOk()->json('fiss'));
        $this->postJson("/api/admin/validations/fiss/{$second}", ['decision' => 'approved'])->assertStatus(409);
        $this->as($this->marie);
        $this->deleteJson("/api/me/fiss/edit-requests/{$second}")->assertStatus(409);

        // Retour chez le patriarche : nouvelle valeur et tracabilite complete.
        $this->as($this->patriarch);
        $this->getJson("/api/admin/members/{$this->marie->id}/fiss")->assertOk()->assertJsonPath('forms.0.jeune', 16);
        $actions = collect($this->getJson("/api/admin/members/{$this->marie->id}/fiss-history")->assertOk()->json('history'))->pluck('action');
        foreach (['fiss.created', 'fiss.locked', 'fiss.edit_requested', 'fiss.edit_approved', 'fiss.unlocked', 'fiss.modified', 'fiss.edit_cancelled'] as $action) {
            $this->assertContains($action, $actions);
        }
        $this->assertSame(4, UserNotification::count());
        $this->assertSame(0, OutboundMessage::count(), 'la FISS ne part jamais par courriel');
    }

    // ================================================================ 2. Vertumetre et RM

    public function test_vertumetre_and_monthly_resolutions_are_read_by_tribe_leaders_and_pastors_only(): void
    {
        // Ecran « Vertumètre » : le mois en cours seulement, verrouille a l'envoi.
        $this->as($this->marie);
        $this->assertSame(['2026-11'], collect($this->getJson('/api/me/vertumetre')->assertOk()->json('periods'))->pluck('period')->all());
        $vertuId = $this->postJson('/api/me/vertumetre', ['period' => '2026-11', 'answers' => $this->vertumetre()])->assertCreated()
            ->assertJsonPath('form.locked', true)->assertJsonPath('message', 'Vertumètre de novembre 2026 enregistré. Merci !')->json('form.id');
        $this->postJson('/api/me/vertumetre', ['period' => '2026-10', 'answers' => $this->vertumetre()])->assertStatus(422);

        // Ecran « Mes RM » : resolutions du mois.
        $this->putJson('/api/me/resolutions', ['meditation' => 'Méditer chaque matin', 'priere' => 'Prier 15 minutes avant le travail'])->assertOk()
            ->assertJsonPath('message', 'Résolutions enregistrées. Que Dieu vous fortifie !')->assertJsonPath('current.count', 2);
        $this->getJson('/api/me/resolutions')->assertOk()->assertJsonPath('current.meditation', 'Méditer chaque matin');
        $this->getJson('/api/me/month')->assertOk()->assertJsonPath('steps.vertumetre.done', true);
        $this->assertSame(0, UserNotification::count());

        // Lecteurs : patriarche et AP de la tribu, PA, PR (fiche du membre et suivi du mois).
        foreach ([$this->patriarch, $this->ap, $this->pa, $this->pr] as $reader) {
            $this->as($reader);
            $this->getJson("/api/admin/members/{$this->marie->id}/vertumetre")->assertOk()
                ->assertJsonPath('forms.0.period', '2026-11')->assertJsonPath('forms.0.answers.soutien_priere', 'Mon travail');
            $this->assertTrue($this->rowOf($this->getJson('/api/admin/vertumetre')->assertOk()->json('members'), $this->marie)['filled']);
            $this->getJson("/api/admin/members/{$this->marie->id}/resolutions")->assertOk()
                ->assertJsonPath('resolutions.0.priere', 'Prier 15 minutes avant le travail');
            $row = $this->rowOf($this->getJson('/api/admin/resolutions')->assertOk()->json('members'), $this->marie);
            $this->assertSame([true, 2], [$row['taken'], $row['count']]);
        }
        // Ni le Garde, ni un responsable de service (le sien ou un autre), ni le protocole, ni le patriarche d'une autre tribu.
        foreach ([$this->garde, $this->choirLeader, $this->welcomeLeader, $this->protocol, $this->leviPatriarch] as $other) {
            $this->as($other);
            $this->getJson("/api/admin/members/{$this->marie->id}/vertumetre")->assertForbidden();
            $this->getJson("/api/admin/members/{$this->marie->id}/resolutions")->assertForbidden();
        }
        foreach ([$this->garde, $this->choirLeader, $this->protocol] as $other) {
            $this->as($other);
            $this->getJson('/api/admin/vertumetre')->assertForbidden();
            $this->getJson('/api/admin/resolutions')->assertForbidden();
        }
        $this->as($this->leviPatriarch);
        $this->assertNull($this->rowOf($this->getJson('/api/admin/vertumetre')->assertOk()->json('members'), $this->marie));
        $this->assertNull($this->rowOf($this->getJson('/api/admin/resolutions')->assertOk()->json('members'), $this->marie));

        // Modification du Vertumetre : demande au patriarche (seul prevenu), decision, Marie prevenue, une modification.
        $this->as($this->marie);
        $this->putJson("/api/me/vertumetre/{$vertuId}", ['answers' => $this->vertumetre(['priere_duree' => '30-60'])])->assertStatus(423);
        $this->postJson("/api/me/vertumetre/{$vertuId}/edit-requests", ['reason' => 'Mauvaise durée de prière'])->assertCreated();
        $this->assertNotifiedExactly('Demande de modification du Vertumètre : Marie Fidèle', [$this->patriarch], 'fiss_request');
        $this->as($this->garde);
        $this->assertSame([], $this->getJson('/api/admin/validations')->assertOk()->json('vertumetre'));
        $this->as($this->patriarch);
        $item = $this->getJson('/api/admin/validations')->assertOk()->json('vertumetre.0');
        $this->assertSame(['Marie Fidèle', 'Mauvaise durée de prière'], [$item['member']['name'], $item['reason']]);
        $this->as($this->leviPatriarch);
        $this->postJson("/api/admin/validations/vertumetre/{$item['id']}", ['decision' => 'approved'])->assertForbidden();
        $this->as($this->patriarch);
        $this->postJson("/api/admin/validations/vertumetre/{$item['id']}", ['decision' => 'approved'])->assertOk();
        $this->assertNotifiedExactly('Modification de votre Vertumètre approuvée', [$this->marie], 'fiss_request');
        $this->assertSame('/vertumetre?mois=2026-11', $this->noteFor($this->marie, 'Modification de votre Vertumètre approuvée')->url);
        $this->as($this->marie);
        $this->putJson("/api/me/vertumetre/{$vertuId}", ['answers' => $this->vertumetre(['priere_duree' => '30-60'])])->assertOk()->assertJsonPath('form.locked', true);
        $this->as($this->ap);
        $this->getJson("/api/admin/members/{$this->marie->id}/vertumetre")->assertOk()->assertJsonPath('forms.0.answers.priere_duree', '30-60');

        // Les RM se modifient librement pendant le mois : les responsables lisent la nouvelle version.
        $this->as($this->marie);
        $this->putJson('/api/me/resolutions', ['meditation' => 'Méditer chaque matin', 'priere' => 'Prier 30 minutes', 'jeune' => 'Un jour par semaine'])->assertOk()
            ->assertJsonPath('message', 'Résolutions mises à jour.');
        $this->as($this->patriarch);
        $this->getJson("/api/admin/members/{$this->marie->id}/resolutions")->assertOk()
            ->assertJsonPath('resolutions.0.priere', 'Prier 30 minutes')->assertJsonPath('resolutions.0.jeune', 'Un jour par semaine');
        $this->assertSame(1, VertumetreForm::count());
        $this->assertSame(2, UserNotification::count());
        $this->assertSame(0, OutboundMessage::count());
    }

    // ================================================================ 3. Meditation

    public function test_a_meditation_sheet_is_read_by_her_patriarch_and_the_pastors_never_by_the_assistant_pastor(): void
    {
        $this->as($this->marie);
        $sheet = $this->postJson('/api/me/meditations', $this->meditation())->assertCreated()
            ->assertJsonPath('message', 'Fiche de méditation enregistrée.')->json('sheet');
        $this->assertSame([$sheet['id']], collect($this->getJson('/api/me/meditations')->assertOk()->json('sheets'))->pluck('id')->all());
        $this->getJson("/api/me/meditations/{$sheet['id']}")->assertOk()->assertJsonPath('sheet.prayer', 'Prière personnelle de Marie');
        $this->assertSame(0, UserNotification::count(), 'une fiche de meditation ne previent personne');

        // Patriarche de sa tribu, PA, PR : la liste et la fiche complete, en lecture seule.
        foreach ([$this->patriarch, $this->pa, $this->pr] as $reader) {
            $this->as($reader);
            $this->getJson("/api/admin/members/{$this->marie->id}")->assertOk()->assertJsonPath('can_view_meditations', true);
            $this->assertSame([$sheet['id']], collect($this->getJson("/api/admin/members/{$this->marie->id}/meditations")->assertOk()->json('sheets'))->pluck('id')->all());
            $this->getJson("/api/admin/members/{$this->marie->id}/meditations/{$sheet['id']}")->assertOk()
                ->assertJsonPath('sheet.rhema', 'Parole reçue par Marie')->assertJsonPath('sheet.prayer', 'Prière personnelle de Marie');
        }
        // « Pas l'AP » : l'Assistant Pasteur, le Garde et le responsable de service ne la lisent pas.
        foreach ([$this->ap, $this->garde, $this->choirLeader] as $other) {
            $this->as($other);
            $this->getJson("/api/admin/members/{$this->marie->id}")->assertOk()->assertJsonPath('can_view_meditations', false);
            $this->getJson("/api/admin/members/{$this->marie->id}/meditations")->assertForbidden();
            $this->getJson("/api/admin/members/{$this->marie->id}/meditations/{$sheet['id']}")->assertForbidden();
            $this->getJson("/api/me/meditations/{$sheet['id']}")->assertNotFound(); // jamais par l'espace personnel
        }
        // Patriarche d'une autre tribu : non plus.
        $this->as($this->leviPatriarch);
        $this->getJson("/api/admin/members/{$this->marie->id}/meditations")->assertForbidden();
        $this->assertSame(0, OutboundMessage::count());
    }

    // ================================================================ 4. Demande (contact)

    public function test_a_prayer_request_reaches_the_leaders_who_handle_it_and_the_reply_comes_back_to_marie(): void
    {
        $this->as($this->marie);
        $body = ['category' => 'priere', 'subject' => 'Santé de ma mère', 'message' => 'Merci de prier pour ma mère hospitalisée.'];
        $id = $this->postJson('/api/me/requests', $body)->assertCreated()->assertJsonPath('message', 'Demande envoyée.')
            ->assertJsonPath('request.status', 'nouvelle')->assertJsonPath('request.category_label', 'Demande de prière')->json('request.id');
        $this->postJson('/api/me/requests', $body)->assertOk(); // double appui : pas de doublon, pas de seconde alerte
        $this->assertSame(1, MemberRequest::count());

        // Prevenus : ceux qui traitent les demandes de Marie (Garde, patriarche, AP, PA, PR), en priorite haute.
        // Jamais : les responsables de service (pas requests.handle), le protocole, l'autre tribu.
        $title = 'Nouvelle demande : Demande de prière';
        $this->assertNotifiedExactly($title, [$this->garde, $this->patriarch, $this->ap, $this->pa, $this->pr], 'request');
        $note = $this->noteFor($this->garde, $title);
        $this->assertSame(['high', '/admin/demandes', 'Marie Fidèle · Santé de ma mère'], [$note->priority, $note->url, $note->body]);
        $this->assertSame(5, UserNotification::count());

        // Ecran « Demandes » de chacun : la demande y est (nouvelle) ; le compteur la compte.
        foreach ([$this->garde, $this->patriarch, $this->ap, $this->pa, $this->pr] as $leader) {
            $this->as($leader);
            $item = collect($this->getJson('/api/admin/requests')->assertOk()->json('requests'))->firstWhere('id', $id);
            $this->assertSame(['Marie Fidèle', 'Merci de prier pour ma mère hospitalisée.', 'nouvelle'], [$item['sender'], $item['message'], $item['status']]);
            $this->getJson('/api/admin/requests/new-count')->assertOk()->assertJsonPath('count', 1);
        }
        foreach ([$this->choirLeader, $this->welcomeLeader, $this->protocol] as $other) {
            $this->as($other);
            $this->getJson('/api/admin/requests')->assertForbidden();
            $this->postJson("/api/admin/requests/{$id}/reply", ['reply' => 'Hors portée'])->assertForbidden();
        }
        $this->as($this->leviPatriarch);
        $this->assertNull(collect($this->getJson('/api/admin/requests')->assertOk()->json('requests'))->firstWhere('id', $id));
        $this->getJson('/api/admin/requests/new-count')->assertOk()->assertJsonPath('count', 0);
        $this->postJson("/api/admin/requests/{$id}/reply", ['reply' => 'Hors portée'])->assertForbidden();
        $this->patchJson("/api/admin/requests/{$id}/status", ['status' => 'traitee'])->assertForbidden();

        // Le patriarche repond : Marie est prevenue et lit la reponse dans « Mes demandes ».
        $this->as($this->patriarch);
        $this->postJson("/api/admin/requests/{$id}/reply", ['reply' => 'Nous prions avec vous cette semaine.'])->assertOk()
            ->assertJsonPath('message', 'Réponse envoyée.');
        $this->assertNotifiedExactly('Réponse à votre demande', [$this->marie], 'request_reply');
        $this->as($this->marie);
        $this->getJson('/api/me/requests')->assertOk()->assertJsonPath('requests.0.reply', 'Nous prions avec vous cette semaine.')
            ->assertJsonPath('requests.0.status', 'en_cours')->assertJsonPath('requests.0.replied_at', '2026-11-09');
        $bell = $this->getJson('/api/me/notifications')->assertOk()->json('notifications.0');
        $this->assertSame(['Réponse à votre demande', 'Nous prions avec vous cette semaine.', '/contact'], [$bell['title'], $bell['body'], $bell['url']]);

        // Les autres responsables voient qui a repondu, et qu'elle est en cours.
        $this->as($this->garde);
        $item = collect($this->getJson('/api/admin/requests')->assertOk()->json('requests'))->firstWhere('id', $id);
        $this->assertSame(['en_cours', 'Abraham Juda', 'Abraham Juda', 'Nous prions avec vous cette semaine.'],
            [$item['status'], $item['replied_by'], $item['handler'], $item['reply']]);
        $this->getJson('/api/admin/requests/new-count')->assertOk()->assertJsonPath('count', 0);

        // Demande close : Marie en est informee une fois.
        $this->as($this->patriarch);
        $this->patchJson("/api/admin/requests/{$id}/status", ['status' => 'traitee'])->assertOk();
        $this->patchJson("/api/admin/requests/{$id}/status", ['status' => 'traitee'])->assertOk();
        $this->assertNotifiedExactly('Votre demande a été traitée', [$this->marie], 'request_reply');
        $this->as($this->marie);
        $this->getJson('/api/me/requests')->assertOk()->assertJsonPath('requests.0.status', 'traitee')->assertJsonPath('requests.0.status_label', 'Traitée');
        $this->assertSame(0, OutboundMessage::count(), 'les demandes ne partent pas par courriel');

        // Un pasteur est aussi un membre : le PA envoie sa propre demande. Seul le PR est prevenu et la voit.
        $this->as($this->pa);
        $own = $this->postJson('/api/me/requests', ['category' => 'question', 'message' => 'Question personnelle du PA'])->assertCreated()->json('request.id');
        $this->assertNotifiedExactly('Nouvelle demande : Question', [$this->pr], 'request');
        $this->as($this->pr);
        $this->assertNotNull(collect($this->getJson('/api/admin/requests')->assertOk()->json('requests'))->firstWhere('id', $own));
        foreach ([$this->patriarch, $this->garde] as $other) {
            $this->as($other);
            $this->assertNull(collect($this->getJson('/api/admin/requests')->assertOk()->json('requests'))->firstWhere('id', $own));
        }
        // Regression (constatee le 2026-11-07 pendant l'ecriture de ces parcours : badge 1 et demande listee ; corrigee
        // depuis dans la copie de travail de RequestController) : vue de toute l'eglise, le PA retrouvait sa propre
        // demande dans son ecran « Demandes » et son compteur, et pouvait la traiter lui-meme. Elle va aux autres pasteurs.
        $this->as($this->pa);
        $badge = $this->getJson('/api/admin/requests/new-count')->assertOk()->json('count');
        $listed = collect($this->getJson('/api/admin/requests')->assertOk()->json('requests'))->contains('id', $own);
        $this->assertSame(['badge' => 0, 'listed' => false], ['badge' => $badge, 'listed' => $listed],
            'le PA ne doit pas retrouver sa propre demande parmi les demandes a traiter (ecran et compteur)');
        $this->postJson("/api/admin/requests/{$own}/reply", ['reply' => 'Réponse à moi-même'])->assertForbidden();
        $this->patchJson("/api/admin/requests/{$own}/status", ['status' => 'traitee'])->assertForbidden();
        // Le PR lui repond : le PA recoit la reponse comme tout membre.
        $this->as($this->pr);
        $this->postJson("/api/admin/requests/{$own}/reply", ['reply' => 'Passez me voir mercredi.'])->assertOk();
        $this->assertNotifiedExactly('Réponse à votre demande', [$this->marie, $this->pa], 'request_reply');
        $this->as($this->pa);
        $this->getJson('/api/me/requests')->assertOk()->assertJsonPath('requests.0.reply', 'Passez me voir mercredi.');
    }

    // ================================================================ 5. Inscription a un service

    public function test_joining_a_service_goes_to_its_own_leaders_by_notification_and_email_and_the_welcome_comes_back(): void
    {
        $jean = $this->person('Jean', 'Nouveau', $this->juda);
        $luc = $this->person('Luc', 'Candidat', $this->juda);

        // Ecran « Servir » : Jean (Juda, hors Chorale) demande a rejoindre la Chorale.
        $this->as($jean);
        $card = $this->rowById($this->getJson('/api/me/services')->assertOk()->json('services'), $this->chorale->id);
        $this->assertSame([false, false], [$card['joined'], $card['pending']]);
        $this->assertStringContainsString('Chantal Chantre', (string) $card['leader']);
        $this->postJson("/api/me/services/{$this->chorale->id}/join")->assertOk()->assertJsonPath('status', 'pending')
            ->assertJsonPath('message', 'Demande envoyée : le responsable du service Chorale va la valider.');
        $this->postJson("/api/me/services/{$this->chorale->id}/join")->assertOk()->assertJsonPath('status', 'pending'); // double appui
        $this->assertTrue($this->rowById($this->getJson('/api/me/services')->assertOk()->json('services'), $this->chorale->id)['pending']);
        $request = DepartmentJoinRequest::sole();

        // Les deux responsables de la Chorale : notification (priorite haute) et courriel ; personne d'autre.
        $this->assertNotifiedExactly('Inscription au service Chorale : Jean Nouveau', [$this->choirLeader, $this->choirCoLeader], 'service');
        $this->assertSame('/admin/validations#services', $this->noteFor($this->choirLeader, 'Inscription au service Chorale : Jean Nouveau')->url);
        $this->assertSame(2, UserNotification::count(), 'ni le Garde, ni le patriarche, ni le responsable de l\'Accueil');
        $this->assertMailedExactly('service_request', [$this->choirLeader, $this->choirCoLeader]);
        $mail = OutboundMessage::where('recipient', 'chantal.chantre@exemple.test')->sole();
        $this->assertSame('Inscription au service Chorale : Jean Nouveau', $mail->subject);
        $this->assertSame(['Jean Nouveau', 'Juda', 'Chorale', 'https://espace.exemple.test/admin/validations#services'],
            [$mail->payload['memberName'], $mail->payload['tribe'], $mail->payload['department'], $mail->payload['url']]);
        $this->assertSame(Messenger::PRIORITY_URGENT, (int) $mail->priority);

        // Le responsable d'un AUTRE service (et le patriarche) : ne voit pas la demande, ne peut pas decider.
        $this->as($this->welcomeLeader);
        $this->assertSame([], $this->getJson('/api/admin/validations')->assertOk()->json('services'));
        $this->postJson("/api/admin/validations/services/{$request->id}", ['decision' => 'approved'])->assertForbidden();
        $this->getJson("/api/admin/departments/{$this->chorale->id}/members")->assertForbidden();
        $this->as($this->patriarch);
        $this->assertSame([], $this->getJson('/api/admin/validations')->assertOk()->json('services'));
        $this->postJson("/api/admin/validations/services/{$request->id}", ['decision' => 'approved'])->assertForbidden();

        // La responsable de la Chorale : la demande dans « Validations » ; Jean n'est pas encore dans sa portee.
        $this->as($this->choirLeader);
        $this->getJson("/api/admin/members/{$jean->id}")->assertForbidden();
        $item = $this->getJson('/api/admin/validations')->assertOk()->json('services.0');
        $this->assertSame(['Chorale', 'Jean Nouveau', 'Juda'], [$item['department']['name'], $item['member']['name'], $item['member']['tribe']]);
        $this->postJson("/api/admin/validations/services/{$request->id}", ['decision' => 'approved'])->assertOk()
            ->assertJsonPath('message', 'Inscription validée.');

        // Retour : « Bienvenue au service » pour Jean ; l'autre responsable est informe ; la seconde decision est refusee.
        $this->assertNotifiedExactly('Bienvenue au service Chorale', [$jean], 'service');
        $this->assertNotifiedExactly('Nouveau serviteur : Jean Nouveau', [$this->choirCoLeader], 'service');
        $this->as($this->choirCoLeader);
        $this->assertSame([], $this->getJson('/api/admin/validations')->assertOk()->json('services'));
        $this->postJson("/api/admin/validations/services/{$request->id}", ['decision' => 'rejected'])->assertStatus(409);

        // Jean sert dans la Chorale : son ecran, la liste du departement, la portee de ses responsables.
        $this->as($jean);
        $card = $this->rowById($this->getJson('/api/me/services')->assertOk()->json('services'), $this->chorale->id);
        $this->assertSame([true, false, '2026-11-09'], [$card['joined'], $card['pending'], $card['joined_at']]);
        $this->assertSame('Bienvenue au service Chorale', $this->getJson('/api/me/notifications')->assertOk()->json('notifications.0.title'));
        $this->as($this->choirLeader);
        $this->assertContains($jean->id, collect($this->getJson("/api/admin/departments/{$this->chorale->id}/members")->assertOk()->json('members'))->pluck('user_id'));
        $this->assertContains($jean->id, collect($this->getJson("/api/admin/members?department_id={$this->chorale->id}")->assertOk()->json('members'))->pluck('user_id'));
        $this->getJson("/api/admin/members/{$jean->id}")->assertOk();
        $this->as($this->welcomeLeader);
        $this->getJson("/api/admin/members/{$jean->id}")->assertForbidden();

        // La co-responsable coupe « Recevoir aussi par courriel » dans ses preferences : elle garde la cloche, plus le courriel.
        $this->as($this->choirCoLeader);
        $this->putJson('/api/me/notification-prefs', ['prefs' => ['channel_email' => false]])->assertOk();

        // Refus : Luc demande, la co-responsable refuse, Luc est prevenu (pas de bienvenue, pas de courriel de decision).
        $this->as($luc);
        $this->postJson("/api/me/services/{$this->chorale->id}/join")->assertOk()->assertJsonPath('status', 'pending');
        $lucRequest = DepartmentJoinRequest::where('user_id', $luc->id)->sole();
        $this->assertNotifiedExactly('Inscription au service Chorale : Luc Candidat', [$this->choirLeader, $this->choirCoLeader], 'service');
        $this->assertSame(['chantal.chantre@exemple.test'],
            OutboundMessage::where('dedupe_key', 'like', "service-request:{$lucRequest->id}:%")->pluck('recipient')->all());
        $this->as($this->choirCoLeader);
        $this->postJson("/api/admin/validations/services/{$lucRequest->id}", ['decision' => 'rejected'])->assertOk()->assertJsonPath('message', 'Demande refusée.');
        $this->assertNotifiedExactly('Inscription au service Chorale', [$luc], 'service');
        $this->assertStringContainsString("n'a pas été retenue", $this->noteFor($luc, 'Inscription au service Chorale')->body);
        $this->assertSame(0, UserNotification::where('title', 'Nouveau serviteur : Luc Candidat')->count());
        $this->assertFalse($luc->profile->fresh()->departments()->whereKey($this->chorale->id)->exists());
        $this->assertSame(3, OutboundMessage::count(), 'un courriel par responsable (qui l\'accepte) et par demande, rien a la decision');

        // Marie quitte la Chorale (ecran « Servir ») : ses deux responsables sont prevenus et ne la suivent plus.
        $this->as($this->marie);
        $this->deleteJson("/api/me/services/{$this->chorale->id}")->assertOk()->assertJsonPath('message', 'Vous ne faites plus partie du service Chorale.');
        $this->assertNotifiedExactly('Marie Fidèle a quitté le service Chorale', [$this->choirLeader, $this->choirCoLeader], 'service');
        $this->assertNull($this->noteFor($this->choirLeader, 'Marie Fidèle a quitté le service Chorale')->url, 'pas de lien vers une fiche hors portee');
        $this->assertFalse($this->rowById($this->getJson('/api/me/services')->assertOk()->json('services'), $this->chorale->id)['joined']);
        $this->as($this->choirLeader);
        $this->getJson("/api/admin/members/{$this->marie->id}")->assertForbidden();
        $this->assertNotContains($this->marie->id, collect($this->getJson("/api/admin/departments/{$this->chorale->id}/members")->assertOk()->json('members'))->pluck('user_id'));
        // Ses responsables de tribu et de GEM la suivent toujours.
        $this->as($this->garde);
        $this->getJson("/api/admin/members/{$this->marie->id}")->assertOk();
    }

    // ================================================================ 6. Changement de tribu

    public function test_a_tribe_change_is_validated_by_both_assistant_pastors_and_both_patriarchs_are_informed(): void
    {
        $this->as($this->marie);
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->juda->id, 'reason' => 'Même tribu'])->assertStatus(422);
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->levi->id, 'reason' => 'Je déménage près de ma famille'])->assertCreated()
            ->assertJsonPath('message', 'Demande envoyée : les responsables des deux tribus vont l\'examiner.');
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->levi->id, 'reason' => 'Je déménage près de ma famille'])->assertStatus(409);
        $req = TribeChangeRequest::sole();

        // Valideurs (patriarches sans tribes.transfer en production) : les AP des deux tribus, notification et courriel.
        // Les patriarches des deux tribus sont informes en meme temps (notification et courriel).
        $title = 'Changement de tribu demandé : Marie Fidèle';
        $this->assertNotifiedExactly($title, [$this->ap, $this->leviAp, $this->patriarch, $this->leviPatriarch], 'tribe_change');
        $toAp = $this->noteFor($this->ap, $title);
        $this->assertSame(['high', '/admin/validations#tribus', 'De Juda vers Lévi · Je déménage près de ma famille'], [$toAp->priority, $toAp->url, $toAp->body]);
        $toPatriarch = $this->noteFor($this->patriarch, $title);
        $this->assertSame('De la tribu Juda vers la tribu Lévi. Pour information : la décision revient à Aaron Juda, Samuel Lévi.', $toPatriarch->body);
        $this->assertSame('/admin/membres/'.$this->marie->id, $toPatriarch->url);
        $this->assertNull($this->noteFor($this->leviPatriarch, $title)->url, 'Marie n\'est pas encore dans sa tribu : pas de lien vers sa fiche');
        $this->assertSame(4, UserNotification::count(), 'ni le Garde, ni le PA, ni le PR, ni Marie');
        $this->assertMailedExactly('tribe_change_request', [$this->ap, $this->leviAp]);
        $this->assertMailedExactly('tribe_change_info', [$this->patriarch, $this->leviPatriarch]);
        $this->assertSame('https://espace.exemple.test/admin/validations#tribus',
            OutboundMessage::where('kind', 'tribe_change_request')->where('user_id', $this->ap->id)->sole()->payload['url']);

        // Patriarche (sans tribes.transfer) et Garde : rien a valider, decision refusee.
        foreach ([$this->patriarch, $this->garde] as $other) {
            $this->as($other);
            $this->assertSame([], $this->getJson('/api/admin/validations')->assertOk()->json('tribes'));
            $this->postJson("/api/admin/validations/tribe/{$req->id}", ['decision' => 'approved'])->assertForbidden();
        }

        // AP de Juda : le cote « depart ».
        $this->as($this->ap);
        $item = $this->getJson('/api/admin/validations')->assertOk()->json('tribes.0');
        $this->assertSame([['from'], ['from', 'to'], 'Juda', 'Lévi', 'Je déménage près de ma famille'],
            [$item['my_sides'], $item['required_sides'], $item['from'], $item['to'], $item['reason']]);
        $this->postJson("/api/admin/validations/tribe/{$req->id}", ['decision' => 'approved'])->assertOk()
            ->assertJsonPath('message', "Approbation enregistrée : en attente de l'autre tribu.");
        $this->postJson("/api/admin/validations/tribe/{$req->id}", ['decision' => 'approved'])->assertStatus(409);
        $this->assertNotifiedExactly('Changement de tribu : une étape validée', [$this->marie], 'tribe_change');
        $this->assertSame('Aaron Juda a validé votre demande pour la tribu Juda. Il reste la validation des responsables de la tribu Lévi.',
            $this->noteFor($this->marie, 'Changement de tribu : une étape validée')->body);
        $this->assertMailedExactly('tribe_change_step', [$this->marie]);

        // Marie suit sa demande : une etape validee, toujours dans Juda.
        $this->as($this->marie);
        $mine = $this->getJson('/api/me/tribe-change')->assertOk()->json('requests.0');
        $this->assertSame(['pending', ['from'], 'Aaron Juda'], [$mine['status'], $mine['approved_sides'], $mine['approvals'][0]['by']]);
        $this->assertSame($this->juda->id, (int) $this->marie->profile->fresh()->tribe_id);

        // AP de Levi : le cote « arrivee » ; le changement est applique.
        $this->as($this->leviAp);
        $this->assertSame(['to'], $this->getJson('/api/admin/validations')->assertOk()->json('tribes.0.my_sides'));
        $this->postJson("/api/admin/validations/tribe/{$req->id}", ['decision' => 'approved'])->assertOk()
            ->assertJsonPath('message', 'Changement de tribu effectué.');
        $profile = $this->marie->profile->fresh();
        $this->assertSame([$this->levi->id, null], [(int) $profile->tribe_id, $profile->gem_id], 'Marie quitte son GEM de Juda');
        $this->assertSame('approved', $req->fresh()->status);

        // Retours : Marie (bienvenue, courriel), patriarche d'arrivee (nouveau membre), patriarche de depart (informe).
        $this->assertNotifiedExactly('Bienvenue dans la tribu Lévi !', [$this->marie], 'tribe_change');
        $this->assertMailedExactly('tribe_change_approved', [$this->marie]);
        $this->assertNotifiedExactly('Nouveau membre dans la tribu : Marie Fidèle', [$this->leviPatriarch], 'member');
        $this->assertNotifiedExactly('Changement de tribu validé : Marie Fidèle', [$this->patriarch], 'tribe_change');
        $approvedInfo = OutboundMessage::where('kind', 'tribe_change_info')->where('dedupe_key', 'like', "tribe-change-info:{$req->id}:approved:%")
            ->pluck('recipient')->sort()->values()->all();
        $this->assertSame(['abraham.juda@exemple.test', 'laban.levi@exemple.test'], $approvedInfo);
        $this->as($this->marie);
        $this->getJson('/api/me/tribe-change')->assertOk()->assertJsonPath('requests.0.status', 'approved');
        $this->assertSame('Bienvenue dans la tribu Lévi !', $this->getJson('/api/me/notifications')->assertOk()->json('notifications.0.title'));

        // Visibilite apres le changement : Garde et patriarche de Juda la perdent ; le patriarche de Levi la suit.
        $this->as($this->garde);
        $this->getJson("/api/admin/members/{$this->marie->id}")->assertForbidden();
        $this->as($this->patriarch);
        $this->getJson("/api/admin/members/{$this->marie->id}")->assertForbidden();
        $this->getJson("/api/admin/members/{$this->marie->id}/fiss")->assertForbidden();
        $this->as($this->leviPatriarch);
        $this->getJson("/api/admin/members/{$this->marie->id}")->assertOk()->assertJsonPath('profile.tribe.name', 'Lévi')->assertJsonPath('can_view_fiss', true);
        // Son service ne change pas : la responsable de la Chorale la suit toujours.
        $this->as($this->choirLeader);
        $this->getJson("/api/admin/members/{$this->marie->id}")->assertOk();
        // Sa prochaine demande de modification de FISS va au patriarche de Levi.
        SpiritualHealthForm::create(['user_id' => $this->marie->id, 'period' => '2026-11', 'meditation' => 10, 'submitted_at' => now(), 'locked_at' => now()]);
        $this->as($this->marie);
        $formId = SpiritualHealthForm::where('user_id', $this->marie->id)->value('id');
        $this->postJson("/api/me/fiss/{$formId}/edit-requests", ['reason' => 'Correction après déménagement'])->assertCreated();
        $this->assertNotifiedExactly('Demande de modification de FISS : Marie Fidèle', [$this->leviPatriarch], 'fiss_request');

        // Nouvelle demande (retour vers Juda) puis annulation par Marie : plus rien a valider, decision refusee.
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->juda->id, 'reason' => 'Retour envisagé'])->assertCreated();
        $back = TribeChangeRequest::where('status', 'pending')->sole();
        $this->assertSame([$this->levi->id, $this->juda->id], [(int) $back->from_tribe_id, (int) $back->to_tribe_id]);
        $this->deleteJson("/api/me/tribe-change/{$back->id}")->assertOk()->assertJsonPath('message', 'Demande annulée.');
        foreach ([$this->ap, $this->leviAp] as $validator) {
            $this->as($validator);
            $this->assertSame([], $this->getJson('/api/admin/validations')->assertOk()->json('tribes'));
            $this->postJson("/api/admin/validations/tribe/{$back->id}", ['decision' => 'approved'])->assertStatus(409);
        }
        $this->assertSame($this->levi->id, (int) $this->marie->profile->fresh()->tribe_id);
    }

    // ================================================================ 7. Rendez-vous avec le pasteur

    public function test_an_appointment_reaches_the_pastor_and_the_protocol_and_every_change_comes_back_to_marie(): void
    {
        // Ecran « Rendez-vous » : seance du mercredi soir, prise aussitot confirmee.
        $this->as($this->marie);
        $page = $this->getJson('/api/me/appointments')->assertOk()->assertJsonPath('host_id', $this->pr->id)->assertJsonPath('accepting', true);
        $this->assertSame('21h50 – 23h20', collect($page->json('sessions'))->firstWhere('date', self::WED)['range']);
        $res = $this->postJson('/api/me/appointments', ['host_id' => $this->pr->id, 'mode' => 'session', 'date' => self::WED,
            'urgency' => 'important', 'message' => 'Conseil pour notre couple'])->assertCreated()
            ->assertJsonPath('appointment.status', 'confirmed')->assertJsonPath('appointment.when', 'mercredi 11 novembre à 21h50')
            ->assertJsonPath('message', 'Rendez-vous confirmé : mercredi 11 novembre à 21h50.');
        $id = $res->json('appointment.id');
        $this->postJson('/api/me/appointments', ['host_id' => $this->pr->id, 'mode' => 'first'])->assertStatus(409); // un seul a la fois
        $this->getJson('/api/me/appointments')->assertOk()->assertJsonPath('active.id', $id)
            ->assertJsonPath('active.message', 'Conseil pour notre couple')->assertJsonPath('active.host', 'Pierre Résident');

        // Le pasteur et le protocole sont prevenus ; personne d'autre (ni ses responsables, ni le PA).
        $this->assertNotifiedExactly('Nouveau rendez-vous : Marie Fidèle', [$this->pr, $this->protocol], 'appointment');
        $this->assertSame('Mercredi 11 novembre à 21h50 · Importante', $this->noteFor($this->pr, 'Nouveau rendez-vous : Marie Fidèle')->body);
        $this->assertSame(2, UserNotification::count());

        // Le pasteur lit le sujet ; le protocole voit le nom et le telephone, jamais le sujet.
        $this->as($this->pr);
        $slot = $this->slot($this->getJson('/api/admin/appointments')->assertOk()->json('sessions'), self::WED, '21:50');
        $this->assertSame(['Marie Fidèle', 'Conseil pour notre couple'], [$slot['appointment']['member']['name'], $slot['appointment']['message']]);
        $this->as($this->protocol);
        $list = $this->getJson('/api/admin/appointments')->assertOk();
        $slot = $this->slot($list->json('sessions'), self::WED, '21:50');
        $this->assertSame(['Marie Fidèle', $this->marie->phone, null, false],
            [$slot['appointment']['member']['name'], $slot['appointment']['member']['phone'], $slot['appointment']['message'], $slot['appointment']['has_message']]);
        $this->assertStringNotContainsString('Conseil pour notre couple', $list->getContent());
        // Ni le patriarche, ni l'AP, ni le PA, ni le Garde n'organisent les rendez-vous du pasteur.
        foreach ([$this->patriarch, $this->ap, $this->pa, $this->garde] as $other) {
            $this->as($other);
            $this->getJson('/api/admin/appointments')->assertForbidden();
            $this->postJson("/api/admin/appointments/{$id}/assign", ['starts_at' => self::WED.' 22:20'])->assertForbidden();
        }

        // Le pasteur deplace le rendez-vous : Marie est prevenue (priorite haute) et voit la nouvelle heure.
        $this->as($this->pr);
        $this->postJson("/api/admin/appointments/{$id}/assign", ['starts_at' => self::WED.' 22:20'])->assertOk()
            ->assertJsonPath('message', 'Rendez-vous déplacé : le membre est prévenu.');
        $this->assertNotifiedExactly('Rendez-vous avec le pasteur déplacé', [$this->marie], 'appointment');
        $moved = $this->noteFor($this->marie, 'Rendez-vous avec le pasteur déplacé');
        $this->assertSame(['high', 'Mercredi 11 novembre à 22h20.', '/rendez-vous'], [$moved->priority, $moved->body, $moved->url]);
        $this->as($this->marie);
        $this->getJson('/api/me/appointments')->assertOk()->assertJsonPath('active.time', '22h20');

        // 15 minutes avant la seance : la liste du soir part par courriel au pasteur (sujet compris).
        Carbon::setTestNow(Carbon::parse(self::WED.' 21:36:00'));
        AppointmentService::mailDigests();
        AppointmentService::mailDigests();
        $digest = OutboundMessage::where('kind', 'appointments_digest')->sole();
        $this->assertSame(['pierre.resident@exemple.test', 'Vos rendez-vous de ce soir · mercredi 11 novembre (1)'], [$digest->recipient, $digest->subject]);
        $this->assertSame([['22h20', 'Marie Fidèle', 'Juda', 'Conseil pour notre couple']],
            array_map(fn ($i) => [$i['time'], $i['name'], $i['tribe'], $i['message']], $digest->payload['items']));
        $this->assertSame(1, OutboundMessage::count(), 'aucun courriel au protocole ni a Marie');

        // Son tour : le protocole la fait entrer (« C'est votre tour »), puis note le rendez-vous termine.
        Carbon::setTestNow(Carbon::parse(self::WED.' 22:19:00'));
        $this->as($this->protocol);
        $this->postJson("/api/admin/appointments/{$id}/call")->assertOk()->assertJsonPath('message', 'Marie Fidèle est appelé(e).');
        $this->assertNotifiedExactly("C'est votre tour", [$this->marie], 'appointment');
        Carbon::setTestNow(Carbon::parse(self::WED.' 22:36:00'));
        $this->postJson("/api/admin/appointments/{$id}/outcome", ['status' => 'done'])->assertOk()->assertJsonPath('message', 'Terminé.');
        $this->assertSame('done', Appointment::find($id)->status);
        $this->as($this->marie);
        $this->getJson('/api/me/appointments')->assertOk()->assertJsonPath('active', null)->assertJsonPath('history.0.status', 'done');

        // Nouveau rendez-vous puis annulation par Marie : le pasteur et le protocole sont prevenus, le creneau est libere.
        $again = $this->postJson('/api/me/appointments', ['host_id' => $this->pr->id, 'mode' => 'first'])->assertCreated()->json('appointment.id');
        $this->postJson("/api/me/appointments/{$again}/cancel")->assertOk()->assertJsonPath('message', 'Rendez-vous annulé.');
        $this->assertNotifiedExactly('Rendez-vous annulé : Marie Fidèle', [$this->pr, $this->protocol], 'appointment');
        $this->assertSame(['cancelled', null], [Appointment::find($again)->status, Appointment::find($again)->slot_key]);
        $this->as($this->garde);
        $this->postJson("/api/me/appointments/{$id}/cancel")->assertNotFound(); // le rendez-vous d'un autre
    }

    // ================================================================ 8. Profil : famille

    public function test_a_spouse_designation_is_confirmed_by_the_spouse_and_leaders_see_the_family_on_the_member_page(): void
    {
        $joseph = $this->person('Joseph', 'Époux', $this->levi);

        // Profil > Famille : Marie cherche son conjoint (sans coordonnees) et le designe.
        $this->as($this->marie);
        $found = $this->getJson('/api/me/family/search?q=Joseph')->assertOk()->json('results');
        $this->assertSame([$joseph->id], array_column($found, 'user_id'));
        $this->assertSame(['Joseph Époux', 'Lévi'], [$found[0]['full_name'], $found[0]['tribe']]);
        $this->assertArrayNotHasKey('phone', $found[0]);
        $this->assertArrayNotHasKey('email', $found[0]);
        $this->putJson('/api/me/family/spouse', ['user_id' => $joseph->id])->assertOk()
            ->assertJsonPath('message', 'Conjoint(e) indiqué(e) : en attente de sa confirmation.')
            ->assertJsonPath('family.spouse.name', 'Joseph Époux')->assertJsonPath('family.spouse.status', 'pending');
        $this->assertNotifiedExactly('Marie Fidèle vous a indiqué comme conjoint(e)', [$joseph], 'family');
        $this->assertSame(1, UserNotification::count());

        // Avant confirmation : le patriarche voit le lien « en attente » ; un tiers ne peut pas le confirmer.
        $this->as($this->patriarch);
        $this->getJson("/api/admin/members/{$this->marie->id}")->assertOk()->assertJsonPath('family.spouse.status', 'pending');
        $link = FamilyLink::where('user_id', $this->marie->id)->sole();
        $this->as($this->garde);
        $this->postJson("/api/me/family/links/{$link->id}/confirm")->assertNotFound();

        // Joseph voit la demande dans son profil et confirme : Marie est prevenue, le lien existe des deux cotes.
        $this->as($joseph);
        $incoming = $this->getJson('/api/me/family')->assertOk()->json('incoming');
        $this->assertSame([[$link->id, 'spouse', 'Marie Fidèle', $this->marie->id]],
            array_map(fn ($i) => [$i['id'], $i['relation'], $i['from'], $i['from_user_id']], $incoming));
        $this->postJson("/api/me/family/links/{$link->id}/confirm")->assertOk()->assertJsonPath('message', 'Lien familial confirmé.')
            ->assertJsonPath('family.spouse.name', 'Marie Fidèle')->assertJsonPath('family.spouse.status', 'confirmed');
        $this->postJson("/api/me/family/links/{$link->id}/confirm")->assertNotFound();
        $this->assertNotifiedExactly('Lien familial confirmé', [$this->marie], 'family');
        $this->assertSame('Joseph Époux a confirmé le lien.', $this->noteFor($this->marie, 'Lien familial confirmé')->body);
        $this->assertSame(2, FamilyLink::where('relation', 'spouse')->where('status', 'confirmed')->count());
        $this->assertSame(['marie', 'Joseph Époux'], [$this->marie->profile->fresh()->marital_status, $this->marie->profile->fresh()->spouse_name]);
        $this->assertSame(['marie', 'Marie Fidèle'], [$joseph->profile->fresh()->marital_status, $joseph->profile->fresh()->spouse_name]);
        $this->as($this->marie);
        $this->getJson('/api/me/family')->assertOk()->assertJsonPath('spouse.status', 'confirmed')->assertJsonPath('spouse.tribe', 'Lévi')
            ->assertJsonPath('incoming', []);

        // Fiche du membre chez les responsables : la famille, un lien vers la fiche du conjoint seulement s'il s'ouvrira.
        foreach ([[$this->patriarch, $this->marie, 'Joseph Époux', false], [$this->garde, $this->marie, 'Joseph Époux', false],
            [$this->pr, $this->marie, 'Joseph Époux', true], [$this->leviPatriarch, $joseph, 'Marie Fidèle', false]] as [$viewer, $member, $spouse, $canOpen]) {
            $this->as($viewer);
            $family = $this->getJson("/api/admin/members/{$member->id}")->assertOk()->json('family');
            $this->assertSame([$spouse, 'confirmed', $canOpen], [$family['spouse']['name'], $family['spouse']['status'], $family['spouse']['can_open']],
                $this->names[$viewer->id].' sur la fiche de '.$this->names[$member->id]);
            $this->assertArrayNotHasKey('incoming', $family);
            $this->assertArrayNotHasKey('suggestions', $family);
        }

        // Enfants : l'un inscrit (lien a confirmer par lui), l'autre sans compte. Timothee refuse : Marie est prevenue.
        $timothee = $this->person('Timothée', 'Fidèle', $this->juda);
        $this->as($this->marie);
        $this->putJson('/api/me/family/children', ['has_children' => true, 'children' => [
            ['name' => 'Timothée Fidèle', 'user_id' => $timothee->id, 'birth_date' => '2008-05-04'],
            ['name' => 'Anne Fidèle', 'birth_date' => '2015-02-01'],
        ]])->assertOk()->assertJsonPath('message', 'Enfants enregistrés.');
        $this->assertNotifiedExactly('Marie Fidèle vous a indiqué comme son enfant', [$timothee], 'family');
        $this->as($timothee);
        $childLink = $this->getJson('/api/me/family')->assertOk()->json('incoming.0');
        $this->assertSame(['child', 'Marie Fidèle'], [$childLink['relation'], $childLink['from']]);
        $this->postJson("/api/me/family/links/{$childLink['id']}/decline")->assertOk()->assertJsonPath('message', 'Lien refusé.');
        $this->assertNotifiedExactly('Lien familial non confirmé', [$this->marie], 'family');
        $this->as($this->patriarch);
        $children = $this->getJson("/api/admin/members/{$this->marie->id}")->assertOk()->json('family.children');
        $this->assertEqualsCanonicalizing([['Timothée Fidèle', 'declined', true], ['Anne Fidèle', 'confirmed', false]],
            array_map(fn ($c) => [$c['name'], $c['status'], $c['can_open']], $children));

        // Effet sur la FISS : mariee, Marie doit maintenant repondre a la situation conjugale.
        $this->as($this->marie);
        $this->postJson('/api/me/fiss', $this->fiss())->assertStatus(422)->assertJsonValidationErrors(['situation_conjugale']);
        $this->postJson('/api/me/fiss', $this->fiss(['situation_conjugale' => 15]))->assertCreated()->assertJsonPath('current.situation_conjugale', 15);
        $this->assertSame(0, OutboundMessage::count());
    }

    // ================================================================ Outils

    /** Roles tels qu'en production (7 novembre 2026), comme DataExtractionSweepTest, et patriarche sans tribes.transfer. */
    private function productionRoles(): void
    {
        $set = function (string $key, array $perms, string $scope = 'none', int $rank = 30) {
            $role = Role::firstOrCreate(['key' => $key], ['name' => $key, 'scope_kind' => $scope, 'rank' => $rank, 'is_system' => true]);
            $role->permissions()->sync(Permission::whereIn('key', $perms)->pluck('id'));
        };
        $set('administrateur', ['accounts.view', 'departments.approve', 'departments.manage', 'exercises.respond', 'roles.assign', 'roles.manage'], 'none', 80);
        $set('responsable_departement', ['announcements.delete', 'announcements.publish', 'departments.manage', 'events.delete', 'events.manage',
            'events.participants', 'members.edit', 'members.export', 'members.view_scope', 'spiritual.view'], 'department', 30);
        $set('protocole', ['appointments.organize', 'exercises.respond'], 'none', 30);
        $set('communication', ['announcements.delete', 'announcements.publish', 'broadcast.all', 'events.delete', 'events.manage',
            'events.participants', 'exercises.respond'], 'none', 45);
        Role::where('key', 'patriarche')->firstOrFail()->permissions()->detach(Permission::where('key', 'tribes.transfer')->value('id'));
    }

    private function person(string $first, string $last, ?Tribe $tribe, ?Gem $gem = null, string $gender = 'homme'): User
    {
        $n = ++$this->n;
        $user = User::create(['phone' => '+1418557'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => $gender,
            'email' => Str::lower(Str::ascii($first)).'.'.Str::lower(Str::ascii($last)).'@exemple.test',
            'is_completed' => true, 'tribe_id' => $tribe?->id, 'gem_id' => $gem?->id, 'matricule' => 'VHC'.(7000 + $n)]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));
        $this->names[$user->id] = "{$first} {$last}";

        return $user;
    }

    private function give(User $user, string $key, ?string $kind = null, ?int $id = null): User
    {
        $user->roles()->attach(Role::where('key', $key)->value('id'), ['scope_kind' => $kind, 'scope_id' => $id]);

        return $user->fresh();
    }

    /** Responsable d'un departement : role sur ce departement et nomination dans Organisation. */
    private function leader(User $user, Department $department): User
    {
        $this->give($user, DepartmentLeadership::ROLE, 'department', $department->id);
        DepartmentLeadership::appoint($department, $user->id, $this->pr->id);

        return $user->fresh();
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user->fresh());
    }

    /**
     * Destinataires exacts d'une notification (titre, et type si donne) : chacun une fois, personne d'autre.
     *
     * @param  array<int, User>  $users
     */
    private function assertNotifiedExactly(string $title, array $users, ?string $type = null): void
    {
        $got = UserNotification::where('title', $title)->when($type, fn ($q) => $q->where('type', $type))->pluck('user_id')
            ->map(fn ($id) => $this->names[(int) $id] ?? "#{$id}")->sort()->values()->all();
        $want = collect($users)->map(fn (User $u) => $this->names[$u->id])->sort()->values()->all();
        $this->assertSame($want, $got, "Destinataires de la notification « {$title} »");
    }

    /**
     * Destinataires exacts des courriels d'un type (file d'envoi) : chacun une fois.
     *
     * @param  array<int, User>  $users
     */
    private function assertMailedExactly(string $kind, array $users): void
    {
        $got = OutboundMessage::where('kind', $kind)->where('channel', 'email')->pluck('recipient')->sort()->values()->all();
        $want = collect($users)->map(fn (User $u) => Messenger::emailOf($u->fresh()->load('profile')))->sort()->values()->all();
        $this->assertSame($want, $got, "Destinataires des courriels « {$kind} »");
    }

    private function noteFor(User $user, string $title): UserNotification
    {
        return UserNotification::where('user_id', $user->id)->where('title', $title)->sole();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function rowOf(array $rows, User $user): ?array
    {
        return collect($rows)->firstWhere('user_id', $user->id);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function rowById(array $rows, int $id): ?array
    {
        return collect($rows)->firstWhere('id', $id);
    }

    /** @param array<int, array<string, mixed>> $sessions */
    private function slot(array $sessions, string $date, string $start): array
    {
        $session = collect($sessions)->firstWhere('date', $date);
        $this->assertNotNull($session, "seance du {$date}");

        return collect($session['slots'])->firstWhere('start', $start);
    }

    /** @return array<string, mixed> */
    private function fiss(array $overrides = []): array
    {
        return $overrides + ['meditation' => 14, 'priere' => 12, 'jeune' => 10, 'sanctification_corps' => 'bien', 'sanctification_ame' => 'bien',
            'sanctification_esprit' => 'moyen', 'situation_financiere' => 12, 'situation_familiale' => 14, 'comment' => 'Mois difficile',
            'addiction' => 'Aucune', 'addiction_victory' => 'Non', 'companions' => 'Oui', 'inner_wound' => 'Blessure confiée au patriarche',
            'difficult_situations' => 'Aucune', 'discouragement' => 'Non'];
    }

    /** @return array<string, mixed> */
    private function vertumetre(array $overrides = []): array
    {
        return $overrides + [
            'priere_frequence' => '2x', 'priere_duree' => '15-30', 'jeune_fois' => '1x',
            'priere_sujets' => ['famille', 'sante'], 'soutien_priere' => 'Mon travail',
            'meditation_frequence' => '1x', 'meditation_duree' => '0-15', 'lecture_volume' => 'chapitre',
            'plan_lecture' => 'annee', 'plan_lecture_precision' => 'Plan annuel', 'enseignements' => 'Série sur la foi',
            'temoignage_sujets' => ['famille'], 'temoignage_texte' => 'Réconciliation avec mon frère',
            'priorites' => 'en_partie', 'parole_tenue' => 'pleinement', 'priere_avant' => 'pas_du_tout',
            'pardon' => 'en_partie', 'irritation' => 'parfois', 'dieu_dabord' => 'na',
        ];
    }

    /** Textes neutres (aucun verset ni priere inventes). @return array<string, string> */
    private function meditation(): array
    {
        return [
            'verse' => 'Référence choisie par Marie',
            'observation' => 'Observation de Marie',
            'rhema' => 'Parole reçue par Marie',
            'action' => 'Action du jour de Marie',
            'prayer' => 'Prière personnelle de Marie',
        ];
    }
}
