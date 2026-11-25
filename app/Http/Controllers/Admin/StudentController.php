<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStudentRequest;
use App\Http\Requests\Admin\UpdateStudentRequest;
use App\Models\User;
use App\Notifications\StudentAccountStatusNotification;
use App\Services\AdminUserLifecycleService;
use App\Services\SkillAssessmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class StudentController extends Controller
{
    public function __construct(
        protected AdminUserLifecycleService $lifecycle
    ) {}

    public function index(Request $request): Response
    {
        $view = in_array($request->query('view'), ['active', 'archived', 'all'], true)
            ? $request->query('view')
            : 'active';

        $query = User::query()
            ->where('role', 'student')
            ->with(['studentProfile'])
            ->withCount([
                'bookingsAsStudent',
                'bookingsAsStudent as underpaid_bookings_count' => function ($q) {
                    $q->where('payment_status', 'underpaid');
                },
            ]);

        if ($view === 'archived') {
            $query->onlyTrashed();
        } elseif ($view === 'all') {
            $query->withTrashed();
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $students = $query
            ->latest()
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Students/Index', [
            'students' => $students,
            'filters' => [
                'search' => $request->input('search', ''),
                'view' => $view,
            ],
            'counts' => [
                'active' => User::query()->where('role', 'student')->count(),
                'archived' => User::onlyTrashed()->where('role', 'student')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Students/Create');
    }

    public function store(StoreStudentRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'student',
                'phone' => $validated['phone'] ?? null,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            Role::findOrCreate('student', 'web');
            $user->assignRole('student');

            $user->studentProfile()->create([
                'skill_level' => $validated['skill_level'],
                'skill_assessed_at' => now(),
                'onboarding_completed_at' => now(),
                'birthday' => $validated['birthday'] ?? null,
                'country' => filled($validated['country'] ?? null) ? trim((string) $validated['country']) : null,
                'status' => $validated['status'],
                'is_first_time' => (bool) ($validated['is_first_time'] ?? ($validated['skill_level'] === 'beginner')),
                'has_board' => (bool) ($validated['has_board'] ?? false),
                'height' => $validated['height'] ?? null,
                'weight' => $validated['weight'] ?? null,
                'emergency_contact_name' => $validated['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? null,
                'medical_info' => $validated['medical_info'] ?? null,
            ]);

            return $user;
        });

        return redirect()->route('admin.students.show', $user)
            ->with('success', 'Student account created successfully.');
    }

    public function show(User $user): Response
    {
        if (! $user->isStudent()) {
            abort(404);
        }

        $user->load([
            'studentProfile',
            'latestSkillAssessment',
            'bookingsAsStudent' => function ($q) {
                $q->latest()->with(['instructor.instructorProfile', 'surfSpot', 'payment', 'waiver', 'review']);
            },
            'reportedIncidents',
        ]);

        $skillAssessment = $user->latestSkillAssessment
            ? app(SkillAssessmentService::class)->formatForDisplay($user->latestSkillAssessment)
            : null;

        return Inertia::render('Admin/Students/Show', [
            'student' => $user,
            'skillAssessment' => $skillAssessment,
            'isArchived' => $user->trashed(),
            'deletedAt' => $user->deleted_at?->toIso8601String(),
            'activeBookingsCount' => $user->activeSlotHoldingBookingsCount(),
            'canForceDelete' => $user->trashed() && ! $user->hasHistoricalRecords(),
        ]);
    }

    public function edit(User $user): Response
    {
        if (! $user->isStudent()) {
            abort(404);
        }

        $user->load('studentProfile');

        return Inertia::render('Admin/Students/Edit', [
            'student' => $user,
            'hasActiveBookings' => $user->activeSlotHoldingBookingsCount() > 0,
        ]);
    }

    public function update(UpdateStudentRequest $request, User $user): RedirectResponse
    {
        if (! $user->isStudent()) {
            abort(404);
        }

        $validated = $request->validated();

        DB::transaction(function () use ($user, $validated): void {
            $userData = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
            ];

            if (filled($validated['password'] ?? null)) {
                $userData['password'] = Hash::make($validated['password']);
            }

            $user->update($userData);

            $profileData = [
                'skill_level' => $validated['skill_level'],
                'birthday' => $validated['birthday'] ?? null,
                'country' => filled($validated['country'] ?? null) ? trim((string) $validated['country']) : null,
                'status' => $validated['status'],
                'is_first_time' => (bool) ($validated['is_first_time'] ?? false),
                'has_board' => (bool) ($validated['has_board'] ?? false),
                'height' => $validated['height'] ?? null,
                'weight' => $validated['weight'] ?? null,
                'emergency_contact_name' => $validated['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? null,
                'medical_info' => $validated['medical_info'] ?? null,
            ];

            if ($user->studentProfile) {
                $user->studentProfile->update($profileData);
            } else {
                $user->studentProfile()->create(array_merge($profileData, [
                    'skill_assessed_at' => now(),
                ]));
            }
        });

        return redirect()->route('admin.students.show', $user)
            ->with('success', 'Student profile updated successfully.');
    }

    public function toggleStatus(User $user): RedirectResponse
    {
        if (! $user->isStudent()) {
            abort(404);
        }

        $profile = $user->studentProfile;

        if (! $profile) {
            $profile = $user->studentProfile()->create([
                'status' => 'active',
                'skill_level' => 'beginner',
                'is_first_time' => true,
            ]);
        }

        $isBanned = $profile->status === 'active';

        $profile->update([
            'status' => $isBanned ? 'banned' : 'active',
        ]);

        try {
            $user->notify(new StudentAccountStatusNotification($isBanned));
        } catch (\Throwable $exception) {
            Log::warning('Failed to notify student of account status change.', [
                'student_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $statusMessage = $profile->status === 'active' ? 'unbanned' : 'banned';

        return back()->with('success', "Student has been {$statusMessage} successfully.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if (! $user->isStudent()) {
            abort(404);
        }

        $result = $this->lifecycle->archive($user, $request->user());

        if (! $result['ok']) {
            return back()->with('error', $result['message']);
        }

        return redirect()->route('admin.students.index')
            ->with('success', $result['message']);
    }

    public function restore(User $user): RedirectResponse
    {
        if (! $user->isStudent()) {
            abort(404);
        }

        $result = $this->lifecycle->restore($user);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function forceDestroy(User $user): RedirectResponse
    {
        if (! $user->isStudent()) {
            abort(404);
        }

        $result = $this->lifecycle->forceDelete($user);

        if (! $result['ok']) {
            return back()->with('error', $result['message']);
        }

        return redirect()->route('admin.students.index', ['view' => 'archived'])
            ->with('success', $result['message']);
    }
}
