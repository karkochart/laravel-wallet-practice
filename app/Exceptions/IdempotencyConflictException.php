<?php

namespace App\Exceptions;

/** Тот же idempotency key, но другие параметры запроса. */
class IdempotencyConflictException extends \DomainException {}
