<?php

namespace Database\Factories;

use App\Models\LeaveRequest;
use App\Models\LeaveRequestMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequestMessage>
 */
class LeaveRequestMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'leave_request_id' => LeaveRequest::factory(),
            'user_id' => User::factory(),
            'body' => 'Kun je eventueel een andere dag vrij nemen?',
            'is_system' => false,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_system' => true,
        ]);
    }
}
