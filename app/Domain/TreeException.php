<?php

namespace App\Domain;

/**
 * Raised when a nested-set move would break parent or lft/rgt integrity.
 */
class TreeException extends DomainException {}
