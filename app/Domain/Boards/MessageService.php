<?php

namespace App\Domain\Boards;

use App\Domain\Acl\ModuleGate;
use App\Domain\Acl\PermissionService;
use App\Domain\Attachments\AttachmentService;
use App\Domain\Attachments\AttachmentThumbnailRenderer;
use App\Domain\Attachments\UnboundAttachment;
use App\Domain\DomainException;
use App\Domain\Issues\JournalQuoteText;
use App\Domain\PermissionDeniedException;
use App\Domain\TextFormatting\FormattedText;
use App\Domain\TextFormatting\FormattingContext;
use App\Domain\Watchers\WatcherLedger;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Forum topics and replies, sticky and locked flags, counters, and watchers.
 *
 * Replies are flat under the topic. A locked topic accepts a reply only from
 * someone who may `edit_messages`.
 */
final class MessageService
{
    public function __construct(
        private readonly ModuleGate $gate,
        private readonly PermissionService $permissions,
        private readonly BoardService $boards,
        private readonly AttachmentService $files,
        private readonly AttachmentThumbnailRenderer $thumbnails,
        private readonly UnboundAttachment $tokens,
        private readonly WatcherLedger $watchers,
        private readonly MessageNotifier $notifications,
        private readonly FormattedText $formatted,
        private readonly JournalQuoteText $quotes,
    ) {}

    public function html(?User $actor, Message $message): string
    {
        $board = Board::query()->find($message->board_id);
        if (! $board instanceof Board) {
            throw new DomainException('Message has no board.');
        }
        $project = Project::query()->find($board->project_id);
        if (! $project instanceof Project) {
            throw new DomainException('Message has no project.');
        }
        $this->gate->allow($actor, $project, 'boards', 'view_messages');

        return $this->formatted->message($message, $project, false, $actor);
    }

    public function postTopic(
        User $actor,
        Board $board,
        string $subject,
        ?string $content,
        int $sticky = 0,
        bool $locked = false,
        bool $notify = true,
    ): Message {
        $project = $this->projectOf($board);
        $this->gate->allow($actor, $project, 'boards', 'add_messages');
        if ($sticky !== 0 || $locked) {
            $this->gate->allow($actor, $project, 'boards', 'edit_messages');
        }
        $storedSubject = $this->subject($subject);
        $storedSticky = $this->sticky($sticky);
        $message = null;
        DB::transaction(function () use ($actor, $board, $storedSubject, $content, $storedSticky, $locked, &$message): void {
            $message = Message::query()->create([
                'board_id' => $board->id,
                'parent_id' => null,
                'author_id' => $actor->id,
                'subject' => $storedSubject,
                'content' => $this->content($content),
                'sticky' => $storedSticky,
                'locked' => $locked,
                'replies_count' => 0,
                'last_reply_id' => null,
                'created_on' => now(),
                'updated_on' => now(),
            ]);
            $this->boards->recount($board);
        });
        if (! $message instanceof Message) {
            throw new DomainException('Message could not be stored.');
        }
        $fresh = $message->refresh();
        if ($notify) {
            $this->notifications->posted($actor, $project, $board, $fresh, $fresh);
        }

        return $fresh;
    }

    public function reply(User $actor, Message $topic, ?string $subject, ?string $content, bool $notify = true): Message
    {
        $root = $this->root($topic);
        $board = $this->boardOf($root);
        $project = $this->projectOf($board);
        $this->gate->allow($actor, $project, 'boards', 'add_messages');
        if ((bool) $root->locked && ! $this->permissions->allowed($actor, 'edit_messages', $project)) {
            throw new PermissionDeniedException('edit_messages');
        }
        if (! $project->isModuleEnabled('boards')) {
            throw new PermissionDeniedException('add_messages');
        }
        $storedSubject = $subject === null || trim($subject) === ''
            ? $this->replySubject((string) $root->subject)
            : $this->subject($subject);
        $reply = null;
        DB::transaction(function () use ($actor, $board, $root, $storedSubject, $content, &$reply): void {
            $reply = Message::query()->create([
                'board_id' => $board->id,
                'parent_id' => $root->id,
                'author_id' => $actor->id,
                'subject' => $storedSubject,
                'content' => $this->content($content),
                'sticky' => 0,
                'locked' => false,
                'replies_count' => 0,
                'last_reply_id' => null,
                'created_on' => now(),
                'updated_on' => now(),
            ]);
            $this->recountTopic($root);
            $this->boards->recount($board);
        });
        if (! $reply instanceof Message) {
            throw new DomainException('Message could not be stored.');
        }
        $fresh = $reply->refresh();
        if ($notify) {
            $this->notifications->posted($actor, $project, $board, $fresh, $root->refresh());
        }

        return $fresh;
    }

    public function update(
        User $actor,
        Message $message,
        string $subject,
        ?string $content,
        ?int $sticky,
        ?bool $locked,
    ): Message {
        $root = $message->isTopic() ? $message : $this->root($message);
        $board = $this->boardOf($message);
        $project = $this->projectOf($board);
        $this->assertCanEdit($actor, $project, $message, $sticky, $locked);
        if (! $message->isTopic() && ($sticky !== null || $locked !== null)) {
            throw new DomainException('A reply cannot be sticky or locked.');
        }
        $message->subject = $this->subject($subject);
        $message->content = $this->content($content);
        if ($message->isTopic() && $sticky !== null) {
            $message->sticky = $this->sticky($sticky);
        }
        if ($message->isTopic() && $locked !== null) {
            $message->locked = $locked;
        }
        $message->save();

        return $message->refresh();
    }

    public function delete(User $actor, Message $message): void
    {
        $board = $this->boardOf($message);
        $project = $this->projectOf($board);
        $this->assertCanDelete($actor, $project, $message);
        DB::transaction(function () use ($message, $board): void {
            if ($message->isTopic()) {
                foreach (Message::query()->where('parent_id', $message->id)->get() as $reply) {
                    $this->forgetMessage((int) $reply->id);
                    $reply->delete();
                }
                $this->forgetMessage((int) $message->id);
                $message->delete();
            } else {
                $parentId = (int) $message->parent_id;
                $this->forgetMessage((int) $message->id);
                $message->delete();
                $topic = Message::query()->find($parentId);
                if ($topic instanceof Message) {
                    $this->recountTopic($topic);
                }
            }
            $this->boards->recount($board);
        });
    }

    /**
     * @return list<array{id: int, subject: string, author_id: int|null, sticky: int, locked: bool, replies_count: int, last_reply_id: int|null, created_on: string, updated_on: string}>
     */
    public function topics(?User $actor, Board $board): array
    {
        $this->gate->allow($actor, $this->projectOf($board), 'boards', 'view_messages');
        $rows = [];
        foreach (
            Message::query()
                ->where('board_id', $board->id)
                ->whereNull('parent_id')
                ->orderByDesc('sticky')
                ->orderByDesc('updated_on')
                ->orderByDesc('id')
                ->get() as $topic
        ) {
            $rows[] = $this->row($topic);
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, subject: string, author_id: int|null, content: string|null, created_on: string}>
     */
    public function replies(?User $actor, Message $topic): array
    {
        $root = $this->root($topic);
        $this->gate->allow($actor, $this->projectOf($this->boardOf($root)), 'boards', 'view_messages');
        $rows = [];
        foreach (
            Message::query()
                ->where('parent_id', $root->id)
                ->orderBy('created_on')
                ->orderBy('id')
                ->get() as $reply
        ) {
            $created = $reply->getAttribute('created_on');
            $rows[] = [
                'id' => (int) $reply->id,
                'subject' => (string) $reply->subject,
                'author_id' => is_numeric($reply->author_id) ? (int) $reply->author_id : null,
                'content' => is_string($reply->content) ? $reply->content : null,
                'created_on' => $created instanceof DateTimeInterface ? $created->format('Y-m-d H:i:s') : '',
            ];
        }

        return $rows;
    }

    public function attach(User $actor, Message $message, string $token, ?string $filename, ?string $description): Attachment
    {
        $project = $this->projectOf($this->boardOf($message));
        $this->assertCanEdit($actor, $project, $message, null, null);
        $attachment = $this->tokens->find($token);

        return DB::transaction(function () use ($attachment, $message, $filename, $description): Attachment {
            $locked = $this->tokens->lock($attachment);
            $this->files->retitle($locked, $filename, $description);
            $this->files->bind($locked, $message);

            return $locked->refresh();
        });
    }

    public function deleteAttachment(User $actor, Attachment $attachment): void
    {
        if ((string) $attachment->container_type !== 'Message') {
            throw new DomainException('Attachment is not attached.');
        }
        $message = Message::query()->find($attachment->container_id);
        if (! $message instanceof Message) {
            throw new DomainException('Attachment is not attached.');
        }
        $project = $this->projectOf($this->boardOf($message));
        $this->gate->allow($actor, $project, 'boards', 'edit_messages');
        DB::transaction(function () use ($attachment): void {
            $locked = Attachment::query()->whereKey($attachment->id)->lockForUpdate()->first();
            if (! $locked instanceof Attachment) {
                throw new DomainException('Attachment does not exist.');
            }
            $this->thumbnails->forget($locked);
            $this->files->forgetFile($locked);
            $locked->delete();
        });
    }

    public function quote(User $actor, Message $message): string
    {
        $root = $this->root($message);
        $project = $this->projectOf($this->boardOf($root));
        $this->gate->allow($actor, $project, 'boards', 'add_messages');
        if ((bool) $root->locked && ! $this->permissions->allowed($actor, 'edit_messages', $project)) {
            throw new PermissionDeniedException('edit_messages');
        }
        if (! $project->isModuleEnabled('boards')) {
            throw new PermissionDeniedException('add_messages');
        }
        $author = User::query()->find($message->author_id);
        $name = 'User';
        if ($author instanceof User) {
            $name = $this->quotes->authorName($author->firstname, $author->lastname, $author->login);
        }
        $content = is_string($message->content) ? $message->content : '';

        return $this->quotes->render($name, $content);
    }

    public function preview(User $actor, Board $board, string $text): string
    {
        $project = $this->projectOf($board);
        $this->gate->allow($actor, $project, 'boards', 'add_messages');

        return $this->formatted->html($text, new FormattingContext($project, $board, false, false, $actor));
    }

    public function canEdit(User $actor, Message $message): bool
    {
        try {
            $this->assertCanEdit($actor, $this->projectOf($this->boardOf($message)), $message, null, null);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    public function canDelete(User $actor, Message $message): bool
    {
        try {
            $this->assertCanDelete($actor, $this->projectOf($this->boardOf($message)), $message);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    public function canReply(User $actor, Message $message): bool
    {
        try {
            $root = $this->root($message);
            $project = $this->projectOf($this->boardOf($root));
            $this->gate->allow($actor, $project, 'boards', 'add_messages');
            if ((bool) $root->locked && ! $this->permissions->allowed($actor, 'edit_messages', $project)) {
                return false;
            }

            return $project->isModuleEnabled('boards');
        } catch (DomainException) {
            return false;
        }
    }

    public function isWatching(User $actor, Message $message): bool
    {
        $root = $this->root($message);
        $this->gate->allow($actor, $this->projectOf($this->boardOf($root)), 'boards', 'view_messages');

        return in_array((int) $actor->id, $this->watchers->userIds(WatcherLedger::MESSAGE, (int) $root->id), true);
    }

    public function watch(User $actor, Message $message): void
    {
        $root = $this->root($message);
        $this->gate->allow($actor, $this->projectOf($this->boardOf($root)), 'boards', 'view_messages');
        $this->watchers->add($actor, WatcherLedger::MESSAGE, (int) $root->id);
    }

    public function unwatch(User $actor, Message $message): void
    {
        $root = $this->root($message);
        $this->gate->allow($actor, $this->projectOf($this->boardOf($root)), 'boards', 'view_messages');
        $this->watchers->remove($actor, WatcherLedger::MESSAGE, (int) $root->id);
    }

    public function addWatcher(User $actor, Message $message, User $target): void
    {
        $root = $this->root($message);
        $project = $this->projectOf($this->boardOf($root));
        $this->gate->allow($actor, $project, 'boards', 'add_message_watchers');
        $this->gate->allow($target, $project, 'boards', 'view_messages');
        $this->watchers->add($target, WatcherLedger::MESSAGE, (int) $root->id);
    }

    public function removeWatcher(User $actor, Message $message, User $target): void
    {
        $root = $this->root($message);
        $project = $this->projectOf($this->boardOf($root));
        if ((int) $actor->id !== (int) $target->id) {
            $this->gate->allow($actor, $project, 'boards', 'delete_message_watchers');
        } else {
            $this->gate->allow($actor, $project, 'boards', 'view_messages');
        }
        $this->watchers->remove($target, WatcherLedger::MESSAGE, (int) $root->id);
    }

    /**
     * @return list<int>
     */
    public function watcherIds(User $actor, Message $message): array
    {
        $root = $this->root($message);
        $this->gate->allow($actor, $this->projectOf($this->boardOf($root)), 'boards', 'view_message_watchers');

        return $this->watchers->userIds(WatcherLedger::MESSAGE, (int) $root->id);
    }

    private function assertCanEdit(User $actor, Project $project, Message $message, ?int $sticky, ?bool $locked): void
    {
        if (! $project->isModuleEnabled('boards')) {
            throw new PermissionDeniedException('edit_messages');
        }
        $moderating = ($sticky !== null && $sticky !== (int) $message->sticky)
            || ($locked !== null && $locked !== (bool) $message->locked);
        if ($moderating) {
            $this->gate->allow($actor, $project, 'boards', 'edit_messages');

            return;
        }
        if ($this->permissions->allowed($actor, 'edit_messages', $project)) {
            return;
        }
        if ((int) $message->author_id === (int) $actor->id
            && $this->permissions->allowed($actor, 'edit_own_messages', $project)) {
            return;
        }
        throw new PermissionDeniedException('edit_messages');
    }

    private function assertCanDelete(User $actor, Project $project, Message $message): void
    {
        if (! $project->isModuleEnabled('boards')) {
            throw new PermissionDeniedException('delete_messages');
        }
        if ($this->permissions->allowed($actor, 'delete_messages', $project)) {
            return;
        }
        if ((int) $message->author_id === (int) $actor->id
            && $this->permissions->allowed($actor, 'delete_own_messages', $project)) {
            return;
        }
        throw new PermissionDeniedException('delete_messages');
    }

    private function recountTopic(Message $topic): void
    {
        $latest = Message::query()
            ->where('parent_id', $topic->id)
            ->orderByDesc('created_on')
            ->orderByDesc('id')
            ->first();
        $topic->replies_count = Message::query()->where('parent_id', $topic->id)->count();
        $topic->last_reply_id = $latest instanceof Message ? (int) $latest->id : null;
        $topic->updated_on = $latest instanceof Message ? $latest->created_on : $topic->created_on;
        $topic->save();
    }

    private function root(Message $message): Message
    {
        if ($message->isTopic()) {
            return $message;
        }
        $parent = Message::query()->find($message->parent_id);
        if (! $parent instanceof Message || ! $parent->isTopic()) {
            throw new DomainException('Message does not exist.');
        }

        return $parent;
    }

    private function boardOf(Message $message): Board
    {
        $board = Board::query()->find($message->board_id);
        if (! $board instanceof Board) {
            throw new DomainException('Board does not exist.');
        }

        return $board;
    }

    private function projectOf(Board $board): Project
    {
        $project = Project::query()->find($board->project_id);
        if (! $project instanceof Project) {
            throw new DomainException('Board does not exist.');
        }

        return $project;
    }

    /**
     * @return array{id: int, subject: string, author_id: int|null, sticky: int, locked: bool, replies_count: int, last_reply_id: int|null, created_on: string, updated_on: string}
     */
    private function row(Message $topic): array
    {
        $created = $topic->getAttribute('created_on');
        $updated = $topic->getAttribute('updated_on');

        return [
            'id' => (int) $topic->id,
            'subject' => (string) $topic->subject,
            'author_id' => is_numeric($topic->author_id) ? (int) $topic->author_id : null,
            'sticky' => (int) $topic->sticky,
            'locked' => (bool) $topic->locked,
            'replies_count' => (int) $topic->replies_count,
            'last_reply_id' => $topic->last_reply_id === null ? null : (int) $topic->last_reply_id,
            'created_on' => $created instanceof DateTimeInterface ? $created->format('Y-m-d H:i:s') : '',
            'updated_on' => $updated instanceof DateTimeInterface ? $updated->format('Y-m-d H:i:s') : '',
        ];
    }

    private function subject(string $subject): string
    {
        $trimmed = trim($subject);
        if ($trimmed === '') {
            throw new DomainException('Message subject is empty.');
        }
        if (strlen($trimmed) > 255) {
            throw new DomainException('Message subject is too long.');
        }

        return $trimmed;
    }

    private function replySubject(string $topicSubject): string
    {
        $subject = 'Re: '.$topicSubject;
        if (strlen($subject) <= 255) {
            return $subject;
        }

        return substr($subject, 0, 255);
    }

    private function content(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }

        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    private function sticky(int $sticky): int
    {
        if ($sticky !== 0 && $sticky !== 1) {
            throw new DomainException('Message sticky must be 0 or 1.');
        }

        return $sticky;
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
    }
}
