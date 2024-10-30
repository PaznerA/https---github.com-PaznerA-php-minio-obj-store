<?php declare(strict_types=1);

namespace Pazny\BtrfsStorageTesting\Utils\RustDb;

enum ValueType: string {
    case String = 'string';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Array = 'array';
    case Map = 'map';
    case Null = 'null';
}