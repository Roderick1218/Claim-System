<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $users = User::query()
            ->with(['department', 'roles'])
            ->when($request->filled('q'), function ($q) use ($request) {
                // 转义 % 和 _，用户输入的通配符不应该生效
                $like = '%'.addcslashes($request->input('q'), '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('employee_code', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('settings.users.index', compact('users'));
    }

    public function create()
    {
        return view('settings.users.form', ['user' => new User(['is_active' => true]), ...$this->options()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $user = DB::transaction(function () use ($data) {
            $user = User::create($data['attributes'] + ['password' => $data['password']]);
            $user->roles()->sync($data['roles']);
            $this->audit->log('user.created', 'user', $user->id, null, $this->snapshot($user));

            return $user;
        });

        return redirect()->route('settings.users.index')->with('success', "User {$user->name} created.");
    }

    public function edit(User $user)
    {
        return view('settings.users.form', ['user' => $user->load('roles'), ...$this->options()]);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);

        if ($user->is($request->user()) && ! $data['attributes']['is_active']) {
            return back()->withInput()->withErrors(['is_active' => 'You cannot deactivate your own account.']);
        }

        DB::transaction(function () use ($user, $data) {
            $before = $this->snapshot($user->load('roles'));
            $user->fill($data['attributes']);
            if ($data['password']) {
                $user->password = $data['password'];
            }
            $user->save();
            $user->roles()->sync($data['roles']);
            // role 换了，这个 model 上缓存的权限要清掉，下面的 snapshot 和后续检查才会读到新的
            $user->flushPermissionCache();

            [$old, $new] = $this->audit->diff($before, $this->snapshot($user->load('roles')));
            if ($data['password']) {
                $new['password_reset'] = true; // 只记有重设过，密码本身绝不写进 log
            }
            if ($new !== []) {
                $this->audit->log('user.updated', 'user', $user->id, $old, $new);
            }
        });

        return redirect()->route('settings.users.index')->with('success', "User {$user->name} updated.");
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => ['nullable', 'string', 'max:50', Rule::unique('users')->ignore($user?->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'roles' => ['array'],
            'roles.*' => ['integer', 'exists:roles,id'],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(8)],
        ]);

        return [
            'attributes' => [
                'name' => $v['name'],
                'employee_code' => $v['employee_code'] ?? null,
                'email' => $v['email'],
                'department_id' => $v['department_id'] ?? null,
                'is_active' => $request->boolean('is_active'),
            ],
            'roles' => $v['roles'] ?? [],
            'password' => $v['password'] ?? null,
        ];
    }

    private function options(): array
    {
        return [
            'departments' => Department::orderBy('name')->get(),
            'roles' => Role::orderBy('name')->get(),
        ];
    }

    private function snapshot(User $user): array
    {
        return $user->only(['name', 'employee_code', 'email', 'department_id', 'is_active'])
            + ['roles' => $user->roles->pluck('name')->sort()->values()->all()];
    }
}
