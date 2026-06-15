<?php

namespace App\Http\Middleware;

use App\Models\SupervisorEvaluationSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $sharedUser = null;

        if ($user !== null) {
            $profilePhotoUrl = $user->personalInformation?->profile_photo_path
                ? asset('storage/' . $user->personalInformation->profile_photo_path)
                : null;

            // Prefer calling resolveRole() when available (reliable even if attribute accessors behave oddly)
            $role = method_exists($user, 'resolveRole') ? $user->resolveRole() : ($user->role ?? null);

            $sharedUser = [
                    'id' => $user->id,
                    'id_no' => $user->id_no,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'middlename' => $user->middlename,
                    'extname' => $user->extname,
                    'profile_photo_url' => $profilePhotoUrl,

                    // Use resolved role explicitly
                    'role' => $role,
                    'isAdmin' => $role === 'admin',
                    'isDean' => $role === 'dean',
                    'isAssociateDean' => $role === 'associate_dean',
                    'isUnitHead' => $role === 'unit_head',
                    'canEvaluateFaculty' => method_exists($user, 'canEvaluateFaculty') ? $user->canEvaluateFaculty() : false,
                ];
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $sharedUser,
            ],
            'hasPendingEvaluations' => $this->hasPendingEvaluations($request->user()),
        ];
    }

    protected function hasPendingEvaluations($user): bool
    {
        if ($user === null) {
            return false;
        }

        if (! $this->canAccessEvaluationForUser($user)) {
            return false;
        }

        $activeSchoolYear = $this->getActiveSchoolYear();
        if (! $activeSchoolYear) {
            return false;
        }

        $assignedInstructorIds = $this->getAssignedEvaluationInstructorIdNos($user, $activeSchoolYear->id);
        if (empty($assignedInstructorIds)) {
            return false;
        }

        $evaluatedCount = SupervisorEvaluationSubmission::query()
            ->where('user_id', $user->id)
            ->where('term_id', $activeSchoolYear->id)
            ->whereNotNull('instructor_id_no')
            ->distinct()
            ->count('instructor_id_no');

        return $evaluatedCount < count($assignedInstructorIds);
    }

    protected function canAccessEvaluationForUser($user): bool
    {
        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'canEvaluateFaculty')) {
            return $user->canEvaluateFaculty();
        }

        return method_exists($user, 'isUnitHead') && $user->isUnitHead();
    }

    protected function getAssignedEvaluationInstructorIdNos($user, int $schoolYearId): array
    {
        if ($user->isDean()) {
            return [];
        }

        if ($user->isAssociateDean()) {
            $associateDean = $user->associateDean;
            if (! $associateDean?->college_id) {
                return [];
            }

            $idNos = User::query()
                ->whereHas('unitHead')
                ->where('college_id', $associateDean->college_id)
                ->pluck('id_no')
                ->filter()
                ->unique()
                ->values()
                ->all();

            return $this->filterInstructorIdNosBySchoolYear($idNos, $schoolYearId);
        }

        if ($user->isUnitHead()) {
            $unitHead = $user->unitHead;
            if (! $unitHead?->unit_id) {
                return [];
            }

            $idNos = User::query()
                ->where('unit_id', $unitHead->unit_id)
                ->whereNotNull('id_no')
                ->where('id_no', '!=', '')
                ->pluck('id_no')
                ->filter()
                ->unique()
                ->values()
                ->all();

            return $this->filterInstructorIdNosBySchoolYear($idNos, $schoolYearId);
        }

        if ($user->isAdmin()) {
            return $this->filterInstructorIdNosBySchoolYear([], $schoolYearId, true);
        }

        return [];
    }

    protected function filterInstructorIdNosBySchoolYear(array $idNos, int $schoolYearId, bool $all = false): array
    {
        $query = DB::connection('lnu_poes')
            ->table('enrollment_courses')
            ->where('school_year_id', $schoolYearId)
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '');

        if (! $all) {
            if (empty($idNos)) {
                return [];
            }

            $query->whereIn('id_no', $idNos);
        }

        return $query->pluck('id_no')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function getActiveSchoolYear()
    {
        $cached = Cache::get('active_school_year');
        if ($cached instanceof \stdClass && property_exists($cached, 'id') && property_exists($cached, 'school_year_from')) {
            return $cached;
        }

        $fresh = DB::connection('lnu_poes')
            ->table('school_years')
            ->where('is_active', 1)
            ->first();

        if ($fresh) {
            Cache::put('active_school_year', $fresh, now()->addSeconds(3600));
        }

        return $fresh;
    }
}
