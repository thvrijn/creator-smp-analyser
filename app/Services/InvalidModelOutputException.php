<?php

namespace App\Services;

use RuntimeException;

/** The worker ran, but the model output for a chunk was unusable (HTTP 422). */
class InvalidModelOutputException extends RuntimeException
{
}
