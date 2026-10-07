<?php

namespace App\Domain;

/**
 * Raised when a download-all zip is larger than `bulk_download_max_size`.
 */
class AttachmentArchiveLimitException extends DomainException {}
