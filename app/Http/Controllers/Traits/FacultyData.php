<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait FacultyData
{
    protected function getFacultyEvaluations($user = null): array
    {
        try {
            $rows = DB::connection('lnu_poes')->table('enrollment_courses')->get();

            $allowedProgramIds = $this->getAllowedProgramIdsForUser($user);

            if ($rows->isEmpty()) {
                return [];
            }

            $instructors = [];

            foreach ($rows as $row) {
                $row = (array) $row;

                if (! empty($allowedProgramIds)) {
                    $programId = isset($row['program_id']) ? (int) $row['program_id'] : null;

                    if ($programId === null || ! in_array($programId, $allowedProgramIds, true)) {
                        continue;
                    }
                }

                $instructor = $row['instructor'] ?? $row['instructor_name'] ?? $row['faculty_name'] ?? $row['name'] ?? null;

                if (! $instructor) {
                    $idno = $row['id_no'] ?? $row['employee_id_no'] ?? $row['instr_id_no'] ?? null;
                    if ($idno) {
                        $candidate = (string) $idno;

                        if (strpos($candidate, ',') !== false) {
                            $parts = array_map('trim', explode(',', $candidate));
                            if (count($parts) >= 2) {
                                $candidate = $parts[1] . ' ' . $parts[0];
                            }
                        }

                        if (strpos($candidate, '-') !== false) {
                            $parts = preg_split('/-/', $candidate);
                            $candidate = trim(end($parts));
                        }

                        $candidate = preg_replace('/\d+/', '', $candidate);
                        $candidate = preg_replace('/[_\.\(\)\[\]]/', ' ', $candidate);
                        $candidate = trim(preg_replace('/\s+/', ' ', $candidate));

                        if (str_word_count($candidate) >= 2) {
                            $instructor = ucwords(strtolower($candidate));
                        }
                    }
                }

                if (! $instructor) {
                    continue;
                }

                $instrIdRaw = $row['id_no'] ?? $row['employee_id_no'] ?? $row['instr_id_no'] ?? $row['instructor_id_no'] ?? null;
                $instrNumeric = null;
                if ($instrIdRaw !== null) {
                    $digits = preg_replace('/\D+/', '', (string) $instrIdRaw);
                    if ($digits !== '') {
                        $instrNumeric = (int) $digits;
                    }
                }

                if ($instrNumeric === null) {
                    continue;
                }

                $code = $row['course_code'] ?? $row['subject_code'] ?? $row['subj_code'] ?? $row['code'] ?? ($row['course'] ?? '');
                $title = $row['course_description'] ?? $row['course_title'] ?? $row['subject_title'] ?? $row['title'] ?? $row['description'] ?? '';

                if (isset($row['school_year_from'], $row['school_year_to'], $row['semester'])) {
                    $term = sprintf('S.Y. %s-%s - %s', $row['school_year_from'], $row['school_year_to'], $row['semester']);
                } elseif (! empty($row['term'])) {
                    $term = $row['term'];
                } elseif (! empty($row['semester'])) {
                    $term = $row['semester'];
                } else {
                    $term = 'Current Term';
                }

                $words = preg_split('/\s+/', trim($instructor));
                $initials = '';
                foreach ($words as $w) {
                    if ($w === '') continue;
                    $initials .= strtoupper(mb_substr($w, 0, 1));
                    if (mb_strlen($initials) >= 3) break;
                }

                if (! isset($instructors[$instructor])) {
                    $instructors[$instructor] = [
                        'initials' => $initials ?: strtoupper(substr($instructor, 0, 3)),
                        'instructor' => $instructor,
                        'subjects' => [],
                    ];
                }

                $instructors[$instructor]['subjects'][] = [
                    'code' => $code ?? '',
                    'title' => $title ?? '',
                    'course_description' => $row['course_description'] ?? ($title ?? ''),
                    'term' => $term,
                    'id_no' => $row['id_no'] ?? $row['employee_id_no'] ?? $row['instr_id_no'] ?? $row['instructor_id_no'] ?? null,
                ];
            }

            return array_values($instructors);
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function getAllowedProgramIdsForUser($user): array
    {
        if ($user === null) {
            return [];
        }

        // Admins see all programs; Deans see programs by their college
        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return [];  // Empty array signals no filtering (all programs)
        }

        if (! method_exists($user, 'isDean') || ! $user->isDean()) {
            return [];
        }

        $collegeId = $user->college_id ?? null;

        if ($collegeId === null) {
            return [];
        }

        try {
            if (! Schema::connection('lnu_poes')->hasTable('programs')) {
                return [];
            }

            $columns = Schema::connection('lnu_poes')->getColumnListing('programs');

            if (! in_array('college_id', $columns, true)) {
                return [];
            }

            return DB::connection('lnu_poes')->table('programs')
                ->where('college_id', $collegeId)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->filter(function ($id) {
                    return $id > 0;
                })
                ->values()
                ->all();
        } catch (\Exception $e) {
            return [];
        }
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

    protected function sharedUserPayload($user): array
    {
        if ($user === null) {
            return [];
        }

        $profilePhotoUrl = $user->personalInformation?->profile_photo_path
            ? asset('storage/' . $user->personalInformation->profile_photo_path)
            : null;

        return [
            'id' => $user->id,
            'id_no' => $user->id_no,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'middlename' => $user->middlename,
            'extname' => $user->extname,
            'profile_photo_url' => $profilePhotoUrl,
            'role' => method_exists($user, 'resolveRole') ? $user->resolveRole() : null,
            'isAdmin' => method_exists($user, 'isAdmin') ? $user->isAdmin() : false,
            'isDean' => method_exists($user, 'isDean') ? $user->isDean() : false,
            'isUnitHead' => method_exists($user, 'isUnitHead') ? $user->isUnitHead() : false,
            'canEvaluateFaculty' => method_exists($user, 'canEvaluateFaculty') ? $user->canEvaluateFaculty() : false,
        ];
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
        ], $pageSpecificProps);
    }
}
