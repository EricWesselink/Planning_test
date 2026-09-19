<?php

namespace Database\Factories;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfDay()->next('Monday');

        return [
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->toDateString(),
            'note' => null,
            'status' => LeaveRequestStatus::Pending,
            'submitted_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LeaveRequestStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'worker_availability_id' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LeaveRequestStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LeaveRequestStatus::Rejected,
            'reviewed_at' => now(),
            'rejection_reason' => 'Te druk op de bouw.',
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LeaveRequestStatus::Withdrawn,
            'reviewed_at' => now(),
        ]);
    }
}
