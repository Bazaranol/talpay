<?php

namespace App\Enums;

enum UserRole: string
{
    case Client = 'client';
    case Admin = 'admin';
}
