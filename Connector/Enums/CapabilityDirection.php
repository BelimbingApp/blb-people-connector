<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

enum CapabilityDirection: string
{
    case None = 'none';
    case Read = 'read';
    case Write = 'write';
    case ReadWrite = 'read_write';

    public function canRead(): bool
    {
        return in_array($this, [self::Read, self::ReadWrite], true);
    }

    public function canWrite(): bool
    {
        return in_array($this, [self::Write, self::ReadWrite], true);
    }
}
