<?php

namespace Tests\Feature\Proffi;

use App\Models\ProffiCategory;
use App\Models\ProffiTask;
use App\Models\ProffiWork;
use App\Models\RequestDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Place;
use Marvel\Database\Models\User;
use Tests\TestCase;

class PlaceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_edit_and_publish_a_place_but_another_user_cannot(): void
    {
        [$category, $work] = $this->catalog();
        $owner = $this->user('owner@example.test');
        $other = $this->user('other@example.test');
        Sanctum::actingAs($owner);

        $created = $this->postJson('/api/proffi/places', [
            ...$this->placePayload($category->id, $work->id),
            'status' => 'draft',
            'price' => 185000,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.price_label', '185 000 ₽');

        $placeId = $created->json('data.id');
        $this->patchJson('/api/proffi/places/'.$placeId, [
            'title' => 'Кухня после ремонта',
            'hide_price' => true,
            'status' => 'published',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Кухня после ремонта')
            ->assertJsonPath('data.price', null)
            ->assertJsonPath('data.price_label', 'Цена по запросу');

        $this->getJson('/api/proffi/places/'.$placeId)->assertOk()
            ->assertJsonPath('data.hide_price', true)
            ->assertJsonPath('data.price', null);

        Sanctum::actingAs($other);
        $this->patchJson('/api/proffi/places/'.$placeId, ['title' => 'Чужое изменение'])
            ->assertForbidden();
    }

    public function test_list_filters_viewport_favorites_and_user_places_use_the_same_place_entity(): void
    {
        [$category, $work] = $this->catalog();
        $owner = $this->user('master@example.test');
        $viewer = $this->user('viewer@example.test');
        $inside = Place::create([
            ...$this->placePayload($category->id, $work->id),
            'user_id' => $owner->id,
            'status' => 'published',
            'published_at' => now(),
            'lat' => 55.75,
            'lng' => 37.62,
        ]);
        Place::create([
            ...$this->placePayload($category->id, $work->id),
            'title' => 'Работа вне карты',
            'user_id' => $owner->id,
            'status' => 'published',
            'published_at' => now(),
            'lat' => 59.93,
            'lng' => 30.31,
        ]);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/proffi/places/'.$inside->id.'/favorite')->assertOk();
        $query = http_build_query([
            'category_id' => $category->id,
            'work_id' => $work->id,
            'sw_lat' => 55.0,
            'sw_lng' => 37.0,
            'ne_lat' => 56.0,
            'ne_lng' => 38.0,
        ]);
        $this->getJson('/api/proffi/places?'.$query)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $inside->id)
            ->assertJsonPath('data.0.is_favorite', true);

        $this->getJson('/api/proffi/places?favorites=1')->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/proffi/users/'.$owner->id.'/places')->assertOk()
            ->assertJsonCount(2, 'data');
        $this->deleteJson('/api/proffi/places/'.$inside->id.'/favorite')->assertOk();
    }

    public function test_place_creates_a_prefilled_request_draft_and_source_is_published_to_task(): void
    {
        [$category, $work] = $this->catalog();
        $master = $this->user('reference-master@example.test');
        $customer = $this->user('customer@example.test');
        $place = Place::create([
            ...$this->placePayload($category->id, $work->id),
            'user_id' => $master->id,
            'status' => 'published',
            'published_at' => now(),
            'price' => 320000,
        ]);
        Sanctum::actingAs($customer);

        $created = $this->postJson('/api/proffi/places/'.$place->id.'/create-request', [
            'idempotency_key' => 'place-request-'.Str::uuid(),
        ])->assertCreated()
            ->assertJsonPath('data.draft.category.id', $category->id)
            ->assertJsonPath('data.draft.work.id', $work->id)
            ->assertJsonPath('data.draft.reference_place.id', $place->id)
            ->assertJsonPath('data.ui_action.type', 'clarify_intent');

        $draft = RequestDraft::findOrFail($created->json('data.draft.id'));
        $this->assertSame($place->id, (int) $draft->source_place_id);
        $snapshot = $draft->snapshot;
        $snapshot['title'] = 'Нужна похожая кухня';
        $snapshot['description'] = 'Нужна похожая кухня с другой планировкой.';
        $snapshot['location'] = [
            'city' => 'Москва',
            'address' => 'Тверская улица, 1',
            'lat' => 55.757,
            'lng' => 37.615,
            'confirmed' => true,
            'source' => 'user_selected',
        ];
        $draft->update(['snapshot' => $snapshot, 'status' => 'ready_for_review']);

        $this->postJson('/api/proffi/request-drafts/'.$draft->id.'/confirm', [
            'expected_version' => $draft->version,
            'consent' => true,
            'idempotency_key' => 'publish-'.Str::uuid(),
        ])->assertOk();

        $this->assertDatabaseHas('proffi_tasks', [
            'customer_id' => $customer->id,
            'source_place_id' => $place->id,
            'category_id' => $category->id,
            'work_id' => $work->id,
        ]);
    }

    public function test_only_the_accepted_master_can_create_a_place_draft_from_a_completed_task(): void
    {
        [$category, $work] = $this->catalog();
        $customer = $this->user('task-customer@example.test');
        $master = $this->user('accepted-master@example.test');
        $outsider = $this->user('outsider@example.test');
        $task = ProffiTask::create([
            'title' => 'Собрать кухню',
            'description' => 'Кухня собрана по проекту.',
            'category' => $category->id,
            'category_id' => $category->id,
            'work_id' => $work->id,
            'city' => 'Москва',
            'status' => 'done',
            'customer_id' => $customer->id,
            'accepted_specialist_id' => $master->id,
            'lat' => 55.75,
            'lng' => 37.62,
        ]);

        Sanctum::actingAs($outsider);
        $this->postJson('/api/proffi/tasks/'.$task->id.'/place-draft')->assertForbidden();

        Sanctum::actingAs($master);
        $created = $this->postJson('/api/proffi/tasks/'.$task->id.'/place-draft')
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.work.id', $work->id);

        $this->assertDatabaseHas('places', [
            'id' => $created->json('data.id'),
            'user_id' => $master->id,
            'source_task_id' => $task->id,
            'status' => 'draft',
        ]);

        $this->postJson('/api/proffi/tasks/'.$task->id.'/place-draft')->assertCreated();
        $this->assertSame(1, Place::where('source_task_id', $task->id)->where('user_id', $master->id)->count());
    }

    public function test_standard_task_creation_does_not_gain_a_source_place(): void
    {
        [$category, $work] = $this->catalog();
        $customer = $this->user('plain-customer@example.test');
        Sanctum::actingAs($customer);

        $created = $this->postJson('/api/proffi/tasks', [
            'title' => 'Обычная заявка',
            'description' => 'Создана без Place.',
            'category' => $category->id,
            'category_id' => $category->id,
            'work_id' => $work->id,
            'city' => 'Москва',
        ])->assertCreated();

        $this->assertDatabaseHas('proffi_tasks', [
            'id' => $created->json('id'),
            'source_place_id' => null,
        ]);
    }

    private function catalog(): array
    {
        $category = ProffiCategory::create([
            'id' => 'interior',
            'slug' => 'interior',
            'icon' => 'Home',
            'name_ru' => 'Интерьер',
            'name_ro' => 'Interior',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $work = ProffiWork::create([
            'category_id' => $category->id,
            'title' => 'Изготовление кухни',
            'slug' => 'custom-kitchen',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$category, $work];
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    private function placePayload(string $categoryId, int $workId): array
    {
        return [
            'title' => 'Современная кухня',
            'description' => 'Кухня по индивидуальному проекту.',
            'category_id' => $categoryId,
            'work_id' => $workId,
            'city' => 'Москва',
            'hide_price' => false,
        ];
    }
}
