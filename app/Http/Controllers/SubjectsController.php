<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use App\Models\UnitHeadGrade;
use App\Models\Poes\PoesSubjects;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class SubjectsController extends Controller
{
    use FacultyData;

    public function index(Request $request)
    {
        $facultyEvaluations = $this->getFacultyEvaluations();
        $currentUser = $request->user();
        $canAccessEvaluation = $this->canAccessEvaluationForUser($currentUser);
        $profilePhotoUrl = $currentUser?->personalInformation?->profile_photo_path
            ? asset('storage/' . $currentUser->personalInformation->profile_photo_path)
            : null;

        $firstName = trim((string) ($currentUser?->firstname ?? ''));
        $lastName = trim((string) ($currentUser?->lastname ?? ''));

        try {
            $normalizedLastName = mb_strtolower(trim($lastName));
            $normalizedIdNo = preg_replace('/\s+/', '', (string) ($currentUser?->id_no ?? ''));

            $rows = PoesSubjects::query()
                ->where(function ($q) use ($currentUser, $lastName) {
                    $idNo = $currentUser?->id_no;

                    if ($idNo) {
                        $q->orWhereRaw('CAST(id_number AS CHAR) = ?', [(string) $idNo]);
                    }

                    if ($idNo) {
                        $q->orWhereRaw('CAST(id_no AS CHAR) = ?', [(string) $idNo]);
                    }

                    if ($lastName !== '') {
                        $q->orWhereRaw('LOWER(TRIM(instructor)) LIKE ?', ['%' . mb_strtolower(trim($lastName)) . '%']);
                    }
                })
                ->get();

            if ($rows->isEmpty() && $normalizedIdNo !== '') {
                $rows = PoesSubjects::query()
                    ->whereRaw('CAST(id_number AS CHAR) = ?', [$normalizedIdNo], 'and')
                    ->get();
            }
        } catch (\Exception $e) {
            $rows = collect();
        }

        $schoolYearMetaById = [];

        try {
            $poesSchema = Schema::connection('lnu_poes');
            $schoolYearTable = null;

            foreach (['school_years', 'school_year', 'schoolyear'] as $candidate) {
                if ($poesSchema->hasTable($candidate)) {
                    $schoolYearTable = $candidate;
                    break;
                }
            }

            if ($schoolYearTable !== null) {
                $columns = $poesSchema->getColumnListing($schoolYearTable);

                $idColumn = in_array('id', $columns, true)
                    ? 'id'
                    : (in_array('school_year_id', $columns, true) ? 'school_year_id' : null);

                $semesterColumn = null;
                foreach (['semester', 'term', 'semester_name', 'term_name'] as $candidate) {
                    if (in_array($candidate, $columns, true)) {
                        $semesterColumn = $candidate;
                        break;
                    }
                }

                $yearFromColumn = null;
                foreach (['school_year_from', 'year_from', 'start_year'] as $candidate) {
                    if (in_array($candidate, $columns, true)) {
                        $yearFromColumn = $candidate;
                        break;
                    }
                }

                $yearToColumn = null;
                foreach (['school_year_to', 'year_to', 'end_year'] as $candidate) {
                    if (in_array($candidate, $columns, true)) {
                        $yearToColumn = $candidate;
                        break;
                    }
                }

                if ($idColumn !== null) {
                    $schoolYearRows = DB::connection('lnu_poes')
                        ->table($schoolYearTable)
                        ->selectRaw($idColumn . ' as ref_id')
                        ->when($semesterColumn !== null, function ($query) use ($semesterColumn) {
                            $query->selectRaw($semesterColumn . ' as ref_semester');
                        })
                        ->when($yearFromColumn !== null, function ($query) use ($yearFromColumn) {
                            $query->selectRaw($yearFromColumn . ' as ref_year_from');
                        })
                        ->when($yearToColumn !== null, function ($query) use ($yearToColumn) {
                            $query->selectRaw($yearToColumn . ' as ref_year_to');
                        })
                        ->get();

                    foreach ($schoolYearRows as $metaRow) {
                        $refId = (string) ($metaRow->ref_id ?? '');

                        if ($refId === '') {
                            continue;
                        }

                        $semester = trim((string) ($metaRow->ref_semester ?? ''));
                        $yearFrom = trim((string) ($metaRow->ref_year_from ?? ''));
                        $yearTo = trim((string) ($metaRow->ref_year_to ?? ''));

                        $schoolYearLabel = '';
                        if ($yearFrom !== '' && $yearTo !== '') {
                            $schoolYearLabel = 'S.Y. ' . $yearFrom . '-' . $yearTo;
                        }

                        $termLabel = trim(collect([$schoolYearLabel, $semester])->filter()->implode(' - '));

                        $schoolYearMetaById[$refId] = [
                            'semester' => $semester !== '' ? $semester : null,
                            'term' => $termLabel !== '' ? $termLabel : null,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $schoolYearMetaById = [];
        }

        $availableTerms = collect($schoolYearMetaById)
            ->pluck('term')
            ->filter(function ($value) {
                return is_string($value) && trim($value) !== '';
            })
            ->map(function ($value) {
                return trim((string) $value);
            })
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (empty($availableTerms)) {
            try {
                $availableTerms = PoesSubjects::query()
                    ->select('school_year_id')
                    ->whereNotNull('school_year_id')
                    ->distinct()
                    ->pluck('school_year_id')
                    ->map(function ($schoolYearId) use ($schoolYearMetaById) {
                        $schoolYearId = (string) $schoolYearId;
                        $metaTerm = $schoolYearMetaById[$schoolYearId]['term'] ?? null;

                        if (is_string($metaTerm) && trim($metaTerm) !== '') {
                            return trim($metaTerm);
                        }

                        return 'School Year #' . $schoolYearId;
                    })
                    ->filter(function ($value) {
                        return is_string($value) && trim($value) !== '';
                    })
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
            } catch (\Exception $e) {
                $availableTerms = [];
            }
        }

        $subjects = $rows->map(function ($row) use ($schoolYearMetaById) {
            $row = is_array($row) ? $row : (method_exists($row, 'toArray') ? $row->toArray() : (array) $row);

            $schoolYearId = isset($row['school_year_id']) ? (string) $row['school_year_id'] : '';
            $schoolYearMeta = $schoolYearId !== '' ? ($schoolYearMetaById[$schoolYearId] ?? null) : null;

            $semester = trim((string) ($row['semester'] ?? $row['term'] ?? ($schoolYearMeta['semester'] ?? '')));
            $term = trim((string) ($row['term'] ?? ($schoolYearMeta['term'] ?? '')));

            if ($term === '' && $semester !== '') {
                $term = $semester;
            }

            if ($term === '' && $schoolYearId !== '') {
                $term = 'School Year #' . $schoolYearId;
            }

            return [
                'course_code' => $row['course_code'] ?? '',
                'course_description' => $row['course_description'] ?? '',
                'course_units' => $row['course_units'] ?? null,
                'section_code' => $row['section_code'] ?? null,
                'schedule_time' => $row['schedule_time'] ?? null,
                'schedule_days' => $row['schedule_days'] ?? null,
                'room' => $row['room'] ?? null,
                'school_year_id' => $row['school_year_id'] ?? null,
                'semester' => $semester !== '' ? $semester : null,
                'term' => $term !== '' ? $term : null,
            ];
        })->values()->all();

        $subjectsProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('my-profile.edit'),
            'accountSettingsUrl' => route('account-settings.edit'),
            'unitHeadGradeStoreUrl' => route('unit-head-grades.store'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
                'display_name' => trim(collect([$currentUser?->firstname, $currentUser?->middlename, $currentUser?->lastname, $currentUser?->extname])->filter()->implode(' ')),
                'profile_photo_url' => $profilePhotoUrl,
            ],
            'subjects' => $subjects,
            'availableTerms' => $availableTerms,
            'hasPendingEvaluations' => UnitHeadGrade::query()
                ->where('user_id', $currentUser->id)
                ->distinct('instructor')
                ->count('instructor') < count($facultyEvaluations),
            'canAccessEvaluation' => $canAccessEvaluation,
        ];

        return Inertia::render('SubjectsPage', $subjectsProps);
    }
}
