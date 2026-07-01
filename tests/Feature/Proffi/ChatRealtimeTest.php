<?php

namespace Tests\Feature\Proffi;

use App\Events\Proffi\MessageSent;
use App\Events\Proffi\MessagesRead;
use App\Events\Proffi\UserPresenceUpdated;
use App\Events\Proffi\UserTyping;
use App\Mail\ProffiNewMessageMail;
use App\Models\ProffiChat;
use App\Models\ProffiMessage;
use App\Models\ProffiTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Tests\TestCase;

class ChatRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $specialist;

    private User $outsider;

    private ProffiChat $chat;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            MessageSent::class,
            MessagesRead::class,
            UserTyping::class,
            UserPresenceUpdated::class,
        ]);
        Mail::fake();

        $this->customer = User::create([
            'name' => 'Customer',
            'email' => 'customer@example.com',
            'password' => bcrypt('secret'),
        ]);

        $this->specialist = User::create([
            'name' => 'Specialist',
            'email' => 'specialist@example.com',
            'password' => bcrypt('secret'),
        ]);

        $this->outsider = User::create([
            'name' => 'Outsider',
            'email' => 'outsider@example.com',
            'password' => bcrypt('secret'),
        ]);

        $task = ProffiTask::create([
            'title' => 'Test task',
            'description' => 'Description',
            'category' => 'repair',
            'city' => 'Moscow',
            'status' => 'in_progress',
            'customer_id' => $this->customer->id,
        ]);

        $this->chat = ProffiChat::create([
            'task_id' => $task->id,
            'customer_id' => $this->customer->id,
            'specialist_id' => $this->specialist->id,
            'last_message' => null,
            'last_message_at' => null,
        ]);
    }

    public function test_user_cannot_read_foreign_chat(): void
    {
        Sanctum::actingAs($this->outsider);

        $this->getJson('/api/proffi/chats/' . $this->chat->id)
            ->assertForbidden();
    }

    public function test_send_message_creates_record_and_updates_last_message(): void
    {
        Sanctum::actingAs($this->customer);

        $response = $this->postJson('/api/proffi/chats/' . $this->chat->id . '/messages', [
            'text' => 'Привет',
        ]);

        $response->assertCreated()
            ->assertJsonPath('text', 'Привет');

        $this->assertDatabaseHas('proffi_messages', [
            'chat_id' => $this->chat->id,
            'sender_id' => $this->customer->id,
            'text' => 'Привет',
        ]);

        $this->chat->refresh();
        $this->assertSame('Привет', $this->chat->last_message);
        $this->assertNotNull($this->chat->last_message_at);
    }

    public function test_chat_list_returns_unread_count(): void
    {
        ProffiMessage::create([
            'chat_id' => $this->chat->id,
            'sender_id' => $this->specialist->id,
            'text' => 'Новое',
            'type' => 'text',
        ]);

        Sanctum::actingAs($this->customer);

        $this->getJson('/api/proffi/chats')
            ->assertOk()
            ->assertJsonPath('0.unread_count', 1);
    }

    public function test_read_marks_only_incoming_messages(): void
    {
        $incoming = ProffiMessage::create([
            'chat_id' => $this->chat->id,
            'sender_id' => $this->specialist->id,
            'text' => 'От специалиста',
            'type' => 'text',
        ]);

        $outgoing = ProffiMessage::create([
            'chat_id' => $this->chat->id,
            'sender_id' => $this->customer->id,
            'text' => 'От клиента',
            'type' => 'text',
        ]);

        Sanctum::actingAs($this->customer);

        $this->postJson('/api/proffi/chats/' . $this->chat->id . '/read')
            ->assertOk();

        $incoming->refresh();
        $outgoing->refresh();

        $this->assertNotNull($incoming->read_at);
        $this->assertNull($outgoing->read_at);
    }

    public function test_typing_is_available_only_to_participants(): void
    {
        Sanctum::actingAs($this->outsider);

        $this->postJson('/api/proffi/chats/' . $this->chat->id . '/typing', [
            'is_typing' => true,
        ])->assertForbidden();

        Sanctum::actingAs($this->customer);

        $this->postJson('/api/proffi/chats/' . $this->chat->id . '/typing', [
            'is_typing' => true,
        ])->assertOk()
            ->assertJsonPath('is_typing', true);
    }

    public function test_send_message_dispatches_broadcast_event(): void
    {
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/proffi/chats/' . $this->chat->id . '/messages', [
            'text' => 'Realtime',
        ])->assertCreated();

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) {
            return (int) $event->chat->id === (int) $this->chat->id
                && $event->message->text === 'Realtime';
        });
    }

    public function test_email_is_not_sent_to_sender(): void
    {
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/proffi/chats/' . $this->chat->id . '/messages', [
            'text' => 'Письмо специалисту',
        ])->assertCreated();

        Mail::assertSent(ProffiNewMessageMail::class, function (ProffiNewMessageMail $mail) {
            return $mail->hasTo($this->specialist->email);
        });

        Mail::assertNotSent(ProffiNewMessageMail::class, function (ProffiNewMessageMail $mail) {
            return $mail->hasTo($this->customer->email);
        });
    }
}
