<?php

namespace ItsJustVita\LaravelBfsg;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';

    public static function fromLegacy(?string $value): self
    {
        return match (strtolower(trim((string) $value))) {
            'error', 'critical' => self::Error,
            'warning' => self::Warning,
            default => self::Notice,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Error => 3,
            self::Warning => 2,
            self::Notice => 1,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
