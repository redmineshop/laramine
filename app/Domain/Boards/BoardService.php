<?php

namespace App\Domain\Boards;

use App\Domain\Acl\ModuleGate;
use App\Domain\Attachments\AttachmentService;
use App\Domain\DomainException;
use App\Domain\Reactions\ReactionService;
use App\Domain\Watchers\WatcherLedger;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Nested forum boards.
 *
 * A disabled `boards` module denies every user, including an active administrator.
 */
final class BoardService
{
    public function __construct(
        private readonly ModuleGate $gate,
        private readonly AttachmentService $files,
        private readonly WatcherLedger $watchers,
    ) {}

    public function create(
        User $actor,
        Project $project,
        string $name,
        ?string $description,
        ?int $parentId,
        ?int $position,
    ): Board {
        $this->gate->allow($actor, $project, 'boards', 'manage_boards');
        $storedName = $this->name($name);
        $storedDescription = $this->description($description);

        return DB::transaction(function () use ($project, $storedName, $storedDescription, $parentId, $position): Board {
            $this->assertParent($project, null, $parentId);
            $slot = $position ?? $this->nextPosition($project);

            return Board::query()->create([
                'project_id' => $project->id,
                'name' => $storedName,
                'description' => $storedDescription,
                'parent_id' => $parentId,
                'position' => $slot,
                'topics_count' => 0,
                'messages_count' => 0,
                'last_message_id' => null,
            ]);
        });
    }

    public function update(
        User $actor,
        Board $board,
        string $name,
        ?string $description,
        ?int $parentId,
        ?int $position,
    ): Board {
        $project = $this->projectOf($board);
        $this->gate->allow($actor, $project, 'boards', 'manage_boards');
        $storedName = $this->name($name);
        $storedDescription = $this->description($description);
        DB::transaction(function () use ($project, $board, $storedName, $storedDescription, $parentId, $position): void {
            $this->assertParent($project, $board, $parentId);
            $board->name = $storedName;
            $board->description = $storedDescription;
            $board->parent_id = $parentId;
            if ($position !== null) {
                $board->position = $position;
            }
            $board->save();
        });

        return $board->refresh();
    }

    public function delete(User $actor, Board $board): void
    {
        $project = $this->projectOf($board);
        $this->gate->allow($actor, $project, 'boards', 'manage_boards');
        DB::transaction(function () use ($board): void {
            Board::query()->where('parent_id', $board->id)->update(['parent_id' => null]);
            foreach (Message::query()->where('board_id', $board->id)->get() as $message) {
                $this->forgetMessage((int) $message->id);
                $message->delete();
            }
            $board->delete();
        });
    }

    /**
     * @return list<array{id: int, name: string, description: string|null, parent_id: int|null, position: int|null, topics_count: int, messages_count: int, last_message_id: int|null}>
     */
    public function index(?User $actor, Project $project): array
    {
        $this->gate->allow($actor, $project, 'boards', 'view_messages');
        $rows = [];
        foreach (
            Board::query()
                ->where('project_id', $project->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get() as $board
        ) {
            $rows[] = [
                'id' => (int) $board->id,
                'name' => (string) $board->name,
                'description' => is_string($board->description) ? $board->description : null,
                'parent_id' => $board->parent_id === null ? null : (int) $board->parent_id,
                'position' => $board->position === null ? null : (int) $board->position,
                'topics_count' => (int) $board->topics_count,
                'messages_count' => (int) $board->messages_count,
                'last_message_id' => $board->last_message_id === null ? null : (int) $board->last_message_id,
            ];
        }

        return $rows;
    }

    public function recount(Board $board): void
    {
        $latest = Message::query()
            ->where('board_id', $board->id)
            ->orderByDesc('created_on')
            ->orderByDesc('id')
            ->first();
        $board->topics_count = Message::query()->where('board_id', $board->id)->whereNull('parent_id')->count();
        $board->messages_count = Message::query()->where('board_id', $board->id)->count();
        $board->last_message_id = $latest instanceof Message ? (int) $latest->id : null;
        $board->save();
    }

    private function assertParent(Project $project, ?Board $board, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($board instanceof Board && $parentId === (int) $board->id) {
            throw new DomainException('A board cannot be its own parent.');
        }
        $parent = Board::query()->where('project_id', $project->id)->find($parentId);
        if (! $parent instanceof Board) {
            throw new DomainException('Parent board is not in this project.');
        }
        $cursor = $parent;
        $seen = [];
        while (true) {
            $id = (int) $cursor->id;
            if ($board instanceof Board && $id === (int) $board->id) {
                throw new DomainException('Parent board would create a cycle.');
            }
            if (isset($seen[$id]) || $cursor->parent_id === null) {
                return;
            }
            $seen[$id] = true;
            $next = Board::query()->find($cursor->parent_id);
            if (! $next instanceof Board) {
                return;
            }
            $cursor = $next;
        }
    }

    private function nextPosition(Project $project): int
    {
        $max = Board::query()->where('project_id', $project->id)->max('position');

        return is_numeric($max) ? ((int) $max) + 1 : 1;
    }

    private function projectOf(Board $board): Project
    {
        $project = Project::query()->find($board->project_id);
        if (! $project instanceof Project) {
            throw new DomainException('Board does not exist.');
        }

        return $project;
    }

    private function name(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new DomainException('Board name is empty.');
        }
        if (strlen($trimmed) > 255) {
            throw new DomainException('Board name is too long.');
        }

        return $trimmed;
    }

    private function description(?string $description): ?string
    {
        if ($description === null || $description === '') {
            return null;
        }
        if (strlen($description) > 255) {
            throw new DomainException('Board description is too long.');
        }

        return $description;
    }

    private function forgetMessage(int $messageId): void
    {
        foreach (
            Attachment::query()
                ->where('container_type', 'Message')
                ->where('container_id', $messageId)
                ->get() as $attachment
        ) {
            $this->files->forgetFile($attachment);
            $attachment->delete();
        }
        $this->watchers->forget(WatcherLedger::MESSAGE, $messageId);
        ReactionService::forget('Message', [$messageId]);
    }
}
