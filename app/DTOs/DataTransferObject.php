<?php

namespace App\DTOs;

use JsonSerializable;
use ReflectionClass;

/**
 * Base for immutable DTOs. Concrete DTOs declare readonly promoted
 * constructor properties and (usually) a static fromRequest()/fromArray()
 * factory that performs the mapping explicitly.
 */
abstract class DataTransferObject implements JsonSerializable
{
    public function toArray(): array
    {
        $result = [];
        foreach ((new ReflectionClass($this))->getProperties() as $property) {
            $result[$property->getName()] = $property->getValue($this);
        }

        return $result;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
