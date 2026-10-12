<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Arr;

class AuditLogger
{
    /** Never written to the audit log. */
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
     * Returns only the keys whose values actually changed, as [old, new].
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
