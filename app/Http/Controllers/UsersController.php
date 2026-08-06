<?php

namespace App\Http\Controllers;

use App\Models\College;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UsersController extends Controller
{
    /**
     * Roles that require a College assignment (Dean oversees the whole
     * college, so no Department is required).
     *
     * @var list<string>
     */
    private const ROLES_REQUIRING_COLLEGE = ['Dean', 'OIC'];

    /**
     * Roles that require both College and Department (an OIC may be
     * covering a specific department).
     *
     * @var list<string>
     */
    private const ROLES_REQUIRING_DEPARTMENT = ['OIC'];

    /**
     * Display the User Management page. Administrator only — Registrar,
     * Dean, OIC and Assistant Dean must never see or reach this page;
     * they get "Manage Account" on the Settings page instead.
     */
    public function index(Request $request): Response
    {
        $this->authorizeAdministrator($request);

        $users = User::with(['roles', 'college', 'department', 'departments'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (User $user) => $this->transform($user));

        return Inertia::render('Users/Index', [
            'users' => $users,
            'colleges' => College::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name', 'college_id']),
            'nextEmployeeId' => $this->nextEmployeeId(),
        ]);
    }

    /**
     * Store a newly created user. Administrator only.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdministrator($request);

        $validated = $request->validate($this->rules($request));

        $user = User::create([
            'name' => trim("{$validated['first_name']} {$validated['last_name']}"),
            'employee_id' => $validated['employee_id'],
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'suffix' => $validated['suffix'] ?? null,
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'status' => $validated['status'],
            'college_id' => $validated['college_id'] ?? null,
            'email_verified_at' => now(),
        ]);

        $user->assignRole($validated['role']);

        // Attach the OIC's specific department assignments. Leaving this
        // empty (when "oversees all departments" is checked) means the
        // OIC covers every department in their college by implication.
        if (! empty($validated['department_ids'])) {
            $user->departments()->sync($validated['department_ids']);
        }

        return redirect()->route('users')->with('success', 'User account created successfully.');
    }

    /**
     * Update an existing user (the "Edit" action in User Management).
     * Administrator only.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdministrator($request);

        $validated = $request->validate($this->rules($request, $user));

        $user->fill([
            'name' => trim("{$validated['first_name']} {$validated['last_name']}"),
            'employee_id' => $validated['employee_id'],
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'suffix' => $validated['suffix'] ?? null,
            'email' => $validated['email'],
            'status' => $validated['status'],
            'college_id' => $validated['college_id'] ?? null,
        ]);

        // Password is optional on edit — only touch it if one was given.
        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        if ($user->roles->first()?->name !== $validated['role']) {
            $user->syncRoles([$validated['role']]);
        }

        $user->departments()->sync($validated['department_ids'] ?? []);

        return redirect()->route('users')->with('success', 'User account updated successfully.');
    }

    /**
     * Toggle a user between Active and Inactive (the "Deactivate" /
     * "Activate" row action in User Management). Administrator only.
     * An Administrator may not deactivate their own account, which
     * would otherwise lock them out of User Management entirely.
     */
    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdministrator($request);

        if ($user->id === $request->user()->id) {
            return redirect()->route('users')->with('error', 'You cannot deactivate your own account.');
        }

        $user->status = $user->status === 'Active' ? 'Inactive' : 'Active';
        $user->save();

        $message = $user->status === 'Active'
            ? 'User account activated.'
            : 'User account deactivated.';

        return redirect()->route('users')->with('success', $message);
    }

    /**
     * Permanently delete a user account (the "Delete" row action in User
     * Management). Administrator only. An Administrator may not delete
     * their own account, and the last remaining Administrator account
     * may never be deleted, to guarantee someone can always manage the
     * system.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdministrator($request);

        if ($user->id === $request->user()->id) {
            return redirect()->route('users')->with('error', 'You cannot delete your own account.');
        }

        if ($user->hasRole('Administrator') && User::role('Administrator')->count() <= 1) {
            return redirect()->route('users')->with('error', 'You cannot delete the last remaining Administrator account.');
        }

        $user->delete();

        return redirect()->route('users')->with('success', 'User account deleted.');
    }

    /**
     * Update the logged-in user's own account details (name, email,
     * password). Used by the "Manage Account" tab on the User Management
     * page (Administrator) and on the Settings page (Registrar, Dean,
     * OIC, Assistant Dean). Every role may only ever touch its own
     * account here — $request->user() is never swapped for another row.
     */
    public function updateAccount(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $user->fill([
            'name' => trim("{$validated['first_name']} {$validated['last_name']}"),
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'suffix' => $validated['suffix'] ?? null,
            'email' => $validated['email'],
        ]);

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        // Administrators manage their account from the Users page; every
        // other role manages it from Settings, so send each back to the
        // page their "Manage Account" tab actually lives on.
        $redirectRoute = $user->hasRole('Administrator') ? 'users' : 'settings';

        return redirect()->route($redirectRoute)->with('success', 'Your account has been updated.');
    }

    /**
     * Guard used by index()/store()/update() — User Management (viewing,
     * creating, and editing accounts) is restricted to the Administrator
     * role. Registrar, Dean, OIC and Assistant Dean are blocked here even
     * if they hit these routes directly (e.g. by URL), not just hidden
     * from the sidebar.
     */
    private function authorizeAdministrator(Request $request): void
    {
        abort_unless($request->user()->hasRole('Administrator'), 403);
    }

    /**
     * Shared validation rules for store() and update(). On update, the
     * password becomes optional and unique checks ignore the current row.
     *
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?User $user = null): array
    {
        return [
            'employee_id' => [
                'required', 'string', 'max:50',
                Rule::unique('users', 'employee_id')->ignore($user?->id),
            ],
            'role' => ['required', Rule::in(RoleSeeder::ROLES)],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:50'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'oversees_all_departments' => ['boolean'],
            'college_id' => [
                Rule::requiredIf(in_array($request->input('role'), self::ROLES_REQUIRING_COLLEGE, true)),
                'nullable',
                'exists:colleges,id',
            ],
            'department_ids' => [
                Rule::requiredIf(
                    in_array($request->input('role'), self::ROLES_REQUIRING_DEPARTMENT, true)
                    && ! $request->boolean('oversees_all_departments')
                ),
                'array',
            ],
            'department_ids.*' => [
                'integer',
                Rule::exists('departments', 'id')->where('college_id', $request->input('college_id')),
            ],
        ];
    }

    /**
     * Shape a User model into the array the Users/Index page expects,
     * shared by the index listing and the edit-form prefill.
     *
     * @return array<string, mixed>
     */
    private function transform(User $user): array
    {
        $role = $user->roles->first()?->name;
        $collegeDepartmentCount = $user->college
            ? Department::where('college_id', $user->college_id)->count()
            : 0;

        $departmentLabel = null;

        if ($role === 'OIC' && $user->college_id) {
            $assigned = $user->departments->pluck('name');

            $departmentLabel = match (true) {
                $assigned->isEmpty() => 'All Departments',
                $assigned->count() >= $collegeDepartmentCount => 'All Departments',
                default => $assigned->implode(', '),
            };
        }

        return [
            'id' => $user->id,
            'employeeId' => $user->employee_id,
            'fullName' => $user->full_name,
            'firstName' => $user->first_name,
            'middleName' => $user->middle_name,
            'lastName' => $user->last_name,
            'suffix' => $user->suffix,
            'email' => $user->email,
            'role' => $role,
            'collegeId' => $user->college_id,
            'departmentIds' => $user->departments->pluck('id'),
            'college' => $user->college?->name,
            'department' => $departmentLabel,
            'status' => $user->status,
        ];
    }

    /**
     * Determine the next sequential Employee ID, e.g. EMP-2026-0001.
     */
    private function nextEmployeeId(): string
    {
        $year = now()->year;
        $prefix = "EMP-{$year}-";

        $lastNumber = User::where('employee_id', 'like', "{$prefix}%")
            ->orderByDesc('employee_id')
            ->value('employee_id');

        $nextNumber = $lastNumber
            ? ((int) substr($lastNumber, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}