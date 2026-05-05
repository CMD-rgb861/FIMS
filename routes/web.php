<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\FacultyEvaluationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UnitHeadGradeController;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\Poes\PoesSubjects;
use App\Models\UnitHeadGrade;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

$facultyEvaluations = (static function () {
    $fallback = [
        [
            'initials' => 'RD',
            'instructor' => 'Raphy Dalan',
            'subjects' => [
                ['code' => 'G-IT 204', 'title' => 'Advanced Operations System and Networking', 'term' => 'S.Y. 2025-2026 - 2nd Semester'],
                ['code' => 'G-IT 214', 'title' => 'Systems Integration and Architecture', 'term' => 'S.Y. 2025-2026 - 2nd Semester'],
            ],
        ],
        [
            'initials' => 'ML',
            'instructor' => 'Mark Lester Laurente',
            'subjects' => [
                ['code' => 'G-IT 207', 'title' => 'Information Management', 'term' => 'S.Y. 2025-2026 - 2nd Semester'],
                ['code' => 'G-IT 217', 'title' => 'Database Administration', 'term' => 'S.Y. 2025-2026 - 2nd Semester'],
            ],
        ],
        [
            'initials' => 'RC',
            'instructor' => 'Rico Combinido',
            'subjects' => [
                ['code' => 'G-IT 205', 'title' => 'Advanced Programming II', 'term' => 'S.Y. 2025-2026 - 2nd Semester'],
                ['code' => 'G-IT 215', 'title' => 'Web Systems and Technologies', 'term' => 'S.Y. 2025-2026 - 2nd Semester'],
            ],
        ],
        [
            'initials' => 'LG',
            'instructor' => 'Louvesa Idda Galban',
            'subjects' => [
                ['code' => 'G-IT 208', 'title' => 'Systems Analysis and Design', 'term' => 'S.Y. 2025-2026 - 2nd Semester'],
                ['code' => 'G-IT 218', 'title' => 'Project Management in IT', 'term' => 'S.Y. 2025-2026 - 2nd Semester'],
            ],
        ],
    ];

    try {
        $rows = DB::connection('lnu_poes')->table('enrollment_courses')->get();

        if ($rows->isEmpty()) {
            return $fallback;
        }

        $instructors = [];

        foreach ($rows as $row) {
            $row = (array) $row;

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

            // Try to extract instructor id_no from various possible columns
            $instrIdRaw = $row['id_no'] ?? $row['employee_id_no'] ?? $row['instr_id_no'] ?? $row['instructor_id_no'] ?? null;
            $instrNumeric = null;
            if ($instrIdRaw !== null) {
                $digits = preg_replace('/\D+/', '', (string) $instrIdRaw);
                if ($digits !== '') {
                    $instrNumeric = (int) $digits;
                }
            }

            // Only include sample instructors with id_no 1..6
            if ($instrNumeric === null || $instrNumeric < 1 || $instrNumeric > 6) {
                continue;
            }

            $code = $row['course_code'] ?? $row['subject_code'] ?? $row['subj_code'] ?? $row['code'] ?? ($row['course'] ?? '');
            $title = $row['course_title'] ?? $row['subject_title'] ?? $row['title'] ?? $row['description'] ?? '';

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
                'term' => $term,
                'id_no' => $row['id_no'] ?? $row['employee_id_no'] ?? $row['instr_id_no'] ?? $row['instructor_id_no'] ?? null,
            ];
        }

        // Ensure deterministic ordering
        return array_values($instructors ?: $fallback);
    } catch (\Exception $e) {
        return $fallback;
    }
})();

$canAccessEvaluationForUser = static function ($user): bool {
    return $user !== null
        && $user->isUnitHead();
};

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () use ($facultyEvaluations, $canAccessEvaluationForUser) {
    Route::get('/my-profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/my-profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/account-settings', [ProfileController::class, 'accountSettings'])->name('account.settings.edit');
    Route::put('/account-settings', [ProfileController::class, 'updateAccountSettings'])->name('account.settings.update');

    Route::get('/subjects', function () use ($facultyEvaluations, $canAccessEvaluationForUser) {
        $currentUser = request()->user();
        $canAccessEvaluation = $canAccessEvaluationForUser($currentUser);
        $profilePhotoUrl = $currentUser?->personalInformation?->profile_photo_path
            ? asset('storage/' . $currentUser->personalInformation->profile_photo_path)
            : null;

        $firstName = trim((string) ($currentUser?->firstname ?? ''));
        $lastName = trim((string) ($currentUser?->lastname ?? ''));

        // Try to load actual enrollment courses from the POES database for this faculty
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
                    ->whereRaw('CAST(id_number AS CHAR) = ?', [$normalizedIdNo])
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
            // Eloquent models must be converted via toArray(); direct casting loses attribute keys.
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
            'profileUrl' => route('profile.edit'),
            'accountSettingsUrl' => route('account.settings.edit'),
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

        return view('subjects', [
            'subjectsProps' => $subjectsProps,
        ]);
    })->name('subjects');

    Route::get('/dashboard', function () use ($facultyEvaluations, $canAccessEvaluationForUser) {
        $currentUser = request()->user();
        $canAccessEvaluation = $canAccessEvaluationForUser($currentUser);
        $profilePhotoUrl = $currentUser?->personalInformation?->profile_photo_path
            ? asset('storage/' . $currentUser->personalInformation->profile_photo_path)
            : null;

        $firstName = trim((string) ($currentUser?->firstname ?? ''));
        $middleName = trim((string) ($currentUser?->middlename ?? ''));
        $lastName = trim((string) ($currentUser?->lastname ?? ''));
        $extName = trim((string) ($currentUser?->extname ?? ''));

        $nameCandidates = collect([
            trim(collect([$firstName, $lastName])->filter()->implode(' ')),
            trim(collect([$firstName, $middleName, $lastName])->filter()->implode(' ')),
            trim(collect([$firstName, $middleName, $lastName, $extName])->filter()->implode(' ')),
            trim(collect([$firstName, $lastName, $extName])->filter()->implode(' ')),
            trim($lastName !== '' && $firstName !== '' ? ($lastName . ', ' . $firstName) : ''),
            trim($lastName !== '' && $firstName !== '' ? ($lastName . ', ' . collect([$firstName, $middleName])->filter()->implode(' ')) : ''),
            trim($lastName !== '' && $firstName !== '' ? ($lastName . ', ' . collect([$firstName, $middleName, $extName])->filter()->implode(' ')) : ''),
        ])
            ->filter(function ($value) {
                return is_string($value) && trim($value) !== '';
            })
            ->map(function ($value) {
                return trim((string) $value);
            })
            ->unique(function ($value) {
                return mb_strtolower($value);
            })
            ->values()
            ->all();

        $submissionBaseQuery = SupervisorEvaluationSubmission::query();
        $gradeBaseQuery = UnitHeadGrade::query();

        if ($canAccessEvaluation) {
            $submissionBaseQuery->where('user_id', $currentUser->id);
            $gradeBaseQuery->where('user_id', $currentUser->id);
        } else {
            if (!empty($nameCandidates)) {
                $submissionBaseQuery->where(function ($query) use ($nameCandidates) {
                    foreach ($nameCandidates as $name) {
                        $query->orWhere('instructor', $name);
                    }
                });

                $gradeBaseQuery->where(function ($query) use ($nameCandidates) {
                    foreach ($nameCandidates as $name) {
                        $query->orWhere('instructor', $name);
                    }
                });
            } else {
                $submissionBaseQuery->where('id', 0);
                $gradeBaseQuery->where('id', 0);
            }
        }

        $totalInstructors = count($facultyEvaluations);
        $evaluatedInstructors = (clone $submissionBaseQuery)
            ->pluck('instructor')
            ->unique()
            ->values()
            ->all();

        $evaluatedCount = count($evaluatedInstructors);
        $pendingCount = max($totalInstructors - $evaluatedCount, 0);
        $completionRate = $totalInstructors > 0
            ? round(($evaluatedCount / $totalInstructors) * 100, 2)
            : 0;

        $recentEvaluations = (clone $submissionBaseQuery)
            ->latest('submitted_at')
            ->take(5)
            ->get()
            ->map(function ($submission) {
                $ratings = $submission->ratings ?? [];
                $totalScore = collect($ratings)->sum(function ($score) {
                    return (int) $score;
                });

                return [
                    'instructor' => $submission->instructor,
                    'course_code' => $submission->course_code,
                    'course_title' => $submission->course_title,
                    'rating_percentage' => round(($totalScore / 75) * 100, 2),
                    'submitted_at' => optional($submission->submitted_at)->format('M d, Y h:i A') ?? '-',
                ];
            })
            ->values()
            ->all();

        $latestEvaluation = $recentEvaluations[0] ?? null;
        $unitHeadEvaluationRating = $latestEvaluation
            ? ($latestEvaluation['rating_percentage'] . '%')
            : 'N/A';
        $unitHeadEvaluationHelper = $latestEvaluation
            ? (($latestEvaluation['course_code'] ?: 'N/A') . ' · ' . $latestEvaluation['instructor'])
            : 'Unit Head evaluation rating will appear once submitted.';

        $unitHeadGrades = (clone $gradeBaseQuery)
            ->latest('submitted_at')
            ->get()
            ->unique(function ($grade) {
                return $grade->instructor . '|' . $grade->course_code;
            })
            ->values()
            ->map(function ($grade) {
                return [
                    'instructor' => $grade->instructor,
                    'course_code' => $grade->course_code,
                    'course_title' => $grade->course_title,
                    'term' => $grade->term,
                    'grade' => (float) $grade->grade,
                    'submitted_at' => optional($grade->submitted_at)->format('M d, Y h:i A') ?? '-',
                ];
            })
            ->all();

        $averageGrade = count($unitHeadGrades) > 0
            ? round(collect($unitHeadGrades)->avg('grade'), 2)
            : null;

        $latestUnitHeadGrade = $unitHeadGrades[0] ?? null;

        $dashboardProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('profile.edit'),
            'accountSettingsUrl' => route('account.settings.edit'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
                'profile_photo_url' => $profilePhotoUrl,
                'isUnitHead' => $canAccessEvaluation,
            ],
            'summaryCards' => [
                [
                    'label' => 'Total Instructors',
                    'value' => $totalInstructors,
                    'helper' => 'Faculty members assigned this term.',
                ],
                [
                    'label' => 'Evaluated',
                    'value' => $evaluatedCount,
                    'helper' => 'Instructors you already evaluated.',
                ],
                [
                    'label' => 'Pending',
                    'value' => $pendingCount,
                    'helper' => 'Instructors left to evaluate.',
                ],
                [
                    'label' => 'Completion Rate',
                    'value' => $completionRate . '%',
                    'helper' => 'Overall evaluation completion.',
                ],
            ],
            'unitHeadGrades' => $unitHeadGrades,
            'unitHeadEvaluationRating' => $unitHeadEvaluationRating,
            'unitHeadEvaluationHelper' => $unitHeadEvaluationHelper,
            'gradeSummaryCards' => [
                [
                    'label' => $canAccessEvaluation ? 'Unit Head Grade' : 'Faculty Grade',
                    'value' => isset($latestUnitHeadGrade['grade'])
                        ? number_format((float) $latestUnitHeadGrade['grade'], 2)
                        : 'N/A',
                    'helper' => $latestUnitHeadGrade
                        ? $latestUnitHeadGrade['course_code'] . ' · ' . $latestUnitHeadGrade['instructor']
                        : ($canAccessEvaluation ? 'No grade issued yet.' : 'No grade received yet.'),
                ],
                [
                    'label' => 'Subjects Graded',
                    'value' => count($unitHeadGrades),
                    'helper' => 'Unique subject and instructor entries.',
                ],
                [
                    'label' => 'Average Grade',
                    'value' => $averageGrade !== null ? number_format($averageGrade, 2) : 'N/A',
                    'helper' => 'Average of your recorded grades.',
                ],
            ],
            'recentEvaluations' => $recentEvaluations,
            'hasPendingEvaluations' => $pendingCount > 0,
            'canAccessEvaluation' => $canAccessEvaluation,
        ];

        return view('dashboard', [
            'dashboardProps' => $dashboardProps,
        ]);
    })->name('dashboard');

    Route::get('/evaluation', function () use ($facultyEvaluations, $canAccessEvaluationForUser) {
        $currentUser = request()->user();
        $canAccessEvaluation = $canAccessEvaluationForUser($currentUser);
        abort_if(! $canAccessEvaluation, 403);

        $profilePhotoUrl = $currentUser?->personalInformation?->profile_photo_path
            ? asset('storage/' . $currentUser->personalInformation->profile_photo_path)
            : null;

        $schoolYearRows = DB::connection('lnu_poes')
            ->table('school_years')
            ->select(['id', 'school_year_from', 'school_year_to', 'semester', 'is_active'])
            ->orderByDesc('id')
            ->get();

        $schoolYears = $schoolYearRows
            ->map(function ($row) {
                return [
                    'label' => sprintf(
                        'S.Y. %s-%s - %s',
                        $row->school_year_from,
                        $row->school_year_to,
                        $row->semester
                    ),
                    'value' => (string) $row->id,
                ];
            })
            ->values()
            ->all();

        if (empty($schoolYears)) {
            $schoolYears = [
                ['label' => 'S.Y. 2025-2026 - 2', 'value' => '2025-2026-2'],
                ['label' => 'S.Y. 2025-2026 - 1', 'value' => '2025-2026-1'],
            ];
        }

        $terms = [
            ['label' => 'All', 'value' => 'all'],
            ['label' => 'For Evaluation', 'value' => 'for-evaluation'],
            ['label' => 'Evaluated', 'value' => 'evaluated'],
        ];
        $selectedSchoolYear = request('sy', $schoolYears[0]['value'] ?? '');
        $selectedSchoolYearId = ctype_digit((string) $selectedSchoolYear)
            ? (int) $selectedSchoolYear
            : null;
        $selectedSchoolYearRow = $selectedSchoolYearId !== null
            ? $schoolYearRows->firstWhere('id', $selectedSchoolYearId)
            : null;
        $scheduleWindow = null;

        if ($selectedSchoolYearId !== null) {
            $scheduleWindow = DB::connection('lnu_poes')
                ->table('evaluation_schedules')
                ->where('school_year_id', $selectedSchoolYearId)
                ->orderByDesc('id')
                ->first();
        }

        $today = Carbon::today();
        $isEvaluationOpen = false;

        if ($scheduleWindow) {
            $scheduleStart = Carbon::parse($scheduleWindow->date_from)->startOfDay();
            $scheduleEnd = Carbon::parse($scheduleWindow->date_extension ?: $scheduleWindow->date_to)->endOfDay();
            $isEvaluationOpen = $today->betweenIncluded($scheduleStart, $scheduleEnd);
        } elseif ($selectedSchoolYearRow) {
            $isEvaluationOpen = (bool) $selectedSchoolYearRow->is_active;
        }

        $isEvaluationClosed = !$isEvaluationOpen;
        $evaluationStatusLabel = $isEvaluationClosed ? 'Closed Evaluation' : 'Open for Evaluation';

        $selectedTerm = request('term', 'all');
        $selectedSubject = request('subject', '');

        $subjects = collect($facultyEvaluations)
            ->map(function ($f) {
                return ['label' => $f['instructor'], 'value' => $f['instructor']];
            })
            ->prepend(['label' => 'Select a name to evaluate', 'value' => ''])
            ->values()
            ->all();

        $evaluatedInstructors = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->pluck('instructor')
            ->unique()
            ->values()
            ->all();

        $latestEvaluationsByInstructor = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->latest('submitted_at')
            ->get()
            ->unique('instructor')
            ->keyBy('instructor');

        $evaluations = array_map(function ($faculty) use ($evaluatedInstructors, $latestEvaluationsByInstructor) {
            $primarySubject = $faculty['subjects'][0] ?? ['code' => '', 'title' => '', 'term' => ''];
            $latestEvaluation = $latestEvaluationsByInstructor->get($faculty['instructor']);
            $ratings = $latestEvaluation?->ratings ?? [];
            $scores = collect($ratings)
                ->map(function ($score, $benchmark) {
                    return [
                        'benchmark' => $benchmark,
                        'score' => (int) $score,
                    ];
                })
                ->sortBy(function ($row) {
                    return (int) preg_replace('/[^0-9]/', '', $row['benchmark']);
                })
                ->values()
                ->all();
            $totalScore = collect($scores)->sum('score');
            $ratingPercentage = round(($totalScore / 75) * 100, 2);

            return [
                'initials' => $faculty['initials'],
                'code' => $primarySubject['code'],
                'title' => $primarySubject['title'],
                'instructor' => $faculty['instructor'],
                'term' => $primarySubject['term'],
                'subjects' => $faculty['subjects'],
                'evaluated' => in_array($faculty['instructor'], $evaluatedInstructors, true),
                'evaluation_result' => $latestEvaluation ? [
                    'instructor' => $latestEvaluation->instructor,
                    'course_code' => $latestEvaluation->course_code,
                    'course_title' => $latestEvaluation->course_title,
                    'term' => $latestEvaluation->term,
                    'scores' => $scores,
                    'total_score' => $totalScore,
                    'rating_percentage' => $ratingPercentage,
                ] : null,
            ];
        }, $facultyEvaluations);

        if ($selectedTerm === 'for-evaluation') {
            $evaluations = array_values(array_filter($evaluations, function ($item) {
                return !$item['evaluated'];
            }));
        }

        if ($selectedTerm === 'evaluated') {
            $evaluations = array_values(array_filter($evaluations, function ($item) {
                return $item['evaluated'];
            }));
        }

        if (!empty($selectedSubject)) {
            $evaluations = array_values(array_filter($evaluations, function ($item) use ($selectedSubject) {
                return $item['instructor'] === $selectedSubject;
            }));
        }

        $evaluationProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('profile.edit'),
            'accountSettingsUrl' => route('account.settings.edit'),
            'evaluationStoreUrl' => route('evaluations.store'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
                'profile_photo_url' => $profilePhotoUrl,
            ],
            'schoolYears' => $schoolYears,
            'terms' => $terms,
            'subjects' => $subjects,
            'evaluations' => $evaluations,
            'evaluatedInstructors' => $evaluatedInstructors,
            'selectedSchoolYear' => $selectedSchoolYear,
            'selectedTerm' => $selectedTerm,
            'selectedSubject' => $selectedSubject,
            'isEvaluationClosed' => $isEvaluationClosed,
            'evaluationStatusLabel' => $evaluationStatusLabel,
            'hasPendingEvaluations' => count($evaluatedInstructors) < count($facultyEvaluations),
            'canAccessEvaluation' => $canAccessEvaluation,
        ];

        return view('evaluation', [
            'evaluationProps' => $evaluationProps,
        ]);
    })->name('evaluation');

    Route::get('/grades', function () use ($facultyEvaluations, $canAccessEvaluationForUser) {
        $currentUser = request()->user();
        $canAccessEvaluation = $canAccessEvaluationForUser($currentUser);
        abort_if(! $canAccessEvaluation, 403);

        $profilePhotoUrl = $currentUser?->personalInformation?->profile_photo_path
            ? asset('storage/' . $currentUser->personalInformation->profile_photo_path)
            : null;

        $existingGrades = UnitHeadGrade::query()
            ->where('user_id', $currentUser->id)
            ->latest('submitted_at')
            ->get()
            ->keyBy(function ($grade) {
                return $grade->instructor . '|' . $grade->course_code;
            });

        $evaluations = collect($facultyEvaluations)
            ->map(function ($faculty) use ($existingGrades) {
                $primarySubject = $faculty['subjects'][0] ?? ['code' => '', 'title' => '', 'term' => ''];
                $gradeKey = $faculty['instructor'] . '|' . ($primarySubject['code'] ?? '');
                $existingGrade = $existingGrades->get($gradeKey);

                return [
                    'initials' => $faculty['initials'],
                    'instructor' => $faculty['instructor'],
                    'code' => $primarySubject['code'] ?? '',
                    'title' => $primarySubject['title'] ?? '',
                    'term' => $primarySubject['term'] ?? '',
                    'final_grade' => $existingGrade ? (float) $existingGrade->grade : null,
                ];
            })
            ->values()
            ->all();

        $gradedCount = collect($evaluations)
            ->filter(function ($item) {
                return $item['final_grade'] !== null;
            })
            ->count();

        $gradesProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'gradesUrl' => route('grades'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('profile.edit'),
            'accountSettingsUrl' => route('account.settings.edit'),
            'unitHeadGradeStoreUrl' => route('unit-head-grades.store'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
                'profile_photo_url' => $profilePhotoUrl,
            ],
            'evaluations' => $evaluations,
            'hasPendingEvaluations' => $gradedCount < count($facultyEvaluations),
            'canAccessEvaluation' => $canAccessEvaluation,
        ];

        return view('grades', [
            'gradesProps' => $gradesProps,
        ]);
    })->name('grades');

    Route::get('/reports', function () use ($facultyEvaluations, $canAccessEvaluationForUser) {
        $currentUser = request()->user();
        $canAccessEvaluation = $canAccessEvaluationForUser($currentUser);
        $profilePhotoUrl = $currentUser?->personalInformation?->profile_photo_path
            ? asset('storage/' . $currentUser->personalInformation->profile_photo_path)
            : null;

        $allSubmissions = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->latest('submitted_at')
            ->get();

        $evaluatedInstructors = $allSubmissions
            ->pluck('instructor')
            ->unique()
            ->values();

        $averageRating = $allSubmissions->count() > 0
            ? round($allSubmissions->avg(function ($submission) {
                $ratings = $submission->ratings ?? [];
                $totalScore = collect($ratings)->sum(function ($score) {
                    return (int) $score;
                });

                return ($totalScore / 75) * 100;
            }), 2)
            : 0;

        $passedGradesCount = UnitHeadGrade::query()
            ->where('user_id', $currentUser->id)
            ->whereNotNull('grade')
            ->count();

        $recentReports = $allSubmissions
            ->take(10)
            ->map(function ($submission) {
                $ratings = $submission->ratings ?? [];
                $totalScore = collect($ratings)->sum(function ($score) {
                    return (int) $score;
                });

                $latestGrade = UnitHeadGrade::query()
                    ->where('user_id', $submission->user_id)
                    ->where('instructor', $submission->instructor)
                    ->where('course_code', $submission->course_code)
                    ->latest('submitted_at')
                    ->first();

                return [
                    'instructor' => $submission->instructor,
                    'course_code' => $submission->course_code,
                    'course_title' => $submission->course_title,
                    'rating_percentage' => round(($totalScore / 75) * 100, 2),
                    'final_grade' => $latestGrade ? number_format((float) $latestGrade->grade, 1) : null,
                    'submitted_at' => optional($submission->submitted_at)->format('M d, Y h:i A') ?? '-',
                ];
            })
            ->values()
            ->all();

        $facultyList = collect($facultyEvaluations)
            ->map(function ($faculty, $index) use ($evaluatedInstructors) {
                return [
                    'initials' => $faculty['initials'],
                    'instructor' => $faculty['instructor'],
                    'subjects_count' => count($faculty['subjects'] ?? []),
                    'evaluated' => $evaluatedInstructors->contains($faculty['instructor']),
                    'employee_id_no' => 'EMP-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'detail_url' => route('reports.faculty', ['instructor' => $faculty['instructor']]),
                ];
            })
            ->values()
            ->all();

        $reportsProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('profile.edit'),
            'accountSettingsUrl' => route('account.settings.edit'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
                'profile_photo_url' => $profilePhotoUrl,
            ],
            'reportSummary' => [
                [
                    'label' => 'Submitted Evaluations',
                    'value' => $allSubmissions->count(),
                    'helper' => 'Total evaluation forms submitted.',
                ],
                [
                    'label' => 'Instructors Evaluated',
                    'value' => $evaluatedInstructors->count(),
                    'helper' => 'Unique faculty members evaluated.',
                ],
                [
                    'label' => 'Average Rating',
                    'value' => $averageRating . '%',
                    'helper' => 'Average score across all evaluations.',
                ],
                [
                    'label' => 'Posted Grades',
                    'value' => $passedGradesCount,
                    'helper' => 'Unit head grades recorded.',
                ],
            ],
            'recentReports' => $recentReports,
            'facultyList' => $facultyList,
            'hasPendingEvaluations' => $evaluatedInstructors->count() < count($facultyEvaluations),
            'canAccessEvaluation' => $canAccessEvaluation,
        ];

        return view('reports', [
            'reportsProps' => $reportsProps,
        ]);
    })->name('reports');

    Route::get('/reports/faculty/{instructor}', function (string $instructor) use ($facultyEvaluations, $canAccessEvaluationForUser) {
        $currentUser = request()->user();
        $canAccessEvaluation = $canAccessEvaluationForUser($currentUser);
        $profilePhotoUrl = $currentUser?->personalInformation?->profile_photo_path
            ? asset('storage/' . $currentUser->personalInformation->profile_photo_path)
            : null;

        $facultyCollection = collect($facultyEvaluations);
        $facultyIndex = $facultyCollection->search(function ($faculty) use ($instructor) {
            return strcasecmp($faculty['instructor'], $instructor) === 0;
        });

        abort_if($facultyIndex === false, 404);

        $facultyMeta = $facultyCollection->get($facultyIndex);
        $employeeIdNo = 'EMP-' . str_pad((string) ($facultyIndex + 1), 3, '0', STR_PAD_LEFT);

        $facultySubmissions = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->where('instructor', $facultyMeta['instructor'])
            ->latest('submitted_at')
            ->get();

        $setBreakdown = $facultySubmissions
            ->values()
            ->map(function ($submission, $index) {
                $setGrade = UnitHeadGrade::query()
                    ->where('user_id', $submission->user_id)
                    ->where('instructor', $submission->instructor)
                    ->where('course_code', $submission->course_code)
                    ->latest('submitted_at')
                    ->value('grade');

                $averageSetRating = $setGrade !== null ? (float) $setGrade : null;
                $studentCount = null;
                $weightedSetScore = ($averageSetRating !== null && $studentCount !== null)
                    ? $averageSetRating * $studentCount
                    : null;

                return [
                    'seq' => $index + 1,
                    'course_code' => $submission->course_code,
                    'year_section' => $submission->term,
                    'no_of_students' => $studentCount,
                    'average_set_rating' => $averageSetRating !== null ? number_format($averageSetRating, 2) : '-',
                    'weighted_set_score' => $weightedSetScore !== null ? number_format($weightedSetScore, 2) : '-',
                    'no_of_students_value' => $studentCount,
                    'average_set_rating_value' => $averageSetRating,
                    'weighted_set_score_value' => $weightedSetScore,
                ];
            })
            ->all();

        $tableRows = $facultySubmissions
            ->map(function ($submission) use ($employeeIdNo, $facultyMeta, $setBreakdown) {
                $ratings = $submission->ratings ?? [];
                $totalScore = collect($ratings)->sum(function ($score) {
                    return (int) $score;
                });

                $sefScore = round(($totalScore / 75) * 100, 2);

                $setGrade = UnitHeadGrade::query()
                    ->where('user_id', $submission->user_id)
                    ->where('instructor', $submission->instructor)
                    ->where('course_code', $submission->course_code)
                    ->latest('submitted_at')
                    ->value('grade');

                $status = $setGrade !== null ? 'Evaluated' : 'For Evaluation';

                return [
                    'id' => $submission->id,
                    'employee_id_no' => $employeeIdNo,
                    'employee_name' => $facultyMeta['instructor'],
                    'set_score' => $setGrade !== null ? number_format((float) $setGrade, 2) : '-',
                    'sef_score' => number_format($sefScore, 2) . '%',
                    'sef_total_score' => $totalScore,
                    'sef_rating' => number_format($sefScore, 2) . '%',
                    'status' => $status,
                    'action_url' => route('evaluation', [
                        'subject' => $facultyMeta['instructor'],
                        'term' => 'all',
                    ]),
                    'action_label' => 'View',
                    'set_breakdown' => $setBreakdown,
                ];
            })
            ->values()
            ->all();

        $facultyReportProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('profile.edit'),
            'accountSettingsUrl' => route('account.settings.edit'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
                'profile_photo_url' => $profilePhotoUrl,
            ],
            'facultyName' => $facultyMeta['instructor'],
            'tableRows' => $tableRows,
            'hasPendingEvaluations' => SupervisorEvaluationSubmission::query()
                ->where('user_id', $currentUser->id)
                ->distinct('instructor')
                ->count('instructor') < count($facultyEvaluations),
            'canAccessEvaluation' => $canAccessEvaluation,
        ];

        return view('faculty-report', [
            'facultyReportProps' => $facultyReportProps,
        ]);
    })->name('reports.faculty');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::post('/evaluations', [FacultyEvaluationController::class, 'store'])->name('evaluations.store');
    Route::post('/unit-head-grades', [UnitHeadGradeController::class, 'store'])->name('unit-head-grades.store');
});
