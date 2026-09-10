<?php

namespace App\Migration\IntranetV3;

final readonly class MigrationContext
{
    public function __construct(
        public bool $dryRun = false,
        public bool $verbose = false,
    ) {
    }
}
