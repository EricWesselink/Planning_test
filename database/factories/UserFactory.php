<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Planner,
            'active' => true,
            'can_access_all_projects' => true,
            'worker_id' => null,
            'crew_member_id' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function uitvoerder(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Uitvoerder,
        ]);
    }

    public function projectleider(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Projectleider,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }

    public function limitedAccess(): static
    {
        return $this->state(fn (array $attributes) => [
            'can_access_all_projects' => false,
        ]);
    }

    public function vakman(?int $workerId = null, ?int $crewMemberId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Vakman,
            'can_access_all_projects' => false,
            'worker_id' => $workerId,
            'crew_member_id' => $crewMemberId,
        ]);
    }
}
