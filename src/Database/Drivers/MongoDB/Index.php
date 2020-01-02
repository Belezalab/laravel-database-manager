<?php

namespace CodexShaper\DBM\Database\Drivers\MongoDB;

use MongoDB\Collection;
use MongoDB\Model\IndexInfo;

class Index
{
    public static function getIndexes(Collection $collection)
    {
        $listIndexes = $collection->listIndexes();
        $indexes     = [];
        foreach ($listIndexes as $index) {
            $indexes[] = [
                "name"        => $index->getName(),
                "oldName"     => $index->getName(),
                "columns"     => array_keys($index->getKey()),
                "type"        => static::getType($index),
                "isPrimary"   => false,
                "isUnique"    => $index->isUnique(),
                "isComposite" => (count($index->getKey()) > 1) ? true : false,
                "flags"       => [],
                "options"     => [],
                "namespace"   => $index->getNamespace(),
            ];
        }

        return $indexes;
    }

    public static function getType(IndexInfo $index)
    {
        if ($index->isText()) {
            return "TEXT";
        } else if ($index->isUnique() && !$index->isSparse() && !static::checkDescending($index)) {
            return "UNIQUE";
        } else if ($index->isUnique() && !$index->isSparse() && static::checkDescending($index)) {
            return "UNIQUE_DESC";
        } else if ($index->isSparse() && !static::checkDescending($index)) {
            return "SPARSE";
        } else if ($index->isSparse() && $index->isUnique() && !static::checkDescending($index)) {
            return "SPARSE_UNIQUE";
        } else if ($index->isSparse() && $index->isUnique() && static::checkDescending($index)) {
            return "SPARSE_UNIQUE_DESC";
        } else if ($index->isSparse() && static::checkDescending($index)) {
            return "SPARSE_DESC";
        } else if ($index->is2dSphere()) {
            return "2DSPARSE";
        } else if ($index->isTtl()) {
            return "TTL";
        } else if ($index->isGeoHaystack()) {
            return "GEOHAYSTACK";
        } else {
            $name     = $index->getName();
            $partials = explode("_", $name);
            $type     = end($partials);
            if ($type == 'asc') {
                return "ASC";
            } else if ($type == 'index') {
                return "INDEX";
            } else if ($type == 'desc') {
                return "DESC";
            } else {
                return "";
            }

        }
    }

    protected static function checkDescending($index)
    {
        $keys = $index->getKey();

        foreach ($keys as $key => $value) {
            if ($value == -1) {
                return true;
            }
        }

        return false;
    }

    public static function setIndexes(Collection $collection, $indexes)
    {

        $collection->dropIndexes();

        foreach ($indexes as $index) {
            $columns = $index['columns'];
            $name    = $index['name'];
            $type    = $index['type'];

            foreach ($columns as $column) {
                if ($column == '_id') {
                    continue;
                }

                switch ($type) {

                    case 'TEXT':
                        $indexType = 'text';
                        break;
                    case 'INDEX':
                        $indexType = 1;
                        break;
                    case 'UNIQUE':
                        $indexType         = 1;
                        $options['unique'] = true;
                        break;
                    case 'UNIQUE_DESC':
                        $indexType         = -1;
                        $options['unique'] = true;
                        break;
                    case 'TTL':
                        $indexType                     = 1;
                        $options['expireAfterSeconds'] = 3600;
                        break;
                    case 'SPARSE':
                        $indexType         = 1;
                        $options['sparse'] = true;
                        break;
                    case 'SPARSE_DESC':
                        $indexType         = -1;
                        $options['sparse'] = true;
                        break;
                    case 'SPARSE_UNIQUE':
                        $indexType         = 1;
                        $options['sparse'] = true;
                        $options['unique'] = true;
                    case 'SPARSE_UNIQUE_DESC':
                        $indexType         = -1;
                        $options['sparse'] = true;
                        $options['unique'] = true;
                        break;
                    case 'ASC':
                        $indexType = 1;
                        break;
                    case 'DESC':
                        $indexType = -1;
                        break;
                    default:
                        $indexType = 1;
                        break;
                }

                $options['name'] = strtolower($collection->getCollectionName() . "_" . $column . "_" . $type);

                $options['ns'] = $collection->getNamespace();

                $collection->createIndex([$column => $indexType], $options);
            }
        }

        return true;
    }
}
