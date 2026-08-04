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
     * Display the User Management page.
     */
    public function index(): Response
    {
        $users = User::with(['roles', 'college', 'department', 'departments'])
            ->orderByDesc('id')
            ->get()
            ->map(function (User $user) {
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
                    'email' => $user->email,
                    'role' => $role,
                    'college' => $user->college?->name,
                    'department' => $departmentLabel,
                    'status' => $user->status,
                ];
            });

        return Inertia::render('Users/Index', [
            'users' => $users,
            'colleges' => College::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name', 'college_id']),
            'nextEmployeeId' => $this->nextEmployeeId(),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'max:50', 'unique:users,employee_id'],
            'role' => ['required', Rule::in(RoleSeeder::ROLES)],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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
        ]);

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