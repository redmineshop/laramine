<?php

namespace App\Domain\TextFormatting;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Object and project a formatted string belongs to.
 *
 * Absolute links are for mail. Section edit links are for a wiki page the
 * viewer may edit. includeStack stops a page from including itself.
 */
final class FormattingContext
{
    /**
     * @param  list<int>  $includeStack
     */
    public function __construct(
        public readonly ?Project $project = null,
        public readonly ?Model $object = null,
        public readonly bool $absolute = false,
        public readonly bool $sectionEdit = false,
        public readonly ?User $viewer = null,
        public readonly array $includeStack = [],
        public readonly bool $headingAnchors = true,
    ) {}

    public function withInclude(int $pageId): self
    {
        return new self(
            $this->project,
            $this->object,
            $this->absolute,
            $this->sectionEdit,
            $this->viewer,
            [...$this->includeStack, $pageId],
            $this->headingAnchors,
        );
    }

    public function forIncludedPage(Project $project, Model $object, int $pageId): self
    {
        return new self(
            $project,
            $object,
            $this->absolute,
            false,
            $this->viewer,
            [...$this->includeStack, $pageId],
            false,
        );
    }
}
