<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service\Exception;

/** The person behind this document already has an account here: they should sign in, not register. */
final class AlreadyRegisteredException extends \InvalidArgumentException
{
}
