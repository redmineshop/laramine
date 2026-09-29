<?php

namespace App\Domain;

/**
 * Raised when an issue status or field rule rejects a write.
 */
class WorkflowDeniedException extends DomainException {}
