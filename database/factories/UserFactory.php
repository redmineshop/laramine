<?php

namespace Database\Factories;

use App\Domain\Auth\RedminePassword;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `hashed_password` is a 40-zero placeholder. It is not a credential.
     * Call `withPassword()` when a test must sign in.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'login' => fake()->unique()->userName(),
            'hashed_password' => RedminePassword::PLACEHOLDER_HASH,
            'firstname' => mb_substr(fake()->firstName(), 0, 30),
            'lastname' => mb_substr(fake()->lastName(), 0, 255),
            'mail_notification' => '',
            'admin' => false,
            'status' => 1,
            'language' => 'en',
            'type' => 'User',
            'must_change_passwd' => false,
        ];
    }

    public function withPassword(string $password): static
    {
        return $this->state(function () use ($password): array {
            return (new RedminePassword)->seal($password);
        });
    }
}
