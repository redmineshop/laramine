<?php

namespace Database\Factories;

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
     * `hashed_password` is a 40-character placeholder. Redmine's hash-and-salt
     * algorithm is not applied by this factory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'login' => fake()->unique()->userName(),
            'hashed_password' => str_repeat('0', 40),
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
}
