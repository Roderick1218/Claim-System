<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index()
    {
        return view('settings.roles.index', [
            'roles' => Role::with('permissions')->withCount('users')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('settings.roles.form', ['role' => new Role, 'permissions' => Permission::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $role = DB::transaction(function () use ($data) {
            $role = Role::create($data['attributes']);
            $role->permissions()->sync($data['permissions']);
            $this->audit->log('role.created', 'role', $role->id, null, $this->snapshot($role));

            return $role;
        });

        return redirect()->route('settings.roles.index')->with('success', "Role {$role->name} created.");
    }

    public function edit(Role $role)
    {
        return view('settings.roles.form', ['role' => $role->load('permissions'), 'permissions' => Permission::orderBy('name')->get()]);
    }

    public function update(Request $request, Role $role)
    {
        $data = $this->validated($request, $role);

        // 防止把自己锁在门外：拿掉 settings.roles 之前，至少要有另一个有用户的 role 还保留这个权限，
        // 否则以后没人能再进来改 role。
        $rolesPermId = Permission::where('name', Permission::SETTINGS_ROLES)->value('id');
        if (! in_array($rolesPermId, $data['permissions'])
            && $role->permissions->contains('id', $rolesPermId)
            && ! Role::whereKeyNot($role->id)->whereHas('permissions', fn ($q) => $q->whereKey($rolesPermId))->whereHas('users')->exists()) {
            return back()->withInput()->withErrors(['permissions' => 'At least one role with users must keep "settings.roles", otherwise nobody can manage roles.']);
        }

        DB::transaction(function () use ($role, $data) {
            $before = $this->snapshot($role->load('permissions'));
            $role->update($data['attributes']);
            $role->permissions()->sync($data['permissions']);
            [$old, $new] = $this->audit->diff($before, $this->snapshot($role->load('permissions')));
            if ($new !== []) {
                $this->audit->log('role.updated', 'role', $role->id, $old, $new);
            }
        });

        return redirect()->route('settings.roles.index')->with('success', "Role {$role->name} updated.");
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_\-]+$/', Rule::unique('roles')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ], ['name.regex' => 'Use lowercase letters, numbers, - or _ only.']);

        return [
            'attributes' => ['name' => $v['name'], 'description' => $v['description'] ?? null],
            'permissions' => array_map('intval', $v['permissions'] ?? []),
        ];
    }

    /** audit log 用的快照，permission 排过序，diff 时才不会因为顺序不同被当成有改动 */
    private function snapshot(Role $role): array
    {
        return $role->only(['name', 'description']) + ['permissions' => $role->permissions->pluck('name')->sort()->values()->all()];
    }
}
