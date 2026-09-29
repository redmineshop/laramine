<?php

namespace App\Domain;

use RuntimeException;

/**
 * Base exception for domain rule failures (tree, ACL, workflow).
 */
class DomainException extends RuntimeException {}
