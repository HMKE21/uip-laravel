<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnnouncementAttachment>
 */
class AnnouncementAttachmentFactory extends Factory
{
    protected $model = AnnouncementAttachment::class;

    public function definition(): array
    {
        return [
            'announcement_id' => Announcement::factory(),
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
