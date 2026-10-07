<?php

namespace App\Domain\Notifications;

/**
 * Core notification event names.
 *
 * Issue events are emitted by this tree. News, document, file, message, and
 * wiki events are named so the setting can store them, and nothing here
 * sends those messages.
 */
final class NotifiedEventCatalog
{
    public const ISSUE_ADDED = 'issue_added';

    public const ISSUE_UPDATED = 'issue_updated';

    public const ISSUE_NOTE_ADDED = 'issue_note_added';

    public const ISSUE_STATUS_UPDATED = 'issue_status_updated';

    public const ISSUE_ASSIGNED_TO_UPDATED = 'issue_assigned_to_updated';

    public const ISSUE_PRIORITY_UPDATED = 'issue_priority_updated';

    public const ISSUE_FIXED_VERSION_UPDATED = 'issue_fixed_version_updated';

    public const ISSUE_ATTACHMENT_ADDED = 'issue_attachment_added';

    public const NEWS_ADDED = 'news_added';

    public const NEWS_COMMENT_ADDED = 'news_comment_added';

    public const DOCUMENT_ADDED = 'document_added';

    public const FILE_ADDED = 'file_added';

    public const MESSAGE_POSTED = 'message_posted';

    public const WIKI_CONTENT_ADDED = 'wiki_content_added';

    public const WIKI_CONTENT_UPDATED = 'wiki_content_updated';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ISSUE_ADDED,
            self::ISSUE_UPDATED,
            self::ISSUE_NOTE_ADDED,
            self::ISSUE_STATUS_UPDATED,
            self::ISSUE_ASSIGNED_TO_UPDATED,
            self::ISSUE_PRIORITY_UPDATED,
            self::ISSUE_FIXED_VERSION_UPDATED,
            self::ISSUE_ATTACHMENT_ADDED,
            self::NEWS_ADDED,
            self::NEWS_COMMENT_ADDED,
            self::DOCUMENT_ADDED,
            self::FILE_ADDED,
            self::MESSAGE_POSTED,
            self::WIKI_CONTENT_ADDED,
            self::WIKI_CONTENT_UPDATED,
        ];
    }

    /**
     * Events enabled when the setting row is missing.
     *
     * Target version and attachment are known and off until an administrator stores them.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [
            self::ISSUE_ADDED,
            self::ISSUE_UPDATED,
            self::ISSUE_NOTE_ADDED,
            self::ISSUE_STATUS_UPDATED,
            self::ISSUE_ASSIGNED_TO_UPDATED,
            self::ISSUE_PRIORITY_UPDATED,
            self::NEWS_ADDED,
            self::NEWS_COMMENT_ADDED,
            self::DOCUMENT_ADDED,
            self::FILE_ADDED,
            self::MESSAGE_POSTED,
            self::WIKI_CONTENT_ADDED,
            self::WIKI_CONTENT_UPDATED,
        ];
    }

    /**
     * Modules that are not built. Storing the name does not send mail.
     *
     * @return list<string>
     */
    public static function unbuilt(): array
    {
        return [
            self::NEWS_ADDED,
            self::NEWS_COMMENT_ADDED,
            self::DOCUMENT_ADDED,
            self::FILE_ADDED,
            self::MESSAGE_POSTED,
            self::WIKI_CONTENT_ADDED,
            self::WIKI_CONTENT_UPDATED,
        ];
    }

    public static function known(string $name): bool
    {
        return in_array($name, self::all(), true);
    }

    public static function built(string $name): bool
    {
        return self::known($name) && ! in_array($name, self::unbuilt(), true);
    }
}
