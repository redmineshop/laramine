<?php

namespace App\Domain\Attachments;

use App\Domain\DomainException;
use App\Models\Attachment;

/**
 * Finds an upload token that is not bound to a container yet.
 *
 * The token is `{id}.{digest}`, the same shape the issue claim route uses.
 */
final class UnboundAttachment
{
    public function find(string $token): Attachment
    {
        if (preg_match('/^(\d+)\.([a-f0-9]{64})$/', $token, $match) !== 1) {
            throw new DomainException('Attachment token is invalid.');
        }
        $attachment = Attachment::query()->find((int) $match[1]);
        $stored = $attachment instanceof Attachment ? (string) $attachment->digest : '';
        if (! $attachment instanceof Attachment || strlen($stored) !== strlen($match[2]) || ! hash_equals($stored, $match[2]) || ! $this->unbound($attachment)) {
            throw new DomainException('Attachment token is invalid.');
        }

        return $attachment;
    }

    public function lock(Attachment $attachment): Attachment
    {
        $locked = Attachment::query()->whereKey($attachment->id)->lockForUpdate()->first();
        if (! $locked instanceof Attachment || ! $this->unbound($locked)) {
            throw new DomainException('Attachment token is invalid.');
        }
        if (! hash_equals((string) $attachment->digest, (string) $locked->digest)) {
            throw new DomainException('Attachment token is invalid.');
        }

        return $locked;
    }

    private function unbound(Attachment $attachment): bool
    {
        $type = $attachment->container_type;

        return ($type === null || $type === '') && $attachment->container_id === null;
    }
}
