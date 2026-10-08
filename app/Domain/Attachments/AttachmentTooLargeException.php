<?php

namespace App\Domain\Attachments;

use App\Domain\DomainException;

/**
 * The upload is larger than `attachment_max_size`. REST maps this to 413.
 */
final class AttachmentTooLargeException extends DomainException {}
