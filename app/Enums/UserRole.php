<?php

namespace App\Enums;

enum UserRole: string
{
    case Worker = 'worker';
    case Employer = 'employer';
    case Admin = 'admin';

    /**
     * Roles a visitor is allowed to self-register as.
     *
     * @return array<int, string>
     */
    public static function registerable(): array
    {
        return [self::Worker->value, self::Employer->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Worker => 'Worker',
            self::Employer => 'Employer',
            self::Admin => 'Admin',
        };
    }
}
