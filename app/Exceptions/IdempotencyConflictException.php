<?php

namespace App\Exceptions;

use DomainException;

final class IdempotencyConflictException extends DomainException
{
}
