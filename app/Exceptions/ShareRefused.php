<?php

namespace App\Exceptions;

/** A share trade refused inside a transaction, carrying the refusal (PlayerShares::move, #845) */
class ShareRefused extends \RuntimeException
{
    public function __construct(public array $result)
    {
        parent::__construct($result['error'] ?? 'Refused.');
    }
}
