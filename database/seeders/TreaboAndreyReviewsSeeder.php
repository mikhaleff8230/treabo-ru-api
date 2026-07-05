<?php

namespace Database\Seeders;

use App\Models\ProffiReview;
use App\Models\ProffiTask;
use Illuminate\Database\Seeder;
use Marvel\Database\Models\User;

class TreaboAndreyReviewsSeeder extends Seeder
{
    public function run(): void
    {
        $master = User::where('email', 'local.master@treabo.local')->first();
        $customer = User::where('email', 'local.customer@treabo.local')->first();

        if (!$master || !$customer) {
            return;
        }

        $task = ProffiTask::where('customer_id', $customer->id)->first();

        ProffiReview::updateOrCreate(
            [
                'specialist_id' => $master->id,
                'customer_id' => $customer->id,
                'task_id' => $task?->id,
            ],
            [
                'rating' => 5,
                'comment' => 'Андрей отлично справился с покраской стен: аккуратно, в срок, без лишней пыли.',
                'photos' => [
                    'https://images.unsplash.com/photo-1581578731548-1f77553f2a59?auto=format&fit=crop&w=800&q=80',
                ],
            ],
        );

        ProffiReview::updateOrCreate(
            [
                'specialist_id' => $master->id,
                'customer_id' => $customer->id,
                'task_id' => null,
            ],
            [
                'rating' => 4,
                'comment' => 'Хороший мастер, всё объяснил и сделал качественно. Рекомендую.',
                'photos' => [
                    'https://images.unsplash.com/photo-1503387762-592deb58ef4e?auto=format&fit=crop&w=800&q=80',
                ],
            ],
        );
    }
}
