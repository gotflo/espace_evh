<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\Gem;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ChatService;
use App\Services\Monitoring\UserAdmin;
use App\Support\PlatformManager;
use App\Support\PlatformSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Chat des GEMs : discussion de groupe du GEM et discussions privees entre ses membres.
 * Seuls les participants lisent ; chaque regle d'acces est verifiee par un compte qui ne devrait pas passer.
 */
class GemChatTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Gem $bethel;

    private Gem $silo;

    private User $garde;

    private User $marie;

    private User $zoe;

    private User $olga;

    private User $pastor;

    private User $patriarch;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-13 10:00'));
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        Storage::fake('local');
        Storage::fake('public');
        config(['services.webpush.enabled' => true]);

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->bethel = Gem::create(['name' => 'Béthel', 'tribe_id' => $this->juda->id, 'is_active' => true]);
        $this->silo = Gem::create(['name' => 'Silo', 'tribe_id' => $this->juda->id, 'is_active' => true]);

        $this->garde = $this->give($this->person('Gaston', 'Garde', $this->bethel), 'garde', 'gem', $this->bethel->id);
        $this->bethel->forceFill(['leader_user_id' => $this->garde->id])->save();
        $this->marie = $this->person('Marie', 'Fidèle', $this->bethel);
        $this->zoe = $this->person('Zoé', 'Zacharie', $this->bethel);
        $this->olga = $this->person('Olga', 'Ailleurs', $this->silo);
        $this->pastor = $this->give($this->person('Pierre', 'Pasteur', null), 'super_admin');
        $this->patriarch = $this->give($this->person('Paul', 'Patriarche', null), 'patriarche', 'tribe', $this->juda->id);
    }

    // ------------------------------------------------------------------ Liste et acces

    public function test_a_member_sees_the_group_of_her_gem_and_the_members_she_can_write_to_without_any_identifier(): void
    {
        $this->as($this->marie);
        $res = $this->getJson('/api/me/chat')->assertOk();
        $res->assertJsonPath('conversations.0.type', 'gem')->assertJsonPath('conversations.0.title', 'GEM Béthel')
            ->assertJsonPath('conversations.0.unread', 0)->assertJsonPath('conversations.0.last', null)
            ->assertJsonPath('conversations.0.can_send', true)->assertJsonCount(1, 'conversations');
        // Le Garde d'abord, puis par nom ; jamais soi-meme ni un membre d'un autre GEM.
        $this->assertSame(['Gaston Garde', 'Zoé Zacharie'], array_column($res->json('contacts'), 'name'));
        $this->assertSame([true, false], array_column($res->json('contacts'), 'is_garde'));
        $this->assertSame(ChatService::key($this->marie->id), $res->json('me'));

        $body = $res->getContent();
        foreach ([$this->garde, $this->zoe, $this->olga] as $u) {
            $this->assertStringNotContainsString($u->phone, $body);
        }
        $this->assertStringNotContainsString('@exemple.test', $body);
        $this->assertStringNotContainsString('user_id', $body);
        $this->assertStringNotContainsString('Olga', $body);

        // « Mon GEM » donne la meme cle opaque pour ouvrir la discussion privee.
        $gem = $this->getJson('/api/me/gem')->assertOk()->json('gem.members');
        $this->assertSame(ChatService::key($this->garde->id), $gem[0]['chat_key']);
        $this->assertNull(collect($gem)->firstWhere('is_me', true)['chat_key']);
    }

    public function test_without_gem_without_session_pending_blocked_or_management_account_there_is_no_chat(): void
    {
        $this->getJson('/api/me/chat')->assertUnauthorized();

        $this->as($this->pastor);
        $this->getJson('/api/me/chat')->assertOk()->assertExactJson(['me' => ChatService::key($this->pastor->id), 'conversations' => [], 'contacts' => [], 'unread' => 0]);

        foreach ([User::PENDING, User::REJECTED] as $status) {
            $this->marie->forceFill(['approval_status' => $status])->save();
            $this->as($this->marie);
            $this->getJson('/api/me/chat')->assertForbidden();
        }
        $this->marie->forceFill(['approval_status' => User::APPROVED])->save();

        Sanctum::actingAs(PlatformManager::activate('+22893471703')[0]);
        $this->getJson('/api/me/chat')->assertForbidden();
        $this->getJson('/api/me/chat/unread')->assertForbidden();
    }

    public function test_nobody_outside_the_gem_reads_or_writes_whatever_the_role(): void
    {
        $id = $this->group($this->marie);
        $message = $this->say($this->marie, $id, 'Prions pour la réunion de jeudi.')->json('message.id');

        // Membre d'un autre GEM, patriarche de la tribu, Pasteur Resident : la discussion « n'existe pas ».
        foreach ([$this->olga, $this->patriarch, $this->pastor] as $outsider) {
            $this->as($outsider);
            $this->getJson("/api/me/chat/{$id}")->assertNotFound();
            $this->getJson("/api/me/chat/{$id}/sync?rev=0")->assertNotFound();
            $this->postJson("/api/me/chat/{$id}/messages", ['client_id' => 'intrus-0001', 'body' => 'Bonjour'])->assertNotFound();
            $this->postJson("/api/me/chat/{$id}/read", ['message_id' => $message])->assertNotFound();
            $this->postJson("/api/me/chat/{$id}/typing")->assertNotFound();
            $this->getJson("/api/me/chat/{$id}/search?q=jeudi")->assertNotFound();
            $this->postJson("/api/me/chat/messages/{$message}/forward", ['client_id' => 'intrus-0002', 'conversation_id' => $id])->assertNotFound();
            $this->putJson("/api/me/chat/messages/{$message}", ['body' => 'Changé'])->assertNotFound();
            $this->deleteJson("/api/me/chat/messages/{$message}")->assertNotFound();
            $this->putJson("/api/me/chat/messages/{$message}/reaction", ['emoji' => '👍'])->assertNotFound();
            $this->getJson("/api/me/chat/messages/{$message}/photo")->assertNotFound();
            $this->postJson("/api/me/chat/messages/{$message}/report", ['reason' => 'Je veux lire'])->assertNotFound();
            $this->getJson('/api/me/chat/unread')->assertOk()->assertJsonPath('unread', 0);
        }
        $this->assertSame(1, ChatMessage::count());
        $this->assertSame('Prions pour la réunion de jeudi.', ChatMessage::sole()->body);
        // Aucune adresse d'administration ne montre le chat.
        $this->as($this->pastor);
        foreach (['/api/admin/chat', "/api/admin/chat/{$id}", '/api/admin/gems/'.$this->bethel->id.'/chat'] as $url) {
            $this->getJson($url)->assertNotFound();
        }
    }

    public function test_the_module_can_be_switched_off_and_comes_back_intact(): void
    {
        $id = $this->group($this->marie);
        $this->say($this->marie, $id, 'Avant la coupure.');

        PlatformSettings::update(['module.chat' => false], $this->pastor);
        $this->as($this->zoe);
        $this->getJson('/api/me/chat')->assertForbidden()->assertJsonPath('module', 'chat');
        $this->getJson("/api/me/chat/{$id}")->assertForbidden();
        $this->postJson("/api/me/chat/{$id}/messages", ['client_id' => 'coupe-00001', 'body' => 'Bonjour'])->assertForbidden();
        $this->assertNull($this->getJson('/api/me/gem')->assertOk()->json('gem.members.0.chat_key'));
        $this->getJson('/api/me/pulse')->assertOk()->assertJsonPath('chat', 0);

        PlatformSettings::update(['module.chat' => null], $this->pastor);
        $this->getJson("/api/me/chat/{$id}")->assertOk()->assertJsonPath('messages.0.body', 'Avant la coupure.');
    }

    // ------------------------------------------------------------------ Messages du groupe

    public function test_a_message_reaches_the_other_members_with_unread_counts_and_read_ticks(): void
    {
        $id = $this->group($this->marie);
        $sent = $this->say($this->marie, $id, "Bonjour à tous !\nOn se voit jeudi ?")->assertCreated();
        $sent->assertJsonPath('message.body', "Bonjour à tous !\nOn se voit jeudi ?")->assertJsonPath('message.mine', true)
            ->assertJsonPath('message.kind', 'text')->assertJsonPath('message.sender', ChatService::key($this->marie->id))
            ->assertJsonPath('rev', 1);
        $messageId = $sent->json('message.id');

        // Zoé : pastille du menu (pouls), liste, puis la discussion.
        $this->as($this->zoe);
        $this->getJson('/api/me/pulse')->assertOk()->assertJsonPath('chat', 1);
        $this->getJson('/api/me/chat/unread')->assertOk()->assertJsonPath('unread', 1);
        $this->getJson('/api/me/chat')->assertOk()->assertJsonPath('unread', 1)->assertJsonPath('conversations.0.unread', 1)
            ->assertJsonPath('conversations.0.last.text', 'Bonjour à tous ! On se voit jeudi ?')
            ->assertJsonPath('conversations.0.last.sender', 'Marie')->assertJsonPath('conversations.0.last.mine', false);
        $page = $this->getJson("/api/me/chat/{$id}")->assertOk();
        $page->assertJsonPath('messages.0.id', $messageId)->assertJsonPath('messages.0.mine', false)->assertJsonPath('messages.0.client_id', null)
            ->assertJsonPath('first_unread_id', $messageId)->assertJsonPath('conversation.members', 3)->assertJsonPath('rev', 1)
            ->assertJsonPath('people.'.ChatService::key($this->marie->id).'.name', 'Marie Fidèle')
            ->assertJsonPath('people.'.ChatService::key($this->garde->id).'.is_garde', true);
        $this->assertStringNotContainsString('user_id', $page->getContent());
        $this->assertStringNotContainsString($this->marie->phone, $page->getContent());

        // Ouvrir ne marque pas « lu » tout seul : l'ecran le dit quand le message est vraiment affiche.
        $this->getJson('/api/me/chat/unread')->assertJsonPath('unread', 1);
        $this->postJson("/api/me/chat/{$id}/read", ['message_id' => $messageId])->assertOk()->assertJsonPath('last_read_id', $messageId)->assertJsonPath('unread_total', 0);
        // Jamais au-dela du dernier message, jamais en arriere.
        $this->postJson("/api/me/chat/{$id}/read", ['message_id' => 999999])->assertOk()->assertJsonPath('last_read_id', $messageId);
        $this->postJson("/api/me/chat/{$id}/read", ['message_id' => 1])->assertOk()->assertJsonPath('last_read_id', $messageId);

        // Marie voit qui a lu (coches) : Zoé oui, le Garde pas encore.
        $this->as($this->marie);
        $sync = $this->getJson("/api/me/chat/{$id}/sync?rev=1")->assertOk();
        $sync->assertJsonPath('messages', [])->assertJsonPath('rev', 1)->assertJsonPath('unread_total', 0)
            ->assertJsonPath('reads.'.ChatService::key($this->zoe->id), $messageId)
            ->assertJsonPath('reads.'.ChatService::key($this->garde->id), 0);
    }

    public function test_the_live_update_returns_only_what_changed_and_pages_go_back_in_time(): void
    {
        $id = $this->group($this->marie);
        for ($i = 1; $i <= 45; $i++) {
            $this->travel(1)->seconds();
            $this->say($i % 2 ? $this->marie : $this->zoe, $id, "Message {$i}");
        }
        $this->as($this->garde);
        $page = $this->getJson("/api/me/chat/{$id}")->assertOk()->assertJsonPath('has_more', true)->assertJsonCount(40, 'messages')
            ->assertJsonPath('messages.0.body', 'Message 6')->assertJsonPath('messages.39.body', 'Message 45')->assertJsonPath('rev', 45);
        $older = $this->getJson("/api/me/chat/{$id}?before=".$page->json('messages.0.id'))->assertOk();
        $older->assertJsonPath('has_more', false)->assertJsonCount(5, 'messages')->assertJsonPath('messages.4.body', 'Message 5')->assertJsonPath('first_unread_id', null);

        $this->getJson("/api/me/chat/{$id}/sync?rev=45")->assertOk()->assertJsonPath('messages', [])->assertJsonPath('reload', false);
        $this->say($this->zoe, $id, 'Le tout dernier');
        $this->as($this->garde);
        $this->getJson("/api/me/chat/{$id}/sync?rev=45")->assertOk()->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'Le tout dernier')->assertJsonPath('rev', 46);
        $this->getJson("/api/me/chat/{$id}/sync")->assertStatus(422);
        $this->getJson("/api/me/chat/{$id}/sync?rev=-1")->assertStatus(422);
    }

    public function test_the_same_sending_repeated_by_the_phone_creates_a_single_message(): void
    {
        $id = $this->group($this->marie);
        $this->as($this->marie);
        $first = $this->postJson("/api/me/chat/{$id}/messages", ['client_id' => 'abc-12345678', 'body' => 'Une seule fois'])->assertCreated();
        $again = $this->postJson("/api/me/chat/{$id}/messages", ['client_id' => 'abc-12345678', 'body' => 'Une seule fois'])->assertOk();
        $this->assertSame($first->json('message.id'), $again->json('message.id'));
        $this->assertSame(1, ChatMessage::count());
        $this->assertSame(1, DB::table('push_outbox')->count());
        // Le meme identifiant d'envoi ne sert pas a ecrire ailleurs.
        $direct = $this->direct($this->marie, $this->zoe);
        $this->as($this->marie);
        $this->postJson("/api/me/chat/{$direct}/messages", ['client_id' => 'abc-12345678', 'body' => 'Ailleurs'])->assertStatus(422);
    }

    public function test_what_is_sent_is_checked(): void
    {
        $id = $this->group($this->marie);
        $other = $this->direct($this->marie, $this->zoe);
        $elsewhere = $this->say($this->marie, $other, 'En privé')->json('message.id');
        $this->as($this->marie);
        $send = fn (array $data) => $this->postJson("/api/me/chat/{$id}/messages", $data + ['client_id' => 'c-'.Str::random(12)]);

        $send(['body' => "   \n  "])->assertStatus(422)->assertJsonValidationErrors(['body']);
        $send([])->assertStatus(422);
        $send(['body' => str_repeat('a', ChatService::MAX_BODY + 1)])->assertStatus(422)->assertJsonValidationErrors(['body']);
        $send(['body' => str_repeat('é', ChatService::MAX_BODY)])->assertCreated();
        $this->postJson("/api/me/chat/{$id}/messages", ['body' => 'Sans identifiant'])->assertStatus(422)->assertJsonValidationErrors(['client_id']);
        $this->postJson("/api/me/chat/{$id}/messages", ['client_id' => 'a b', 'body' => 'x'])->assertStatus(422);
        // Reponse a un message d'une autre discussion : refusee.
        $send(['body' => 'Je réponds', 'reply_to' => $elsewhere])->assertStatus(422)->assertJsonValidationErrors(['reply_to']);
        // Texte rendu tel quel (l'ecran l'affiche comme du texte, jamais comme du code) ; caracteres de controle retires.
        $send(['body' => "<script>alert(1)</script>\u{202E}fin"])->assertCreated()->assertJsonPath('message.body', '<script>alert(1)</script>fin');
    }

    public function test_text_is_encrypted_in_the_database(): void
    {
        $id = $this->group($this->marie);
        $this->say($this->marie, $id, 'Sujet de prière très personnel');
        $raw = (string) DB::table('chat_messages')->value('body');
        $this->assertStringNotContainsString('personnel', $raw);
        $this->assertStringNotContainsString('pri', $raw);
        $this->assertGreaterThan(60, strlen($raw));
        $this->assertSame('Sujet de prière très personnel', ChatMessage::sole()->body);
        // Ni dans la file des notifications du telephone, ni dans la cloche de l'application.
        $this->assertStringNotContainsString('personnel', (string) DB::table('push_outbox')->value('payload'));
        $this->assertSame(2, UserNotification::where('type', 'chat')->count());
        foreach (UserNotification::all() as $note) {
            $this->assertStringNotContainsString('personnel', $note->title.' '.$note->body.' '.json_encode($note->data));
        }
    }

    // ------------------------------------------------------------------ Repondre, modifier, supprimer, reagir

    public function test_reply_edit_delete_and_reactions_follow_whatsapp_like_rules(): void
    {
        $id = $this->group($this->marie);
        $first = $this->say($this->marie, $id, 'Qui apporte le repas ?')->json('message.id');
        $reply = $this->as($this->zoe)->postJson("/api/me/chat/{$id}/messages", ['client_id' => 'rep-00000001', 'body' => 'Moi !', 'reply_to' => $first])->assertCreated();
        $reply->assertJsonPath('message.reply.id', $first)->assertJsonPath('message.reply.text', 'Qui apporte le repas ?')
            ->assertJsonPath('message.reply.sender', ChatService::key($this->marie->id));

        // Reactions : une par membre, changeable, retirable ; seulement celles prevues.
        $this->putJson("/api/me/chat/messages/{$first}/reaction", ['emoji' => '👍'])->assertOk()->assertJsonPath('message.reactions.0.emoji', '👍');
        $this->as($this->garde);
        $this->putJson("/api/me/chat/messages/{$first}/reaction", ['emoji' => '👍'])->assertOk()->assertJsonCount(2, 'message.reactions.0.by');
        $this->putJson("/api/me/chat/messages/{$first}/reaction", ['emoji' => '🙏'])->assertOk()->assertJsonCount(2, 'message.reactions');
        $this->putJson("/api/me/chat/messages/{$first}/reaction", ['emoji' => '💣'])->assertStatus(422);
        $this->putJson("/api/me/chat/messages/{$first}/reaction", [])->assertStatus(422);
        $this->putJson("/api/me/chat/messages/{$first}/reaction", ['emoji' => null])->assertOk()->assertJsonCount(1, 'message.reactions');
        $this->assertSame(1, DB::table('chat_reactions')->count());

        // Modifier : le sien seulement, pendant 15 minutes ; les autres ecrans le voient.
        $this->putJson("/api/me/chat/messages/{$first}", ['body' => 'Piraté'])->assertForbidden();
        $this->as($this->marie);
        $rev = $this->getJson("/api/me/chat/{$id}")->json('rev');
        $this->putJson("/api/me/chat/messages/{$first}", ['body' => '  '])->assertStatus(422);
        $this->putJson("/api/me/chat/messages/{$first}", ['body' => 'Qui apporte le repas jeudi ?'])->assertOk()
            ->assertJsonPath('message.edited', true)->assertJsonPath('message.body', 'Qui apporte le repas jeudi ?');
        $this->as($this->zoe);
        $this->getJson("/api/me/chat/{$id}/sync?rev={$rev}")->assertOk()->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.id', $first)->assertJsonPath('messages.0.edited', true);
        $this->travel(ChatService::EDIT_MINUTES + 1)->minutes();
        $this->as($this->marie);
        $this->putJson("/api/me/chat/messages/{$first}", ['body' => 'Trop tard'])->assertStatus(422);

        // Supprimer : le sien seulement ; texte et reactions effaces pour tout le monde, il reste une trace vide.
        $this->as($this->zoe);
        $this->deleteJson("/api/me/chat/messages/{$first}")->assertForbidden();
        $this->as($this->marie);
        $this->deleteJson("/api/me/chat/messages/{$first}")->assertOk()->assertJsonPath('message.deleted', true)
            ->assertJsonPath('message.body', null)->assertJsonPath('message.reactions', []);
        $this->deleteJson("/api/me/chat/messages/{$first}")->assertOk();
        $this->assertNull(DB::table('chat_messages')->where('id', $first)->value('body'));
        $this->assertSame(0, DB::table('chat_reactions')->count());
        $this->putJson("/api/me/chat/messages/{$first}", ['body' => 'Revenu'])->assertStatus(422);
        $this->putJson("/api/me/chat/messages/{$first}/reaction", ['emoji' => '👍'])->assertStatus(422);
        $this->as($this->zoe);
        $page = $this->getJson("/api/me/chat/{$id}")->assertOk();
        $page->assertJsonPath('messages.0.deleted', true)->assertJsonPath('messages.0.body', null)
            ->assertJsonPath('messages.1.reply.deleted', true)->assertJsonPath('messages.1.reply.text', 'Message supprimé');
        $this->assertStringNotContainsString('repas', $page->getContent());
    }

    // ------------------------------------------------------------------ Photos

    public function test_a_photo_is_private_reencoded_and_served_only_to_the_participants(): void
    {
        $id = $this->group($this->marie);
        $this->as($this->marie);
        $sent = $this->post("/api/me/chat/{$id}/messages", ['client_id' => 'photo-0000001', 'body' => 'Notre rencontre',
            'photo' => UploadedFile::fake()->image('rencontre.png', 2400, 1200)], ['Accept' => 'application/json'])->assertCreated();
        $sent->assertJsonPath('message.kind', 'photo')->assertJsonPath('message.body', 'Notre rencontre')
            ->assertJsonPath('message.photo.width', 1600)->assertJsonPath('message.photo.height', 800);
        $message = ChatMessage::sole();
        $this->assertStringStartsWith("chat/{$id}/", $message->photo_path);
        $this->assertStringEndsWith('.jpg', $message->photo_path);
        Storage::disk('local')->assertExists($message->photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertStringNotContainsString('photo_path', $sent->getContent());
        $this->assertStringNotContainsString($message->photo_path, $sent->getContent());

        $photo = $this->get("/api/me/chat/messages/{$message->id}/photo")->assertOk();
        $photo->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('private', (string) $photo->headers->get('Cache-Control'));
        $this->as($this->zoe);
        $this->get("/api/me/chat/messages/{$message->id}/photo")->assertOk();
        $this->as($this->olga);
        $this->getJson("/api/me/chat/messages/{$message->id}/photo")->assertNotFound();
        $this->as($this->pastor);
        $this->getJson("/api/me/chat/messages/{$message->id}/photo")->assertNotFound();

        // Ce qui n'est pas une image est refuse ; une photo seule (sans texte) passe.
        $this->as($this->zoe);
        $this->post("/api/me/chat/{$id}/messages", ['client_id' => 'photo-0000002',
            'photo' => UploadedFile::fake()->create('virus.php', 10, 'application/x-php')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/me/chat/{$id}/messages", ['client_id' => 'photo-0000003',
            'photo' => UploadedFile::fake()->createWithContent('faux.jpg', '<?php echo 1;')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/me/chat/{$id}/messages", ['client_id' => 'photo-0000004',
            'photo' => UploadedFile::fake()->image('seule.jpg', 300, 300)], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('message.body', null);
        $this->assertCount(2, Storage::disk('local')->allFiles());

        // Supprimee : le fichier disparait du disque.
        $this->as($this->marie);
        $this->deleteJson("/api/me/chat/messages/{$message->id}")->assertOk()->assertJsonPath('message.photo', null);
        Storage::disk('local')->assertMissing($message->photo_path);
        $this->getJson("/api/me/chat/messages/{$message->id}/photo")->assertNotFound();
    }

    // ------------------------------------------------------------------ Discussions privees

    public function test_a_private_discussion_opens_only_between_two_members_of_the_same_gem(): void
    {
        $this->as($this->marie);
        $id = $this->postJson('/api/me/chat/direct', ['key' => ChatService::key($this->zoe->id)])->assertOk()->json('conversation_id');
        $this->assertSame($id, $this->postJson('/api/me/chat/direct', ['key' => ChatService::key($this->zoe->id)])->json('conversation_id'));
        // Pas soi-meme, pas un membre d'un autre GEM, pas un responsable hors du GEM, pas une cle inventee.
        foreach ([$this->marie, $this->olga, $this->pastor, $this->patriarch] as $no) {
            $this->postJson('/api/me/chat/direct', ['key' => ChatService::key($no->id)])->assertNotFound();
        }
        $this->postJson('/api/me/chat/direct', ['key' => (string) $this->zoe->id])->assertStatus(422);
        $this->postJson('/api/me/chat/direct', ['key' => str_repeat('a', 20)])->assertNotFound();
        $this->assertSame(1, ChatConversation::where('type', 'direct')->count());

        // Vide : pas encore dans la liste ; apres un message : chez les deux, avec le nom de l'autre.
        $this->getJson('/api/me/chat')->assertJsonCount(1, 'conversations');
        $this->say($this->marie, $id, 'Coucou Zoé, tu vas bien ?');
        $this->as($this->zoe);
        $list = $this->getJson('/api/me/chat')->assertOk()->assertJsonCount(2, 'conversations')->assertJsonPath('unread', 1);
        $list->assertJsonPath('conversations.0.type', 'direct')->assertJsonPath('conversations.0.title', 'Marie Fidèle')
            ->assertJsonPath('conversations.0.peer', ChatService::key($this->marie->id))->assertJsonPath('conversations.0.unread', 1);
        $this->assertSame($id, collect($list->json('contacts'))->firstWhere('name', 'Marie Fidèle')['conversation_id']);
        $this->getJson("/api/me/chat/{$id}")->assertOk()->assertJsonPath('messages.0.body', 'Coucou Zoé, tu vas bien ?')
            ->assertJsonPath('conversation.title', 'Marie Fidèle')->assertJsonPath('conversation.members', 2);

        // Le troisieme membre du meme GEM (et son Garde) ne lit pas la discussion privee des deux autres.
        $this->as($this->garde);
        $this->getJson("/api/me/chat/{$id}")->assertNotFound();
        $this->getJson("/api/me/chat/{$id}/sync?rev=0")->assertNotFound();
        $this->postJson("/api/me/chat/{$id}/messages", ['client_id' => 'garde-000001', 'body' => 'Je lis'])->assertNotFound();
        $this->getJson('/api/me/chat')->assertJsonCount(1, 'conversations')->assertJsonPath('unread', 0);
        $this->assertStringNotContainsString('Coucou', $this->getJson('/api/me/chat')->getContent());
    }

    // ------------------------------------------------------------------ Arrivees et departs

    public function test_someone_who_leaves_the_gem_loses_the_group_and_can_no_longer_write_in_private(): void
    {
        $group = $this->group($this->marie);
        $direct = $this->direct($this->marie, $this->zoe);
        $this->say($this->marie, $group, 'Message du groupe');
        $this->say($this->marie, $direct, 'Message privé');

        // Zoé passe dans le GEM Silo.
        Profile::where('user_id', $this->zoe->id)->first()->forceFill(['gem_id' => $this->silo->id])->save();
        $this->as($this->zoe);
        $this->getJson("/api/me/chat/{$group}")->assertNotFound();
        $this->getJson("/api/me/chat/{$group}/sync?rev=0")->assertNotFound();
        $this->postJson("/api/me/chat/{$group}/messages", ['client_id' => 'partie-00001', 'body' => 'Encore là ?'])->assertNotFound();
        $list = $this->getJson('/api/me/chat')->assertOk();
        $this->assertSame(['GEM Silo', 'Marie Fidèle'], collect($list->json('conversations'))->pluck('title')->sort()->values()->all());
        $this->assertStringNotContainsString('Message du groupe', $list->getContent());
        // Seul le message prive reste compte (celui du groupe quitte ne l'est plus).
        $this->getJson('/api/me/chat/unread')->assertJsonPath('unread', 1);
        $this->getJson('/api/me/pulse')->assertJsonPath('chat', 1);

        // La discussion privee reste lisible, mais on n'y ecrit plus.
        $this->getJson("/api/me/chat/{$direct}")->assertOk()->assertJsonPath('conversation.can_send', false)->assertJsonPath('messages.0.body', 'Message privé');
        $this->postJson("/api/me/chat/{$direct}/messages", ['client_id' => 'partie-00002', 'body' => 'Je réponds'])->assertForbidden();
        $this->putJson('/api/me/chat/messages/'.ChatMessage::where('conversation_id', $direct)->value('id').'/reaction', ['emoji' => '👍'])->assertForbidden();
        $this->as($this->marie);
        $this->postJson("/api/me/chat/{$direct}/messages", ['client_id' => 'partie-00003', 'body' => 'Tu es partie ?'])->assertForbidden();
        $this->getJson('/api/me/chat')->assertOk()->assertJsonPath('conversations.1.can_send', false);
        // Marie ecrit au groupe : Zoé n'y est plus inscrite.
        $this->say($this->marie, $group, 'Après le départ');
        $this->assertFalse(ChatParticipant::where('conversation_id', $group)->where('user_id', $this->zoe->id)->exists());

        // Zoé revient : elle ne lit que ce qui est envoye depuis son retour.
        Profile::where('user_id', $this->zoe->id)->first()->forceFill(['gem_id' => $this->bethel->id])->save();
        $this->as($this->zoe);
        $back = $this->getJson("/api/me/chat/{$group}")->assertOk()->assertJsonPath('messages', []);
        $this->assertStringNotContainsString('Après le départ', $this->getJson('/api/me/chat')->getContent());
        $this->getJson("/api/me/chat/{$group}/sync?rev=0")->assertOk()->assertJsonPath('messages', []);
        $this->assertGreaterThan(0, $back->json('last_read_id'), 'inscrite au message courant');
        $this->getJson("/api/me/chat/{$direct}")->assertOk()->assertJsonPath('conversation.can_send', true);
    }

    public function test_a_newcomer_reads_only_what_is_sent_after_arriving(): void
    {
        $group = $this->group($this->marie);
        $old = $this->say($this->marie, $group, 'Confidence d’avant')->json('message.id');
        $withPhoto = $this->as($this->marie)->post("/api/me/chat/{$group}/messages", ['client_id' => 'avant-photo-1',
            'photo' => UploadedFile::fake()->image('avant.jpg', 200, 200)], ['Accept' => 'application/json'])->assertCreated()->json('message.id');

        $nina = $this->person('Nina', 'Nouvelle', $this->bethel);
        $this->as($nina);
        $list = $this->getJson('/api/me/chat')->assertOk()->assertJsonPath('unread', 0)->assertJsonPath('conversations.0.last', null);
        $this->assertStringNotContainsString('Confidence', $list->getContent());
        $this->getJson("/api/me/chat/{$group}")->assertOk()->assertJsonPath('messages', [])->assertJsonPath('has_more', false);
        $this->getJson("/api/me/chat/{$group}?before=999999")->assertOk()->assertJsonPath('messages', []);
        $this->getJson("/api/me/chat/{$group}/sync?rev=0")->assertOk()->assertJsonPath('messages', []);
        // Ni par l'adresse directe d'un ancien message, ni en y repondant.
        $this->getJson("/api/me/chat/messages/{$withPhoto}/photo")->assertNotFound();
        $this->putJson("/api/me/chat/messages/{$old}/reaction", ['emoji' => '👍'])->assertNotFound();
        $this->postJson("/api/me/chat/messages/{$old}/report", ['reason' => 'Curiosité'])->assertNotFound();
        $this->postJson("/api/me/chat/{$group}/messages", ['client_id' => 'nina-0000001', 'body' => 'Je réponds', 'reply_to' => $old])->assertStatus(422);

        $this->say($this->marie, $group, 'Bienvenue Nina !');
        $this->as($nina);
        $this->getJson("/api/me/chat/{$group}")->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.body', 'Bienvenue Nina !');
        $this->getJson('/api/me/chat/unread')->assertJsonPath('unread', 1);

        // Un Garde nomme ensuite sur ce GEM y entre de la meme facon ; l'ancien Garde, parti, n'y a plus acces.
        $newGarde = $this->person('Gilles', 'Gardien', $this->silo);
        $this->bethel->forceFill(['leader_user_id' => $newGarde->id])->save();
        Profile::where('user_id', $this->garde->id)->first()->forceFill(['gem_id' => null])->save();
        $this->as($newGarde);
        $this->assertSame(['GEM Béthel', 'GEM Silo'], collect($this->getJson('/api/me/chat')->json('conversations'))->pluck('title')->sort()->values()->all());
        $this->getJson("/api/me/chat/{$group}")->assertOk()->assertJsonPath('messages', []);
        $this->as($this->garde);
        $this->getJson("/api/me/chat/{$group}")->assertNotFound();
    }

    // ------------------------------------------------------------------ Notifications

    public function test_the_phone_is_notified_without_the_text_one_push_per_burst(): void
    {
        $group = $this->group($this->marie);
        $this->say($this->marie, $group, 'Texte confidentiel du groupe');
        $row = DB::table('push_outbox')->sole();
        $this->assertEqualsCanonicalizing([$this->garde->id, $this->zoe->id], json_decode($row->user_ids, true));
        $payload = json_decode($row->payload, true);
        $this->assertSame('GEM Béthel', $payload['title']);
        $this->assertSame('Marie : nouveau message', $payload['body']);
        $this->assertSame("/chat/{$group}", $payload['url']);
        $this->assertSame('chat', $payload['type']);
        $this->assertSame("chat-{$group}", $payload['tag']);

        // Rafale : une seule vibration par discussion tant que le delai court.
        $this->say($this->marie, $group, 'Deuxième');
        $this->say($this->marie, $group, 'Troisième');
        $this->assertSame(1, DB::table('push_outbox')->count());
        $this->travel(20)->seconds();
        $this->say($this->marie, $group, 'Plus tard');
        $this->assertSame(2, DB::table('push_outbox')->count());

        // Discussion privee : le nom de l'expediteur, toujours sans le texte.
        DB::table('push_outbox')->delete();
        $direct = $this->direct($this->marie, $this->zoe);
        $this->as($this->marie)->post("/api/me/chat/{$direct}/messages", ['client_id' => 'push-photo-01',
            'photo' => UploadedFile::fake()->image('p.jpg', 100, 100)], ['Accept' => 'application/json'])->assertCreated();
        $row = DB::table('push_outbox')->sole();
        $this->assertSame([$this->zoe->id], json_decode($row->user_ids, true));
        $this->assertSame(['Marie Fidèle', 'Photo'], [json_decode($row->payload, true)['title'], json_decode($row->payload, true)['body']]);
    }

    public function test_the_app_bell_holds_one_notification_per_discussion_and_follows_what_is_read(): void
    {
        $group = $this->group($this->marie);
        $direct = $this->direct($this->marie, $this->zoe);
        $bell = fn (User $u) => UserNotification::where('user_id', $u->id)->where('type', 'chat')->orderBy('id')->get();

        // Trois messages dans le groupe : une seule ligne dans la cloche de chacun, mise a jour.
        $this->say($this->marie, $group, 'Un');
        $this->assertSame('Marie : nouveau message', $bell($this->zoe)->sole()->body);
        $this->say($this->marie, $group, 'Deux');
        $last = $this->say($this->marie, $group, 'Trois')->json('message.id');
        foreach ([$this->zoe, $this->garde] as $member) {
            $note = $bell($member)->sole();
            $this->assertSame(['GEM Béthel', '3 nouveaux messages', "/chat/{$group}", null], [$note->title, $note->body, $note->url, $note->read_at]);
        }
        $this->assertSame(0, $bell($this->marie)->count(), 'rien pour l’auteur');

        // Une autre discussion : sa propre ligne. La cloche de Zoé compte deux discussions.
        $this->say($this->marie, $direct, 'En privé');
        $this->assertSame(['3 nouveaux messages', 'Nouveau message'], $bell($this->zoe)->pluck('body')->all());
        $this->as($this->zoe);
        $this->getJson('/api/me/pulse')->assertOk()->assertJsonPath('unread', 2)->assertJsonPath('chat', 4);
        $prefs = $this->getJson('/api/me/notification-prefs')->assertOk()->json('categories');
        $this->assertContains('chat', array_column($prefs, 'key'));

        // Lire le groupe a moitie ne touche pas la cloche ; tout lire la marque lue, sans toucher l'autre discussion.
        $this->postJson("/api/me/chat/{$group}/read", ['message_id' => $last - 1])->assertOk()->assertJsonPath('notifications_read', 0);
        $this->postJson("/api/me/chat/{$group}/read", ['message_id' => $last])->assertOk()->assertJsonPath('notifications_read', 1)->assertJsonPath('unread_total', 1);
        $this->getJson('/api/me/pulse')->assertJsonPath('unread', 1)->assertJsonPath('chat', 1);
        $this->assertNotNull($bell($this->zoe)->first()->read_at);
        $this->assertNull($bell($this->zoe)->last()->read_at);

        // Message suivant : une NOUVELLE ligne (la precedente est lue), qui repart de un.
        $this->say($this->marie, $group, 'Quatre');
        $this->assertSame('Marie : nouveau message', $bell($this->zoe)->last()->body);
        $this->assertSame(1, UserNotification::where('user_id', $this->zoe->id)->where('url', "/chat/{$group}")->whereNull('read_at')->count());
        // Répondre dans la discussion vaut lecture : sa ligne est marquee lue.
        $this->say($this->zoe, $group, 'Bien reçu');
        $this->assertSame(0, UserNotification::where('user_id', $this->zoe->id)->where('url', "/chat/{$group}")->whereNull('read_at')->count());

        // Celle qui quitte le GEM ne garde pas la notification d'une discussion qu'elle ne peut plus ouvrir.
        $this->say($this->marie, $group, 'Cinq');
        Profile::where('user_id', $this->zoe->id)->first()->forceFill(['gem_id' => $this->silo->id])->save();
        $this->say($this->marie, $group, 'Six');
        $this->assertSame(0, UserNotification::where('user_id', $this->zoe->id)->where('url', "/chat/{$group}")->whereNull('read_at')->count());
    }

    public function test_no_notification_for_an_open_screen_no_push_for_a_switched_off_preference_or_a_closed_account(): void
    {
        $group = $this->group($this->marie);

        // Preference « Messages du chat » coupee par le Garde : plus de push pour lui, la cloche de l'application reste.
        $this->as($this->garde);
        $this->putJson('/api/me/notification-prefs', ['prefs' => ['chat' => false]])->assertOk();
        $this->say($this->marie, $group, 'Le Garde a coupé le chat');
        $this->assertSame([$this->zoe->id], json_decode(DB::table('push_outbox')->sole()->user_ids, true));
        $this->assertSame(1, UserNotification::where('user_id', $this->garde->id)->where('type', 'chat')->count());

        // Zoé a la discussion a l'ecran (mise a jour en direct recente) : ni push ni cloche ; compte bloque : jamais.
        DB::table('push_outbox')->delete();
        UserNotification::query()->delete();
        $this->travel(30)->seconds();
        $this->garde->forceFill(['notification_prefs' => null, 'blocked_at' => now()])->save();
        $this->as($this->zoe);
        $this->getJson("/api/me/chat/{$group}/sync?rev=0")->assertOk();
        $this->say($this->marie, $group, 'Zoé regarde l’écran');
        $this->assertSame(0, DB::table('push_outbox')->count());
        $this->assertSame(0, UserNotification::count());
        // L'ecran ferme depuis un moment : tout repart, pour elle seule.
        $this->travel(30)->seconds();
        $this->say($this->marie, $group, 'Zoé est partie');
        $this->assertSame([$this->zoe->id], json_decode(DB::table('push_outbox')->sole()->user_ids, true));
        $this->assertSame([$this->zoe->id], UserNotification::pluck('user_id')->all());
    }

    // ------------------------------------------------------------------ Recherche et transfert

    public function test_search_finds_messages_whatever_the_case_or_accents_only_where_one_can_read(): void
    {
        $group = $this->group($this->marie);
        $old = $this->say($this->marie, $group, 'Sujet de PRIÈRE pour la famille Tremblay')->json('message.id');
        for ($i = 1; $i <= 50; $i++) {
            $this->say($i % 2 ? $this->zoe : $this->marie, $group, "Remplissage {$i}");
        }
        $gone = $this->say($this->zoe, $group, 'Une priere supprimée')->json('message.id');
        $this->as($this->zoe)->deleteJson("/api/me/chat/messages/{$gone}")->assertOk();
        $recent = $this->say($this->zoe, $group, 'On continue la prière jeudi soir')->json('message.id');
        $direct = $this->direct($this->marie, $this->zoe);
        $this->say($this->marie, $direct, 'Prière privée entre nous');

        $this->as($this->garde);
        $res = $this->getJson("/api/me/chat/{$group}/search?q=priere")->assertOk()->assertJsonPath('partial', false);
        $this->assertSame([$recent, $old], array_column($res->json('results'), 'id'));
        $res->assertJsonPath('results.0.sender', 'Zoé Zacharie')->assertJsonPath('results.1.sender', 'Marie Fidèle')
            ->assertJsonPath('results.1.text', 'Sujet de PRIÈRE pour la famille Tremblay');
        $this->assertStringNotContainsString('privée', $res->getContent());
        $this->assertStringNotContainsString('supprimée', $res->getContent());
        $this->getJson("/api/me/chat/{$group}/search?q=TREMBLAY")->assertOk()->assertJsonCount(1, 'results');
        $this->getJson("/api/me/chat/{$group}/search?q=introuvable")->assertOk()->assertJsonPath('results', []);
        $this->getJson("/api/me/chat/{$group}/search?q=a")->assertStatus(422);
        $this->getJson("/api/me/chat/{$group}/search")->assertStatus(422);
        $this->getJson("/api/me/chat/{$direct}/search?q=priere")->assertNotFound(); // discussion privee des deux autres

        // Aller au message retrouve : il arrive entoure de ses voisins ; on peut ensuite remonter ou redescendre.
        $around = $this->getJson("/api/me/chat/{$group}?around={$old}")->assertOk();
        $around->assertJsonPath('messages.0.id', $old)->assertJsonPath('has_more', false)->assertJsonPath('has_newer', true)
            ->assertJsonCount(21, 'messages')->assertJsonPath('first_unread_id', null);
        $next = $this->getJson("/api/me/chat/{$group}?after=".$around->json('messages.20.id'))->assertOk();
        $next->assertJsonCount(32, 'messages')->assertJsonPath('has_newer', false)->assertJsonPath('has_more', null);
        $this->assertSame($recent, $next->json('messages.31.id'));
        $this->getJson("/api/me/chat/{$group}?around=999999")->assertNotFound();

        // Une nouvelle arrivee ne trouve rien de ce qui precede son arrivee, meme en cherchant.
        $nina = $this->person('Nina', 'Nouvelle', $this->bethel);
        $this->as($nina);
        $this->getJson("/api/me/chat/{$group}/search?q=priere")->assertOk()->assertJsonPath('results', []);
        $this->getJson("/api/me/chat/{$group}?around={$old}")->assertNotFound();
        $this->say($this->marie, $group, 'Prière de bienvenue');
        $this->as($nina);
        $this->getJson("/api/me/chat/{$group}/search?q=priere")->assertOk()->assertJsonCount(1, 'results');
    }

    public function test_a_message_is_forwarded_only_from_what_one_reads_to_where_one_writes(): void
    {
        $group = $this->group($this->marie);
        $text = $this->say($this->zoe, $group, 'Rendez-vous jeudi 19 h à la salle 2')->json('message.id');
        $photo = $this->as($this->zoe)->post("/api/me/chat/{$group}/messages", ['client_id' => 'transfert-ph-1', 'body' => 'Le plan',
            'photo' => UploadedFile::fake()->image('plan.jpg', 400, 300)], ['Accept' => 'application/json'])->assertCreated()->json('message.id');
        $direct = $this->direct($this->marie, $this->garde);

        // Marie transfere le texte au Garde, en prive : mention « Transféré », a son nom, sans la reponse ni les reactions.
        $this->as($this->marie);
        $sent = $this->postJson("/api/me/chat/messages/{$text}/forward", ['client_id' => 'transfert-0001', 'conversation_id' => $direct])->assertCreated();
        $sent->assertJsonPath('message.body', 'Rendez-vous jeudi 19 h à la salle 2')->assertJsonPath('message.forwarded', true)
            ->assertJsonPath('message.mine', true)->assertJsonPath('message.reply', null)->assertJsonPath('conversation_id', $direct);
        $this->postJson("/api/me/chat/messages/{$text}/forward", ['client_id' => 'transfert-0001', 'conversation_id' => $direct])->assertOk();
        $this->assertSame(1, ChatMessage::where('conversation_id', $direct)->count());
        // Par la cle d'un membre : la discussion privee est ouverte au passage.
        $toZoe = $this->postJson("/api/me/chat/messages/{$text}/forward", ['client_id' => 'transfert-0002', 'key' => ChatService::key($this->zoe->id)])->assertCreated()->json('conversation_id');
        $this->assertSame([$this->marie->id, $this->zoe->id], [ChatConversation::find($toZoe)->user_low_id, ChatConversation::find($toZoe)->user_high_id]);

        // Photo : copiee (chaque discussion a son fichier), l'originale reste si la copie est supprimee.
        $copy = $this->postJson("/api/me/chat/messages/{$photo}/forward", ['client_id' => 'transfert-0003', 'conversation_id' => $direct])->assertCreated();
        $copy->assertJsonPath('message.kind', 'photo')->assertJsonPath('message.body', 'Le plan')->assertJsonPath('message.photo.width', 400);
        $paths = ChatMessage::whereNotNull('photo_path')->orderBy('id')->pluck('photo_path')->all();
        $this->assertCount(2, array_unique($paths));
        $this->assertStringStartsWith("chat/{$direct}/", $paths[1]);
        $this->deleteJson('/api/me/chat/messages/'.$copy->json('message.id'))->assertOk();
        Storage::disk('local')->assertExists($paths[0]);
        Storage::disk('local')->assertMissing($paths[1]);
        // Le destinataire est prevenu comme pour un message ordinaire.
        $this->assertSame(1, UserNotification::where('user_id', $this->garde->id)->where('url', "/chat/{$direct}")->count());

        // Refus : cible ou l'on n'ecrit pas, message qu'on ne lit pas, message supprime, demande incomplete.
        $elsewhere = $this->group($this->olga);
        $this->as($this->marie);
        $this->postJson("/api/me/chat/messages/{$text}/forward", ['client_id' => 'transfert-0004', 'conversation_id' => $elsewhere])->assertNotFound();
        $this->postJson("/api/me/chat/messages/{$text}/forward", ['client_id' => 'transfert-0005', 'key' => ChatService::key($this->olga->id)])->assertNotFound();
        $this->postJson("/api/me/chat/messages/{$text}/forward", ['client_id' => 'transfert-0006'])->assertStatus(422);
        $this->as($this->olga);
        $this->postJson("/api/me/chat/messages/{$text}/forward", ['client_id' => 'transfert-0007', 'conversation_id' => $elsewhere])->assertNotFound();
        $this->as($this->zoe)->deleteJson("/api/me/chat/messages/{$text}")->assertOk();
        $this->as($this->marie);
        $this->postJson("/api/me/chat/messages/{$text}/forward", ['client_id' => 'transfert-0008', 'conversation_id' => $direct])->assertStatus(422);
        // Discussion privee fermee (plus le meme GEM) : on n'y transfere plus.
        Profile::where('user_id', $this->garde->id)->first()->forceFill(['gem_id' => null])->save();
        $this->bethel->forceFill(['leader_user_id' => null])->save();
        $this->postJson("/api/me/chat/messages/{$photo}/forward", ['client_id' => 'transfert-0009', 'conversation_id' => $direct])->assertForbidden();
    }

    public function test_typing_and_online_are_short_lived_signals(): void
    {
        $direct = $this->direct($this->marie, $this->zoe);
        $this->as($this->zoe);
        $this->postJson("/api/me/chat/{$direct}/typing")->assertOk();
        $this->as($this->marie);
        $this->getJson("/api/me/chat/{$direct}/sync?rev=0")->assertOk()->assertJsonPath('typing', [ChatService::key($this->zoe->id)])
            ->assertJsonPath('conversation.online', true);
        $this->travel(40)->seconds();
        $this->getJson("/api/me/chat/{$direct}/sync?rev=0")->assertOk()->assertJsonPath('typing', [])->assertJsonPath('conversation.online', false);
        // Envoyer efface « écrit… ».
        $this->as($this->zoe);
        $this->postJson("/api/me/chat/{$direct}/typing")->assertOk();
        $this->say($this->zoe, $direct, 'Voilà');
        $this->as($this->marie);
        $this->getJson("/api/me/chat/{$direct}/sync?rev=0")->assertOk()->assertJsonPath('typing', []);
        $this->assertSame(0, DB::table('chat_messages')->where('body', 'like', '%typing%')->count());
    }

    // ------------------------------------------------------------------ Signalement

    public function test_reporting_shows_that_single_message_to_the_resident_pastor_only(): void
    {
        $group = $this->group($this->marie);
        $this->say($this->marie, $group, 'Message correct');
        $bad = $this->say($this->zoe, $group, 'Propos blessant')->json('message.id');

        $this->as($this->zoe);
        $this->postJson("/api/me/chat/messages/{$bad}/report", ['reason' => 'Test'])->assertStatus(422); // le sien
        $this->as($this->marie);
        $this->postJson("/api/me/chat/messages/{$bad}/report", ['reason' => ''])->assertStatus(422);
        $this->postJson("/api/me/chat/messages/{$bad}/report", ['reason' => 'Ce message me blesse.'])->assertOk();
        $this->postJson("/api/me/chat/messages/{$bad}/report", ['reason' => 'Encore'])->assertStatus(422);

        $note = UserNotification::where('type', 'chat_report')->sole();
        $this->assertSame($this->pastor->id, $note->user_id);
        $this->assertSame(0, UserNotification::where('user_id', $this->pastor->id)->where('type', 'chat')->count());
        $this->assertSame('Message signalé · GEM Béthel', $note->title);
        $this->assertStringContainsString('Marie Fidèle signale un message de Zoé Zacharie', $note->body);
        $this->assertStringContainsString('« Propos blessant »', $note->body);
        $this->assertStringContainsString('Motif : Ce message me blesse.', $note->body);
        $this->assertStringNotContainsString('Message correct', $note->body);
        // Le pasteur n'a toujours aucun acces a la discussion.
        $this->as($this->pastor);
        $this->getJson("/api/me/chat/{$group}")->assertNotFound();
    }

    // ------------------------------------------------------------------ Suppressions

    public function test_deleting_an_account_or_a_gem_removes_messages_and_photos(): void
    {
        $group = $this->group($this->marie);
        $direct = $this->direct($this->marie, $this->zoe);
        $photo = fn (User $from, int $to, string $id) => $this->as($from)->post("/api/me/chat/{$to}/messages", ['client_id' => $id,
            'photo' => UploadedFile::fake()->image('p.jpg', 100, 100)], ['Accept' => 'application/json'])->assertCreated();
        $photo($this->marie, $group, 'suppr-photo-01');
        $photo($this->zoe, $direct, 'suppr-photo-02');
        $photo($this->zoe, $group, 'suppr-photo-03');
        $this->say($this->zoe, $group, 'Je reste');
        $this->assertCount(3, Storage::disk('local')->allFiles());
        $this->assertSame(1, collect(UserAdmin::impact($this->marie)['deleted'])->firstWhere('label', 'Messages du chat des GEMs')['count']);

        // Compte de Marie supprime : ses messages, sa discussion privee entiere (meme la photo de Zoé) et les fichiers.
        UserAdmin::delete($this->marie->fresh(), 'test', $this->pastor->id);
        $this->assertSame(0, ChatMessage::where('sender_id', $this->marie->id)->count());
        $this->assertNull(ChatConversation::find($direct));
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->as($this->zoe);
        $this->getJson("/api/me/chat/{$group}")->assertOk()->assertJsonCount(2, 'messages');
        $this->getJson('/api/me/chat')->assertOk()->assertJsonCount(1, 'conversations');

        // GEM supprime : sa discussion et ses photos disparaissent.
        $this->as($this->pastor);
        $this->deleteJson('/api/admin/gems/'.$this->bethel->id)->assertOk();
        $this->assertSame(0, ChatConversation::count());
        $this->assertSame(0, ChatMessage::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_flood_of_messages_is_slowed_down(): void
    {
        $id = $this->group($this->marie);
        $this->as($this->marie);
        $codes = [];
        for ($i = 0; $i < 45; $i++) {
            $codes[] = $this->postJson("/api/me/chat/{$id}/messages", ['client_id' => 'rafale-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'body' => "Message {$i}"])->status();
        }
        $this->assertSame(40, count(array_filter($codes, fn ($c) => $c === 201)));
        $this->assertSame(5, count(array_filter($codes, fn ($c) => $c === 429)));
        // Les autres membres ne sont pas ralentis par la rafale de Marie.
        $this->say($this->zoe, $id, 'Je peux écrire')->assertCreated();
    }

    // ------------------------------------------------------------------ Outils

    private function group(User $member): int
    {
        $this->as($member);

        return (int) collect($this->getJson('/api/me/chat')->assertOk()->json('conversations'))->firstWhere('type', 'gem')['id'];
    }

    private function direct(User $from, User $to): int
    {
        $this->as($from);

        return (int) $this->postJson('/api/me/chat/direct', ['key' => ChatService::key($to->id)])->assertOk()->json('conversation_id');
    }

    private function say(User $from, int $conversation, string $body)
    {
        $this->as($from);

        return $this->postJson("/api/me/chat/{$conversation}/messages", ['client_id' => 'm-'.Str::random(16), 'body' => $body]);
    }

    private function person(string $first, string $last, ?Gem $gem): User
    {
        $n = ++$this->n;
        $user = User::create(['phone' => '+1418558'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'last_login_at' => now()]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'femme',
            'email' => Str::lower(Str::ascii($first)).'.'.Str::lower(Str::ascii($last)).'@exemple.test',
            'is_completed' => true, 'tribe_id' => $this->juda->id, 'gem_id' => $gem?->id]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $key, ?string $kind = null, ?int $id = null): User
    {
        $user->roles()->attach(Role::where('key', $key)->value('id'), ['scope_kind' => $kind, 'scope_id' => $id]);

        return $user->fresh();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user->fresh());

        return $this;
    }
}
