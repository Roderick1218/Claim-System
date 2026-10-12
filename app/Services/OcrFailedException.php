<?php

namespace App\Services;

use RuntimeException;

/** A failure whose message is safe to show to the user. */
class OcrFailedException extends RuntimeException {}
