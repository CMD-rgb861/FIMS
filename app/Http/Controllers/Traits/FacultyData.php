<?php

namespace App\Http\Controllers\Traits;

use App\Models\SupervisorEvaluationSubmission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait FacultyData
{
    /**
     * OPTIMIZED: avoids full table scan + heavy PHP loops
     */
    protected function getFacultyEvaluations($user = null): array
    {
        $cacheKey =
            'faculty-evaluations:' .
            ($user?->resolveRole() ?? 'guest') . ':' .
            ($user?->college_id ?? 'none') . ':' .
            ($user?->unit_id ?? 'none');

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($user) {

            try {
                $allowedProgramIds = $this->getAllowedProgramIdsForUser($user);

                // 🔥 STEP 1: push filtering to DB (NO full table load)
                $query = DB::connection('lnu_poes')
                    ->table('enrollment_courses')
                    ->select([
                        'instructor',
                        'instructor_name',
                        'faculty_name',
                        'name',
                        'id_no',
                        'employee_id_no',
                        'instr_id_no',
                        'instructor_id_no',
                        'course_code',
                        'subject_code',
                        'subj_code',
                        'code',
                        'course',
                        'course_description',
                        'course_title',
                        'subject_title',
                        'title',
                        'description',
                        'school_year_from',
                        'school_year_to',
                        'semester',
                        'term',
                        'program_id'
                    ]);

                if (!empty($allowedProgramIds)) {
                    $query->whereIn('program_id', $allowedProgramIds);
                }

                $rows = $query->limit(5000)->get();

                if ($rows->isEmpty()) {
                    return [];
                }

                $instructors = [];

                foreach ($rows as $row) {

                    $row = (array) $row;

                    // -------------------------
                    // FAST instructor resolution
                    // -------------------------
                    $instructor =
                        $row['instructor']
                        ?? $row['instructor_name']
                        ?? $row['faculty_name']
                        ?? $row['name']
                        ?? null;

                    if (!$instructor) {
                        $instructor = $this->resolveInstructorFromIdNo($row);
                    }

                    if (!$instructor) {
                        continue;
                    }

                    // -------------------------
                    // validate id
                    // -------------------------
                    $instrNumeric = preg_replace('/\D+/', '', (string)(
                        $row['id_no']
                        ?? $row['employee_id_no']
                        ?? $row['instr_id_no']
                        ?? $row['instructor_id_no']
                        ?? ''
                    ));

                    if ($instrNumeric === '') {
                        continue;
                    }

                    // -------------------------
                    // course info
                    // -------------------------
                    $code = $row['course_code']
                        ?? $row['subject_code']
                        ?? $row['subj_code']
                        ?? $row['code']
                        ?? $row['course']
                        ?? '';

                    $title = $row['course_description']
                        ?? $row['course_title']
                        ?? $row['subject_title']
                        ?? $row['title']
                        ?? $row['description']
                        ?? '';

                    // -------------------------
                    // term
                    // -------------------------
                    if (!empty($row['school_year_from']) && !empty($row['school_year_to'])) {
                        $term = "S.Y. {$row['school_year_from']}-{$row['school_year_to']} - " . ($row['semester'] ?? '');
                    } else {
                        $term = $row['term'] ?? $row['semester'] ?? 'Current Term';
                    }

                    // -------------------------
                    // initials (lightweight)
                    // -------------------------
                    $initials = $this->makeInitials($instructor);

                    if (!isset($instructors[$instructor])) {
                        $instructors[$instructor] = [
                            'initials' => $initials,
                            'instructor' => $instructor,
                            'subjects' => [],
                        ];
                    }

                    $instructors[$instructor]['subjects'][] = [
                        'code' => $code,
                        'title' => $title,
                        'course_description' => $title,
                        'term' => $term,
                        'id_no' => $instrNumeric,
                    ];
                }

                return array_values($instructors);

            } catch (\Exception $e) {
                return [];
            }
        });
    }

    /**
     * 🔥 Extract instructor safely without heavy regex chains in main loop
     */
    private function resolveInstructorFromIdNo(array $row): ?string
    {
        $idno = $row['id_no']
            ?? $row['employee_id_no']
            ?? $row['instr_id_no']
            ?? null;

        if (!$idno) return null;

        $candidate = (string) $idno;

        if (str_contains($candidate, ',')) {
            $parts = array_map('trim', explode(',', $candidate));
            if (count($parts) >= 2) {
                $candidate = $parts[1] . ' ' . $parts[0];
            }
        }

        if (str_contains($candidate, '-')) {
            $parts = explode('-', $candidate);
            $candidate = trim(end($parts));
        }

        $candidate = preg_replace('/\d+/', '', $candidate);
        $candidate = preg_replace('/[_\.\(\)\[\]]/', ' ', $candidate);
        $candidate = trim(preg_replace('/\s+/', ' ', $candidate));

        return str_word_count($candidate) >= 2
            ? ucwords(strtolower($candidate))
            : null;
    }

    /**
     * 🔥 lightweight initials generator
     */
    private function makeInitials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name));
        $initials = '';

        foreach ($words as $w) {
            if ($w === '') continue;
            $initials .= strtoupper($w[0]);
            if (strlen($initials) >= 3) break;
        }

        return $initials ?: strtoupper(substr($name, 0, 3));
    }

    protected function getAllowedProgramIdsForUser($user): array
    {
        if ($user === null) return [];

        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return [];
        }

        if (!(
            (method_exists($user, 'isDean') && $user->isDean())
            || (method_exists($user, 'isAssociateDean') && $user->isAssociateDean())
        )) {
            return [];
        }

        $collegeId = $user->dean?->college_id ?? $user->associateDean?->college_id ?? $user->college_id ?? null;
        if (!$collegeId) return [];

        try {
            if (!Schema::connection('lnu_poes')->hasTable('programs')) {
                return [];
            }

            return DB::connection('lnu_poes')
                ->table('programs')
                ->where('college_id', $collegeId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

        } catch (\Exception $e) {
            return [];
        }
    }

    protected function canAccessEvaluationForUser($user): bool
    {
        if ($user === null) return false;

        if (method_exists($user, 'canEvaluateFaculty')) {
            return $user->canEvaluateFaculty();
        }

        return method_exists($user, 'isUnitHead') && $user->isUnitHead();
    }

    protected function sharedUserPayload($user): array
    {
        if ($user === null) return [];

        return [
            'id' => $user->id,
            'id_no' => $user->id_no,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'middlename' => $user->middlename,
            'extname' => $user->extname,
            'profile_photo_url' => $user->personalInformation?->profile_photo_path
                ? asset('storage/' . $user->personalInformation->profile_photo_path)
                : null,
            'role' => method_exists($user, 'resolveRole') ? $user->resolveRole() : null,
            'isAdmin' => method_exists($user, 'isAdmin') ? $user->isAdmin() : false,
            'isDean' => method_exists($user, 'isDean') ? $user->isDean() : false,
            'isAssociateDean' => method_exists($user, 'isAssociateDean') ? $user->isAssociateDean() : false,
            'isUnitHead' => method_exists($user, 'isUnitHead') ? $user->isUnitHead() : false,
            'canEvaluateFaculty' => method_exists($user, 'canEvaluateFaculty') ? $user->canEvaluateFaculty() : false,
        ];
    }

    protected function hasPendingEvaluations($user): bool
    {
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

    protected function getAssignedEvaluationInstructorIdNos($user, int $schoolYearId): array
    {
        if (! $user) {
            return [];
        }

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

    protected function commonInertiaProps($user, array $pageSpecificProps = []): array
    {
        return array_merge([
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('my-profile.edit'),
            'accountSettingsUrl' => route('account-settings.edit'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => $this->sharedUserPayload($user),
            'hasPendingEvaluations' => $this->hasPendingEvaluations($user),
        ], $pageSpecificProps);
    }
}