<?php

declare(strict_types=1);

namespace App\Migrations;

enum MigrationScope: string
{
    case GLOBAL = 'global';
    case PROJECT = 'project';
}
