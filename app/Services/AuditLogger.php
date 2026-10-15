<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Arr;

class AuditLogger
{
    // 这些字段不写进日志：密码、token 不该留底；ocr_text 太长又可能含个人资料；file_path 是内部路径
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'remember_token', 'token', '_token',
        'ocr_text', 'file_path', 'api_key', 'secret',
    ];

    public function log(string $action, string $entityType, ?int $entityId, ?array $old = null, ?array $new = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $this->clean($old),
            'new_values' => $this->clean($new),
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * 只挑出真正有变动的字段，返回 [old, new]，日志里就不会堆满没改过的值。
     * 用 json_encode 来比，嵌套的 items 数组也能直接整块比较。
     *
     * @return array{0: array, 1: array}
     */
    public function diff(array $old, array $new): array
    {
        $changedOld = [];
        $changedNew = [];

        foreach ($new as $key => $value) {
            $before = $old[$key] ?? null;
            if ((string) json_encode($before) !== (string) json_encode($value)) {
                $changedOld[$key] = $before;
                $changedNew[$key] = $value;
            }
        }

        return [$changedOld, $changedNew];
    }

    private function clean(?array $values): ?array
    {
        if ($values === null || $values === []) {
            return null;
        }

        return Arr::except($values, self::SENSITIVE_KEYS);
    }
}
