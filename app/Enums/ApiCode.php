<?php

namespace App\Enums;

/**
 * API business status codes. HTTP status codes are managed separately.
 */
enum ApiCode: int
{
    case SUCCESS = 0;
    case ERROR = -1;
    case INVALID_PARAMS = -1001;
    case UNAUTHORIZED = -1002;
    case FORBIDDEN = -1003;
    case NOT_FOUND = -1004;
    case CONFLICT = -1005;
}
