<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\FacultyEvaluationController;
use App\Http\Controllers\ProfileController;
use App\Models\SupervisorEvaluationSubmission;
use Illuminate\Support\Facades\Route;

$facultyEvaluations = [
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

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () use ($facultyEvaluations) {
    Route::get('/my-profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/my-profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/account-settings', [ProfileController::class, 'accountSettings'])->name('account.settings.edit');
    Route::put('/account-settings', [ProfileController::class, 'updateAccountSettings'])->name('account.settings.update');

    Route::get('/dashboard', function () use ($facultyEvaluations) {
        $currentUser = request()->user();

        $totalInstructors = count($facultyEvaluations);
        $evaluatedInstructors = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->pluck('instructor')
            ->unique()
            ->values()
            ->all();

        $evaluatedCount = count($evaluatedInstructors);
        $pendingCount = max($totalInstructors - $evaluatedCount, 0);
        $completionRate = $totalInstructors > 0
            ? round(($evaluatedCount / $totalInstructors) * 100, 2)
            : 0;

        $recentEvaluations = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
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

        $dashboardProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'evaluationUrl' => route('evaluation'),
            'profileUrl' => route('profile.edit'),
            'accountSettingsUrl' => route('account.settings.edit'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
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
            'recentEvaluations' => $recentEvaluations,
            'hasPendingEvaluations' => $pendingCount > 0,
        ];

        return view('dashboard', [
            'dashboardProps' => $dashboardProps,
        ]);
    })->name('dashboard');

    Route::get('/evaluation', function () use ($facultyEvaluations) {
        $currentUser = request()->user();

        $schoolYears = [
            ['label' => 'S.Y. 2025-2026 - 2', 'value' => '2025-2026-2'],
            ['label' => 'S.Y. 2025-2026 - 1', 'value' => '2025-2026-1'],
        ];

        $terms = [
            ['label' => 'All', 'value' => 'all'],
            ['label' => 'For Evaluation', 'value' => 'for-evaluation'],
            ['label' => 'Evaluated', 'value' => 'evaluated'],
        ];

        $selectedTerm = request('term', 'all');
        $selectedSubject = request('subject', '');

        $subjects = [
            ['label' => 'Select a name to evaluate', 'value' => ''],
            ['label' => 'Raphy Dalan', 'value' => 'Raphy Dalan'],
            ['label' => 'Mark Lester Laurente', 'value' => 'Mark Lester Laurente'],
            ['label' => 'Rico Combinido', 'value' => 'Rico Combinido'],
            ['label' => 'Louvesa Idda Galban', 'value' => 'Louvesa Idda Galban'],
        ];

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
            'evaluationUrl' => route('evaluation'),
            'profileUrl' => route('profile.edit'),
            'accountSettingsUrl' => route('account.settings.edit'),
            'evaluationStoreUrl' => route('evaluations.store'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
            ],
            'schoolYears' => $schoolYears,
            'terms' => $terms,
            'subjects' => $subjects,
            'evaluations' => $evaluations,
            'evaluatedInstructors' => $evaluatedInstructors,
            'selectedSchoolYear' => request('sy', $schoolYears[0]['value'] ?? ''),
            'selectedTerm' => $selectedTerm,
            'selectedSubject' => $selectedSubject,
            'hasPendingEvaluations' => count($evaluatedInstructors) < count($facultyEvaluations),
        ];

        return view('evaluation', [
            'evaluationProps' => $evaluationProps,
        ]);
    })->name('evaluation');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::post('/evaluations', [FacultyEvaluationController::class, 'store'])->name('evaluations.store');
});
