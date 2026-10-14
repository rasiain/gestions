<?php

namespace App\Services;

use RuntimeException;

/** No hi ha cap compte de Gmail connectat, o Google n'ha retirat el permís. */
class GmailDesconnectat extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Gmail no està connectat.');
    }
}
