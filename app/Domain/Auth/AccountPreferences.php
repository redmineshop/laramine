<?php

namespace App\Domain\Auth;

use App\Domain\PermissionDeniedException;
use App\Models\User;
use App\Models\UserPreference;

/**
 * Mail-notification value and the `user_preferences` row.
 *
 * Mail is not delivered.
 */
final class AccountPreferences
{
    public function __construct(
        private readonly PreferenceCodec $codec,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, User $subject, array $input): UserPreference
    {
        $this->assertCanEdit($actor, $subject);
        if ($subject->type !== User::TYPE_USER) {
            throw new AccountValidationException([
                'user' => ['Preferences belong to a user account.'],
            ]);
        }

        if (array_key_exists('mail_notification', $input)) {
            $notification = $input['mail_notification'];
            if (! is_string($notification) || ! MailNotification::valid($notification)) {
                throw new AccountValidationException([
                    'mail_notification' => ['Mail notification must be one of the six values.'],
                ]);
            }
            $subject->forceFill(['mail_notification' => $notification])->save();
        }

        $preference = UserPreference::query()->where('user_id', $subject->id)->first();
        if (! $preference instanceof UserPreference) {
            $preference = new UserPreference([
                'user_id' => $subject->id,
                'hide_mail' => true,
            ]);
        }

        if (array_key_exists('hide_mail', $input)) {
            $preference->hide_mail = $this->flag($input['hide_mail'], 'hide_mail');
        }
        if (array_key_exists('time_zone', $input)) {
            $preference->time_zone = $this->timeZone($input['time_zone']);
        }
        if (array_key_exists('others', $input)) {
            $others = $input['others'];
            if (! is_array($others)) {
                throw new AccountValidationException([
                    'others' => ['Preferences must be an object.'],
                ]);
            }
            /** @var array<string, mixed> $others */
            $preference->others = $this->codec->encode($others);
        }

        $preference->save();

        return $preference->refresh();
    }

    private function assertCanEdit(User $actor, User $subject): void
    {
        if ((int) $actor->id === (int) $subject->id && $actor->canKeepWebSession()) {
            return;
        }
        if ($actor->type === User::TYPE_USER && $actor->isActive() && $actor->admin === true) {
            return;
        }

        throw new PermissionDeniedException('admin');
    }

    private function flag(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        throw new AccountValidationException([
            $field => ['The value must be true or false.'],
        ]);
    }

    private function timeZone(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! in_array($value, timezone_identifiers_list(), true)) {
            throw new AccountValidationException([
                'time_zone' => ['Time zone is not recognized.'],
            ]);
        }

        return $value;
    }
}
