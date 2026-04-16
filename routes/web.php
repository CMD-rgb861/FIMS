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

    Route::get('/dashboard', function () use ($facultyEvaluations) {
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

        return view('dashboard', [
            'schoolYears' => $schoolYears,
            'terms' => $terms,
            'subjects' => $subjects,
            'evaluations' => $evaluations,
            'evaluatedInstructors' => $evaluatedInstructors,
        ]);
    })->name('dashboard');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::post('/evaluations', [FacultyEvaluationController::class, 'store'])->name('evaluations.store');

    Route::get('/evaluation', function () use ($facultyEvaluations) {
        $instructor = request('instructor', 'Unknown Instructor');
        $currentUser = request()->user();

        $selectedFaculty = collect($facultyEvaluations)
            ->firstWhere('instructor', $instructor);

        $isEvaluated = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser?->id)
            ->where('instructor', $selectedFaculty['instructor'] ?? $instructor)
            ->exists();

        $latestEvaluation = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser?->id)
            ->where('instructor', $selectedFaculty['instructor'] ?? $instructor)
            ->latest('submitted_at')
            ->first();

        $latestRatings = $latestEvaluation?->ratings ?? [];
        $latestScores = collect($latestRatings)
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
        $latestTotalScore = collect($latestScores)->sum('score');
        $latestRatingPercentage = round(($latestTotalScore / 75) * 100, 2);

        $evaluationProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'evaluationUrl' => route('dashboard'),
            'profileUrl' => route('profile.edit'),
            'logoutUrl' => route('logout'),
            'evaluationStoreUrl' => route('evaluations.store'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
            ],
            'instructor' => $selectedFaculty['instructor'] ?? $instructor,
            'initials' => $selectedFaculty['initials'] ?? 'NA',
            'subjects' => $selectedFaculty['subjects'] ?? [],
            'isEvaluated' => $isEvaluated,
            'evaluationResult' => $latestEvaluation ? [
                'instructor' => $latestEvaluation->instructor,
                'course_code' => $latestEvaluation->course_code,
                'course_title' => $latestEvaluation->course_title,
                'term' => $latestEvaluation->term,
                'scores' => $latestScores,
                'total_score' => $latestTotalScore,
                'rating_percentage' => $latestRatingPercentage,
            ] : null,
        ];

        return view('evaluation', [
            'evaluationProps' => $evaluationProps,
        ]);
    })->name('evaluation.page');
});
