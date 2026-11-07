<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Evaluation;
use App\Models\Gem;
use App\Models\MeditationSheet;
use App\Models\MemberRequest;
use App\Models\MonthlyResolution;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualEntry;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Models\VertumetreForm;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tentative d'extraction systematique (audit du 7 novembre 2026) : des secrets uniques sont plantes dans les donnees
 * confidentielles d'un membre (FISS, suivi spirituel, notes, demandes, meditation, RM, Vertumetre, rendez-vous,
 * telephone, courriel). Puis CHAQUE adresse de lecture de l'API est appelee par des comptes qui ne suivent PAS ce
 * membre (avec les vrais droits de production, roles cumules compris) : aucun secret ne doit jamais sortir. Sans
 * session, toute adresse non publique repond 401, et la liste des adresses publiques est fixee ici.
 */
class DataExtractionSweepTest extends TestCase
{
    use RefreshDatabase;

    /** Adresses ouvertes sans session (methode + chemin) : toute nouvelle adresse publique doit etre ajoutee ici. */
    private const PUBLIC_ROUTES = [
        'POST api/auth/request-otp', 'POST api/auth/verify-otp', 'POST api/auth/manager-activation',
        'GET api/health', 'GET api/appearance', 'POST api/monitor/client-errors', 'POST api/push/receipt',
        // Jeton secret dans l'adresse (flux d'agenda) et liens signes des courriels du pasteur (signature verifiee).
        'GET api/calendar/feed/{token}.ics',
        'GET api/email-actions/deletion/{deletionRequest}/{decision}/{pastor}', 'POST api/email-actions/deletion/{deletionRequest}/{decision}/{pastor}',
        'GET api/email-actions/membership/{member}/{decision}/{pastor}', 'POST api/email-actions/membership/{member}/{decision}/{pastor}',
    ];

    private const SECRETS = [
        'SECRET-FISS-1', 'SECRET-SUIVI-2', 'SECRET-NOTE-3', 'SECRET-DEMANDE-4', 'SECRET-MEDITATION-5',
        'SECRET-RM-6', 'SECRET-VERTU-7', 'SECRET-RDV-8', '+14185557777', 'victime@secret.test',
    ];

    private int $n = 0;

    /** @var array<string, int> identifiants a mettre dans les adresses ({user}, {request}...) */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(Tribe $tribe, ?Gem $gem = null, array $extra = []): User
    {
        $phone = $extra['phone'] ?? '+14185556'.str_pad((string) ++$this->n, 3, '0', STR_PAD_LEFT);
        unset($extra['phone']);
        $user = User::create(['phone' => $phone, 'last_login_at' => now()]);
        Profile::create($extra + ['user_id' => $user->id, 'first_name' => 'P'.$this->n, 'last_name' => 'T', 'gender' => 'homme',
            'is_completed' => true, 'tribe_id' => $tribe->id, 'gem_id' => $gem?->id]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $key, ?string $kind = null, ?int $id = null): User
    {
        $user->roles()->attach(Role::where('key', $key)->value('id'), ['scope_kind' => $kind, 'scope_id' => $id]);

        return User::find($user->id);
    }

    /** Roles tels qu'en production (7 novembre 2026). */
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
    }

    public function test_no_confidential_secret_of_a_member_leaks_to_accounts_that_do_not_follow_him(): void
    {
        $this->productionRoles();
        $juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $bethel = Gem::create(['name' => 'Béthel', 'tribe_id' => $juda->id, 'is_active' => true]);
        $silo = Gem::create(['name' => 'Silo', 'tribe_id' => $levi->id, 'is_active' => true]);
        $choir = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        $kitchen = Department::create(['name' => 'Cuisine', 'slug' => 'cuisine', 'is_active' => true]);

        // La victime : tribu Levi, GEM Silo, service Cuisine, et ses donnees confidentielles marquees.
        $victim = $this->member($levi, $silo, ['phone' => '+14185557777', 'email' => 'victime@secret.test']);
        $victim->profile->departments()->attach($kitchen->id);
        $author = $this->give($this->member($levi), 'patriarche', 'tribe', $levi->id);
        $fiss = SpiritualHealthForm::create(['user_id' => $victim->id, 'period' => '2026-10', 'meditation' => 10, 'priere' => 10, 'jeune' => 10,
            'comment' => 'SECRET-FISS-1', 'inner_wound' => 'SECRET-FISS-1']);
        $entry = SpiritualEntry::create(['member_user_id' => $victim->id, 'author_user_id' => $author->id, 'type' => 'note', 'entry_date' => now(), 'note' => 'SECRET-SUIVI-2']);
        $evaluation = Evaluation::create(['user_id' => $victim->id, 'type' => 'autre', 'score' => 12, 'max_score' => 20, 'evaluated_on' => now(),
            'comment' => 'SECRET-NOTE-3', 'created_by' => $author->id]);
        $request = MemberRequest::create(['user_id' => $victim->id, 'category' => 'priere', 'subject' => 'Prière', 'message' => 'SECRET-DEMANDE-4', 'status' => 'new']);
        $sheet = MeditationSheet::create(['user_id' => $victim->id, 'verse' => 'Jean 3', 'observation' => 'o', 'rhema' => 'r', 'action' => 'a',
            'prayer' => 'SECRET-MEDITATION-5', 'submitted_at' => now()]);
        $rm = MonthlyResolution::create(['user_id' => $victim->id, 'period' => '2026-11', 'autres' => 'SECRET-RM-6']);
        $vertu = VertumetreForm::create(['user_id' => $victim->id, 'period' => '2026-10', 'answers' => ['note' => 'SECRET-VERTU-7'], 'submitted_at' => now()]);
        $pastor = $this->give($this->member($juda), 'super_admin');
        $appointment = Appointment::create(['host_id' => $pastor->id, 'user_id' => $victim->id, 'status' => 'pending', 'urgency' => 'normal',
            'preference' => 'any', 'message' => 'SECRET-RDV-8']);

        $this->ids = [
            'user' => $victim->id, 'member' => $victim->id, 'tribe' => $levi->id, 'gem' => $silo->id, 'department' => $kitchen->id,
            'request' => $request->id, 'memberRequest' => $request->id, 'sheet' => $sheet->id, 'meditationSheet' => $sheet->id,
            'evaluation' => $evaluation->id, 'entry' => $entry->id, 'form' => $fiss->id, 'fissForm' => $fiss->id,
            'appointment' => $appointment->id, 'resolution' => $rm->id, 'vertumetre' => $vertu->id,
        ];

        // Les « attaquants » : aucun ne suit la victime (autre tribu, autre GEM, autre service), roles cumules compris.
        $attackers = [
            'fidele' => $this->member($juda),
            'garde' => $this->give($this->member($juda, $bethel), 'garde', 'gem', $bethel->id),
            'patriarche' => $this->give($this->member($juda), 'patriarche', 'tribe', $juda->id),
            'ap' => $this->give($this->member($juda), 'assistant_pasteur', 'tribe', $juda->id),
            'responsable de service' => $this->give($this->member($juda), 'responsable_departement', 'department', $choir->id),
            'patriarche + service' => $this->give($this->give($this->member($juda), 'patriarche', 'tribe', $juda->id), 'responsable_departement', 'department', $choir->id),
            'garde + service' => $this->give($this->give($this->member($juda, $bethel), 'garde', 'gem', $bethel->id), 'responsable_departement', 'department', $choir->id),
            'communication' => $this->give($this->member($juda), 'communication'),
            'administrateur' => $this->give($this->member($juda), 'administrateur'),
        ];
        $choir->leaders()->attach([$attackers['responsable de service']->id, $attackers['patriarche + service']->id, $attackers['garde + service']->id]);

        $routes = $this->readRoutes();
        $this->assertGreaterThan(80, count($routes), 'toutes les adresses de lecture sont essayees');
        $leaks = [];
        foreach ($attackers as $label => $attacker) {
            Sanctum::actingAs($attacker);
            foreach ($routes as $uri) {
                $response = $this->getJson($uri);
                if ($response->getStatusCode() >= 500) {
                    $leaks[] = "{$label} : {$uri} -> erreur {$response->getStatusCode()}";
                }
                if ($response->getStatusCode() !== 200) {
                    continue;
                }
                $body = $response->getContent();
                foreach (self::SECRETS as $secret) {
                    if (str_contains($body, $secret) || str_contains($body, json_encode($secret))) {
                        $leaks[] = "{$label} : {$uri} laisse sortir {$secret}";
                    }
                }
            }
        }
        $this->assertSame([], $leaks, "Fuites :\n".implode("\n", $leaks));

        // Temoin : le test sait reconnaitre un secret. Le patriarche de la victime, lui, voit bien son suivi et sa FISS.
        Sanctum::actingAs($author);
        $this->assertStringContainsString('SECRET-SUIVI-2', $this->getJson("/api/admin/members/{$victim->id}/spiritual")->assertOk()->getContent());
        $this->assertStringContainsString('SECRET-FISS-1', $this->getJson("/api/admin/members/{$victim->id}/fiss")->assertOk()->getContent());
    }

    public function test_without_a_session_every_non_public_address_refuses(): void
    {
        $public = [];
        $problems = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'api/agent') || str_starts_with($route->uri(), 'api/email/')) {
                continue; // console signee (HMAC) et liens signes des courriels : verifies par leurs propres tests
            }
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $key = "{$method} {$route->uri()}";
            $guarded = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth:sanctum'));
            if (! $guarded) {
                $public[] = $key;

                continue;
            }
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $status = $this->json($method, $uri)->getStatusCode();
            if ($status !== 401) {
                $problems[] = "{$key} sans session -> {$status}";
            }
        }
        $this->assertSame([], $problems);
        $this->assertSame([], array_values(array_diff($public, self::PUBLIC_ROUTES)), 'nouvelle adresse publique : a verifier puis a ajouter a PUBLIC_ROUTES');
    }

    /** @return array<int, string> adresses GET de l'API, identifiants remplaces par ceux de la victime */
    private function readRoutes(): array
    {
        $uris = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'api/')
                || str_starts_with($uri, 'api/agent') || str_starts_with($uri, 'api/email/') || str_contains($uri, '.ics')) {
                continue;
            }
            $uris[] = '/'.preg_replace_callback('/\{([^}?]+)\??\}/', fn ($m) => (string) ($this->ids[$m[1]] ?? 1), $uri);
        }

        return array_values(array_unique($uris));
    }
}
