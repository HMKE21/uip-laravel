<?php

namespace Database\Factories;

use App\Models\FeedPost;
use App\Models\FeedPostAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedPostAttachment>
 */
class FeedPostAttachmentFactory extends Factory
{
    protected $model = FeedPostAttachment::class;

    public function definition(): array
    {
        return [
            'post_id' => FeedPost::factory(),
            'kind' => fake()->randomElement(['image', 'video', 'pdf', 'file', 'link']),
            'file_path' => fake()->filePath(),
            'original_name' => fake()->words(2, true),
            'mime_type' => fake()->sentence(4),
            'size_bytes' => fake()->numberBetween(1, 1000),
            'external_url' => fake()->url(),
            'link_title' => fake()->sentence(4),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
