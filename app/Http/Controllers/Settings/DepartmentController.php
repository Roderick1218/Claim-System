<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index()
    {
        return view('settings.departments.index', [
            'departments' => Department::withCount('users')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('settings.departments.form', ['department' => new Department(['is_active' => true])]);
    }

    public function store(Request $request)
    {
        $department = Department::create($this->validated($request));
        $this->audit->log('department.created', 'department', $department->id, null, $department->only(['name', 'description', 'is_active']));

        return redirect()->route('settings.departments.index')->with('success', 'Department created.');
    }

    public function edit(Department $department)
    {
        return view('settings.departments.form', compact('department'));
    }

    public function update(Request $request, Department $department)
    {
        $before = $department->only(['name', 'description', 'is_active']);
        $department->update($this->validated($request, $department));
        [$old, $new] = $this->audit->diff($before, $department->only(['name', 'description', 'is_active']));
        if ($new !== []) {
            $this->audit->log('department.updated', 'department', $department->id, $old, $new);
        }

        return redirect()->route('settings.departments.index')->with('success', 'Department updated.');
    }

    private function validated(Request $request, ?Department $department = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments')->ignore($department?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
