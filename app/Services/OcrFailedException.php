<?php

namespace App\Services;

use RuntimeException;

// 这类错误的 message 可以直接显示给用户看，其他 exception 只进 log
class OcrFailedException extends RuntimeException {}
