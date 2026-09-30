<?php

namespace App\Domain\Queries;

use App\Domain\DomainException;

/**
 * A saved query or filter payload broke a domain rule.
 */
class QueryValidationException extends DomainException {}
