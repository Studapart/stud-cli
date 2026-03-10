<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Serializes DTOs (plain objects with public properties) to arrays suitable for JSON encoding.
 */
final class DtoSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(object $dto): array
    {
        $data = get_object_vars($dto);
        foreach ($data as $key => $value) {
            $data[$key] = $this->serializeValue($value);
        }

        return $data;
    }

    /**
     * @param object[] $dtos
     * @return list<array<string, mixed>>
     */
    public function serializeList(array $dtos): array
    {
        return array_map(fn (object $dto): array => $this->serialize($dto), $dtos);
    }

    protected function serializeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }
        if (is_object($value)) {
            return $this->serialize($value);
        }
        if (is_array($value)) {
            return array_map(fn (mixed $v): mixed => is_object($v) ? $this->serialize($v) : $v, $value);
        }

        return $value;
    }
}
