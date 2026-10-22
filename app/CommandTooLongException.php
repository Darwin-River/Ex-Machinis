<?php

namespace App;

/** A drone command doesn't fit in the commands table. */
class CommandTooLongException extends \RuntimeException
{
    public function __construct(public readonly int $length, public readonly int $max)
    {
        parent::__construct("Command is $length characters; the limit is $max");
    }
}
