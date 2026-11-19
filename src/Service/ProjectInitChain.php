<?php

declare(strict_types=1);

namespace App\Service;

use App\Handler\ConfigProjectInitHandler;
use App\Responder\ConfigProjectInitResponder;

final class ProjectInitChain
{
    public function __construct(
        public readonly ConfigProjectInitHandler $handler,
        public readonly ConfigProjectInitResponder $responder,
    ) {
    }
}
