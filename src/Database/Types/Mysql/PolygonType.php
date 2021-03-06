<?php

namespace CodexShaper\DBM\Database\Types\Mysql;

use CodexShaper\DBM\Database\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;

class PolygonType extends Type
{
    const NAME = 'polygon';

    /**
     * Register polygon type.
     *
     * @return string
     */
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return 'polygon';
    }
}
