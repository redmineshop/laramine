<?php

namespace App\Domain\Activity;

/**
 * One activity row the page and the Atom feed both render.
 */
final readonly class ActivityEvent
{
    public function __construct(
        public string $kind,
        public int $id,
        public string $project,
        public string $author,
        public string $title,
        public string $at,
    ) {}

    /**
     * @return array{kind: string, id: int, project: string, author: string, title: string, at: string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'project' => $this->project,
            'author' => $this->author,
            'title' => $this->title,
            'at' => $this->at,
        ];
    }
}
