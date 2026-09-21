<?php

namespace Database\Factories;

use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = '2026-09-21';

        return [
            'date' => $date,
            'planned_hours' => 8,
            'hours' => 8,
            'note' => null,
            'status' => TimeEntryStatus::Submitted,
            'is_unplanned' => false,
            'identity_key' => fake()->unique()->uuid(),
            'submitted_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TimeEntryStatus::Approved,
            'reviewed_at' => now(),
            'processed_at' => now(),
        ]);
    }
}
