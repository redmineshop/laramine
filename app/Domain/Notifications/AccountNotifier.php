<?php

namespace App\Domain\Notifications;

use App\Models\Token;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Account registration, activation, recovery, and lock or unlock notices.
 *
 * Automatic registration does not send mail. News and the other unbuilt
 * module events are not sent from here.
 */
final class AccountNotifier
{
    public function __construct(
        private readonly IssueRecipientResolver $addresses,
        private readonly MailIdentity $identity,
        private readonly OutboundMail $mail,
    ) {}

    public function activation(User $user, Token $token): void
    {
        $this->send(
            $user,
            $user,
            'Account activation',
            "Login: {$user->login}\nToken: {$token->value}\nPath: /account/activate?token={$token->value}\n",
            'activation',
            $token,
        );
    }

    public function activationRequest(User $registered): void
    {
        $admins = User::query()
            ->where('type', User::TYPE_USER)
            ->where('admin', true)
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();
        $body = "Login: {$registered->login}\nUser: {$registered->id}\n";
        foreach ($admins as $admin) {
            $this->send($admin, $registered, 'New user account', $body, 'activation-request', null);
        }
    }

    public function activated(User $actor, User $user): void
    {
        $this->send(
            $user,
            $actor,
            'Account activated',
            "Login: {$user->login}\nStatus: active\n",
            'activated',
            null,
        );
    }

    public function information(User $actor, User $user, string $password): void
    {
        $this->send(
            $user,
            $actor,
            'Account information',
            "Login: {$user->login}\nPassword: {$password}\n",
            'information',
            null,
        );
    }

    public function lostPassword(User $user, Token $token): void
    {
        $this->send(
            $user,
            $user,
            'Lost password',
            "Login: {$user->login}\nToken: {$token->value}\nPath: /account/lost_password?token={$token->value}\n",
            'recovery',
            $token,
        );
    }

    public function locked(User $actor, User $user): void
    {
        $this->send(
            $user,
            $actor,
            'Account locked',
            "Login: {$user->login}\nStatus: locked\n",
            'locked',
            null,
        );
    }

    public function unlocked(User $actor, User $user): void
    {
        $this->send(
            $user,
            $actor,
            'Account unlocked',
            "Login: {$user->login}\nStatus: active\n",
            'unlocked',
            null,
        );
    }

    private function send(User $recipient, User $sender, string $subject, string $body, string $action, ?Token $token): void
    {
        $stamp = $token instanceof Token ? $token->created_on : Carbon::now();
        $created = $recipient->created_on instanceof DateTimeInterface ? $recipient->created_on : $stamp;
        $messageId = $this->identity->messageId('account-'.$recipient->id.'.'.$action, $token instanceof Token ? (int) $token->id : (int) $recipient->id, $stamp);
        $references = [$this->identity->messageId('user', (int) $recipient->id, $created)];
        $headers = $this->identity->commonHeaders((string) $sender->login);
        foreach ($this->addresses->addresses($recipient) as $address) {
            $this->mail->queue($address, $subject, $body, $messageId, $references, $headers);
        }
    }
}
