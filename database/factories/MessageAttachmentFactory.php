<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageAttachment>
 */
class MessageAttachmentFactory extends Factory
{
    protected $model = MessageAttachment::class;

    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'uploader_id' => User::factory(),
            'kind' => fake()->word(),
            'stored_path' => fake()->filePath(),
            'thumbnail_path' => fake()->filePath(),
            'original_name' => fake()->words(2, true),
            'mime_type' => fake()->sentence(4),
            'extension' => fake()->word(),
            'size_bytes' => fake()->numberBetween(1, 1000),
            'width' => fake()->numberBetween(1, 1000),
            'height' => fake()->numberBetween(1, 1000),
            'duration_seconds' => fake()->numberBetween(1, 1000),
            'waveform_json' => [],
            'is_encrypted' => fake()->boolean(),
        ];
    }
}
