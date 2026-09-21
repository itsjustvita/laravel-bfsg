<?php

namespace ItsJustVita\LaravelBfsg;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';

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

    public function label(?string $locale = null): string
    {
        if (! function_exists('app') || ! app()->bound('translator')) {
            return $this->value;
        }

        $text = app('translator')->get('bfsg::report.severity.'.$this->value, [], $locale);

        return is_string($text) ? $text : $this->value;
    }
}
