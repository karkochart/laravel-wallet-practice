<?php

namespace App\Exceptions;

/** Некорректная сумма, перевод самому себе, разные валюты, нет такого аккаунта. */
class InvalidTransferException extends \DomainException {}
