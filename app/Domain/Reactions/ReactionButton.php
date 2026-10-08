<?php

namespace App\Domain\Reactions;

/**
 * State of the thumbs-up control for one record and one viewer.
 *
 * `mode` is `reacted` (the viewer's reaction exists and can be removed),
 * `not_reacted` (the viewer can add one), or `readonly` (the viewer can see
 * the count but cannot change it). `tooltip` is null when the count is zero.
 */
final class ReactionButton
{
    public const REACTED = 'reacted';

    public const NOT_REACTED = 'not_reacted';

    public const READONLY = 'readonly';

    public function __construct(
        public readonly string $objectType,
        public readonly int $objectId,
        public readonly string $mode,
        public readonly int $count,
        public readonly ?string $tooltip,
        public readonly ?int $reactionId,
    ) {}

    public function icon(): string
    {
        return $this->mode === self::REACTED ? 'thumb-up-filled' : 'thumb-up';
    }

    /**
     * @return array{object_type: string, object_id: int, mode: string, icon: string, count: int, has_reactions: bool, tooltip: string|null, reaction_id: int|null, dom_id: string}
     */
    public function toArray(): array
    {
        return [
            'object_type' => $this->objectType,
            'object_id' => $this->objectId,
            'mode' => $this->mode,
            'icon' => $this->icon(),
            'count' => $this->count,
            'has_reactions' => $this->count > 0,
            'tooltip' => $this->tooltip,
            'reaction_id' => $this->reactionId,
            'dom_id' => self::domId($this->objectType, $this->objectId),
        ];
    }

    /**
     * Rails `dom_id(object, :reaction)`: prefix, underscored singular model
     * name, then the id.
     */
    public static function domId(string $type, int $id): string
    {
        $model = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $type));

        return 'reaction_'.$model.'_'.$id;
    }
}
