<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::query()
            ->with('user')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->input('entity_type')))
            ->when(ctype_digit((string) $request->input('entity_id')), fn ($q) => $q->where('entity_id', (int) $request->input('entity_id')))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('settings.audit-logs.index', [
            'logs' => $logs,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'entityTypes' => AuditLog::query()->distinct()->orderBy('entity_type')->pluck('entity_type'),
        ]);
    }
}
