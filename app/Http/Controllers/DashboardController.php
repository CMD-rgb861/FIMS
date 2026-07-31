<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use App\Models\FacultyDevelopmentForm;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\UnitHeadGrade;
use App\Models\User;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    use FacultyData;

    public function index(Request $request)
    {
        $currentUser = $request->user();
        $canAccessEvaluation = $this->canAccessEvaluationForUser($currentUser);
        
        // Get available terms from the school_years table
        $availableTerms = DB::connection('lnu_poes')
            ->table('school_years')
            ->select(
                'id as value',
                DB::raw("
                    CONCAT(
                        school_year_from,
                        '-',
                        school_year_to,
                        ' - ',
                        CASE
                            WHEN semester = 1 THEN '1st Semester'
                            WHEN semester = 2 THEN '2nd Semester'
                            WHEN semester = 3 THEN 'Summer'
                            ELSE CONCAT('Semester ', semester)
                        END
                    ) as label
                ")
            )
            ->where(function($query) {
                $query->where('school_year_from', '>', 2025)
                    ->orWhere(function($q) {
                        $q->where('school_year_from', '=', 2025)
                            ->where('school_year_to', '=', 2026)
                            ->where('semester', '>=', 2);
                    });
            })
            ->orderByDesc('school_year_from')
            ->orderByDesc('semester')
            ->get()
            ->toArray();
        
        // Handle term parameter - expecting ID
        $termParam = $request->query('term', null);
        $selectedTermId = null;

        // If a specific term is requested
        if ($termParam && $termParam !== 'all' && $termParam !== '') {
            $selectedTermId = is_numeric($termParam) ? (int) $termParam : null;
        } 
        // If no term is selected, default to the latest term
        elseif (empty($termParam) || $termParam === '') {
            $latestTerm = DB::connection('lnu_poes')
                ->table('school_years')
                ->orderByDesc('school_year_from')
                ->orderByDesc('semester')
                ->first();
            
            if ($latestTerm) {
                $selectedTermId = $latestTerm->id;
            }
        }
        
        // Get school year metadata for context
        $schoolYearMetaById = $this->getSchoolYearMetaById();
        
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

        $submissionBaseQuery = SupervisorEvaluationSubmission::query()->with('instructorUser');
        $gradeBaseQuery = UnitHeadGrade::query();

        // Apply term filter if selected
        if ($selectedTermId !== null) {
            $submissionBaseQuery->where('term_id', $selectedTermId);
            $gradeBaseQuery->where('term_id', $selectedTermId);
        }

        if ($canAccessEvaluation) {
            $submissionBaseQuery->where('user_id', $currentUser->id);
            $gradeBaseQuery->where('user_id', $currentUser->id);
        } else {
            if (!empty($currentUser?->id_no)) {
                $submissionBaseQuery->where('instructor_id_no', $currentUser->id_no);
            } else {
                $submissionBaseQuery->where('id', 0);
            }

            if (!empty($nameCandidates)) {
                $gradeBaseQuery->where(function ($query) use ($nameCandidates) {
                    foreach ($nameCandidates as $name) {
                        $query->orWhere('instructor', $name);
                    }
                });
            } else {
                $gradeBaseQuery->where('id', 0);
            }
        }

        // Get total instructors with term filter
        $facultyEvaluations = $this->getFacultyEvaluations($selectedTermId);
        $totalInstructors = count($facultyEvaluations);

        // Get evaluated instructors by the CURRENT USER for this term (matching EvaluationController logic)
        $evaluatedSubmissions = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->where('term_id', $selectedTermId)
            ->select(['id', 'user_id', 'instructor_id_no', 'term_id'])
            ->get();

        $evaluatedInstructorsGlobal = $evaluatedSubmissions
            ->pluck('instructor_id_no')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // ============================================
        // BUILD FACULTY EVALUATION LIST & COUNTS FOR EVALUATORS
        // ============================================
        $facultyEvaluationList = [];
        $totalFaculty = 0;
        $evaluatedCount = 0;
        $pendingCount = 0;
        $completionRate = 0;

        // Cache for batch ratings
        $batchSetRatings = collect();
        $batchSefRatings = collect();
        $facultyIdNosForBatch = [];

        if ($canAccessEvaluation && ($currentUser->isUnitHead() || $currentUser->isAssociateDean())) {
            // Get faculty members eligible for evaluation based on role (matching EvaluationController)
            $facultyUsers = collect();
            $facultyIdNos = [];
            
            if ($currentUser->isUnitHead()) {
                // Unit Head: Get faculty members under their unit
                $unitHead = $currentUser->unitHead;
                if ($unitHead && $unitHead->unit_id) {
                    $facultyUsers = User::query()
                        ->whereNotNull('id_no')
                        ->where('id_no', '!=', '')
                        ->where('unit_id', $unitHead->unit_id)
                        ->where('id', '!=', $currentUser->id)
                        ->with(['college', 'unit'])
                        ->orderBy('lastname')
                        ->orderBy('firstname')
                        ->get();
                }
            } elseif ($currentUser->isAssociateDean()) {
                // Associate Dean: Get Unit Heads under their college
                $associateDean = $currentUser->associateDean;
                if ($associateDean && $associateDean->college_id) {
                    $collegeId = $associateDean->college_id;
                    $facultyUsers = User::query()
                        ->whereNotNull('id_no')
                        ->where('id_no', '!=', '')
                        ->whereHas('unitHead')
                        ->where('college_id', $collegeId)
                        ->where('id', '!=', $currentUser->id)
                        ->with(['college', 'unit'])
                        ->orderBy('lastname')
                        ->orderBy('firstname')
                        ->get();
                }
            }
            
            if ($facultyUsers->isNotEmpty()) {
                // Get faculty ID numbers
                $facultyIdNos = $facultyUsers->pluck('id_no')->filter()->values()->all();
                
                // ============================================
                // IMPORTANT: Check if faculty has courses in the selected term (matching EvaluationController)
                // ============================================
                $facultyWithCourses = [];
                if (!empty($facultyIdNos) && $selectedTermId !== null) {
                    // Get faculty who have courses in the selected term
                    $courses = DB::connection('lnu_poes')
                        ->table('enrollment_courses')
                        ->where('school_year_id', $selectedTermId)
                        ->whereIn('id_no', $facultyIdNos)
                        ->select('id_no')
                        ->distinct()
                        ->get();
                    
                    $facultyWithCourses = $courses->pluck('id_no')->filter()->values()->all();
                }
                
                // Filter faculty users to only those with courses in the selected term
                $filteredFacultyUsers = $facultyUsers->filter(function ($faculty) use ($facultyWithCourses) {
                    return in_array($faculty->id_no, $facultyWithCourses);
                });
                
                // Update counts based on filtered faculty
                $totalFaculty = $filteredFacultyUsers->count();
                
                // Get evaluated instructors by the CURRENT USER for the filtered faculty
                $evaluatedIdNos = [];
                if (!empty($facultyWithCourses)) {
                    $evaluatedIdNos = SupervisorEvaluationSubmission::query()
                        ->where('user_id', $currentUser->id)
                        ->where('term_id', $selectedTermId)
                        ->whereIn('instructor_id_no', $facultyWithCourses)
                        ->pluck('instructor_id_no')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();
                }
                
                $evaluatedCount = count($evaluatedIdNos);
                $pendingCount = max($totalFaculty - $evaluatedCount, 0);
                $completionRate = $totalFaculty > 0
                    ? round(($evaluatedCount / $totalFaculty) * 100, 2)
                    : 0;
                
                // STORE FACULTY ID NOS FOR BATCH RATING FETCHING
                $facultyIdNosForBatch = $filteredFacultyUsers->pluck('id_no')->filter()->values()->all();
                
                // BATCH LOAD SET RATINGS FOR ALL FACULTY IN ONE QUERY
                if (!empty($facultyIdNosForBatch)) {
                    $batchSetRatings = $this->getBatchFacultySetRatings($facultyIdNosForBatch, $selectedTermId);
                    $batchSefRatings = $this->getBatchFacultySefRatings($facultyIdNosForBatch, $selectedTermId);
                }
                
                // Build the faculty evaluation list using filtered faculty
                foreach ($filteredFacultyUsers as $faculty) {
                    $displayName = $this->buildDisplayName($faculty);
                    $initials = $this->buildInitials($displayName, $faculty->id_no);
                    
                    // For Associate Dean, show the unit/department name
                    $department = $faculty->unit?->name ?? 'No department';
                    if ($currentUser->isAssociateDean()) {
                        $department = $faculty->unit?->name ?? 'No unit assigned';
                    }
                    
                    // Get ratings from batch cache
                    $setRating = $batchSetRatings->get($faculty->id_no);
                    $sefRating = $batchSefRatings->get($faculty->id_no);
                    
                    $facultyEvaluationList[] = [
                        'id' => $faculty->id,
                        'id_no' => $faculty->id_no,
                        'name' => $displayName,
                        'initials' => $initials,
                        'department' => $department,
                        'role' => $currentUser->isAssociateDean() ? 'Unit Head' : 'Faculty',
                        'status' => in_array($faculty->id_no, $evaluatedIdNos) ? 'Completed' : 'Pending',
                        'evaluated' => in_array($faculty->id_no, $evaluatedIdNos),
                        'set_rating' => $setRating,
                        'sef_rating' => $sefRating,
                    ];
                }
            }
        } else {
            // For non-evaluators, use the global counts based on current user's submissions
            $totalFaculty = $totalInstructors;
            $evaluatedCount = count($evaluatedInstructorsGlobal);
            $pendingCount = max($totalFaculty - $evaluatedCount, 0);
            $completionRate = $totalFaculty > 0
                ? round(($evaluatedCount / $totalFaculty) * 100, 2)
                : 0;
        }

        $recentEvaluations = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->where('term_id', $selectedTermId)
            ->with('instructorUser')
            ->latest('submitted_at')
            ->take(5)
            ->get()
            ->map(function ($submission) {
                $ratings = $submission->ratings ?? [];
                $totalScore = collect($ratings)->sum(function ($score) {
                    return (int) $score;
                });

                $instructorName = trim(collect([
                    trim((string) ($submission->instructorUser?->firstname ?? '')),
                    trim((string) ($submission->instructorUser?->middlename ?? '')),
                    trim((string) ($submission->instructorUser?->lastname ?? '')),
                    trim((string) ($submission->instructorUser?->extname ?? '')),
                ])->filter()->implode(' '));

                return [
                    'instructor' => $instructorName !== ''
                        ? $instructorName
                        : (string) ($submission->instructor_id_no ?? 'Unknown Instructor'),
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
            : 'Unit head evaluation rating will appear once submitted.';

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

        // OPTIMIZED: Get dean dashboard data with batch loading
        $deanDashboardData = $this->getDeanDashboardDataOptimized($currentUser, $selectedTermId);
        $deanFacultyRankings = $deanDashboardData['rankings'];
        $deanCompletedFaculty = $deanDashboardData['completed'];
        $deanSetRankings = $deanDashboardData['setRankings'];
        $deanSefRankings = $deanDashboardData['sefRankings'];

        // ============================================
        // CALCULATE OVERALL SET, SEF RATINGS & SUBJECTS HANDLED 
        // ============================================
        $averageSetRating = 'N/A';
        $averageSefRating = 'N/A';
        $subjectsHandled = 0;
        $facultyGrade = 'N/A';
        $mySubjects = [];
        $recentGrades = [];

        // Get the user's ID number and name (works for both faculty and unit heads)
        $idNo = $currentUser?->id_no;
        $fullName = trim(collect([$firstName, $lastName])->filter()->implode(' '));

        // ALWAYS calculate SET and SEF ratings for the logged-in user (they are a faculty member too)
        // Use cached batch results if available, otherwise compute individually
        if (!empty($idNo)) {
            // Check if we already have batch data for this user
            if ($batchSetRatings->has($idNo)) {
                $averageSetRating = $batchSetRatings->get($idNo);
            } else {
                $averageSetRating = $this->getFacultyOverallSetRating($idNo, $fullName, $selectedTermId);
            }
            
            if ($batchSefRatings->has($idNo)) {
                $averageSefRating = $batchSefRatings->get($idNo);
            } else {
                $averageSefRating = $this->getFacultyReceivedSefRating($idNo, $selectedTermId);
            }
        }

        // Get subjects handled (works for both faculty and unit heads)
        $mySubjects = $this->getFacultySubjects($idNo, $fullName, $selectedTermId);
        $subjectsHandled = count($mySubjects);

        if (!$canAccessEvaluation) {
            // For faculty users (non-evaluators)
            if (!empty($unitHeadGrades)) {
                $facultyGrade = isset($latestUnitHeadGrade['grade']) 
                    ? number_format((float) $latestUnitHeadGrade['grade'], 2) 
                    : 'N/A';
                $recentGrades = collect($unitHeadGrades)
                    ->sortByDesc('submitted_at')
                    ->take(5)
                    ->values()
                    ->all();
            }
        } else {
            // For evaluators (Unit Heads, Deans, etc.)
            $facultyGrade = isset($latestUnitHeadGrade['grade']) 
                ? number_format((float) $latestUnitHeadGrade['grade'], 2) 
                : 'N/A';
            $recentGrades = collect($unitHeadGrades)
                ->sortByDesc('submitted_at')
                ->take(5)
                ->values()
                ->all();
        }

        // Get term label for display
        $selectedTermLabel = null;
        if ($selectedTermId !== null) {
            $termMeta = $schoolYearMetaById[$selectedTermId] ?? null;
            if ($termMeta) {
                $semesterText = $this->getSemesterText($termMeta['semester']);
                $selectedTermLabel = "S.Y. {$termMeta['year_from']}-{$termMeta['year_to']} - {$semesterText}";
            }
        }

        // ============================================
        // GET STUDENT EVALUATION SECTIONS FOR THE USER
        // ============================================
        $evaluationSections = [];

        // Only get evaluation sections for non-admin users (faculty, unit heads, associate deans)
        // Skip for Deans and Admins
        if (!$currentUser->isDean() && !$currentUser->isAdmin()) {
            // Get the user's ID number
            $userIdNo = $currentUser->id_no;
            
            if (!empty($userIdNo) && $selectedTermId !== null) {
                try {
                    // Get unique sections/courses where this instructor has student evaluations
                    $evaluationSections = DB::connection('lnu_poes')
                        ->table('enrollment_courses as ec')
                        ->join('student_evaluation_submissions as ses', 'ec.id', '=', 'ses.subject_id')
                        ->select(
                            'ec.course_code',
                            'ec.course_description',
                            'ec.section_code',
                            DB::raw('COUNT(DISTINCT ses.student_id_number) as evaluation_count'),
                            DB::raw('AVG(ses.rating_percentage) as avg_rating')
                        )
                        ->where('ec.id_no', $userIdNo)
                        ->where('ec.school_year_id', $selectedTermId)
                        ->groupBy('ec.course_code', 'ec.course_description', 'ec.section_code')
                        ->orderBy('ec.course_code')
                        ->get()
                        ->map(function ($row) {
                            // Extract year from section code
                            $year = 'N/A';
                            if (!empty($row->section_code)) {
                                preg_match('/\d/', $row->section_code, $matches);
                                if (!empty($matches)) {
                                    $year = $matches[0];
                                }
                            }
                            
                            return [
                                'year' => $year,
                                'section_name' => $row->section_code ?? 'N/A',
                                'course_code' => $row->course_code ?? 'N/A',
                                'course_description' => $row->course_description ?? 'N/A',
                                'evaluation_count' => (int) $row->evaluation_count,
                                'avg_rating' => $row->avg_rating ? round((float) $row->avg_rating, 2) : null,
                            ];
                        })
                        ->values()
                        ->all();
                } catch (\Exception $e) {
                    Log::error('Error getting evaluation sections: ' . $e->getMessage());
                    $evaluationSections = [];
                }
            }
        }

        // ============================================
        // CALCULATE STUDENT EVALUATIONS RECEIVED
        // ============================================
        $studentEvaluationsReceived = 0;

        // Count student evaluations where the current user is the instructor
        // This applies to Faculty, Unit Heads, Associate Deans, and Deans (if they teach)
        if (!$currentUser->isAdmin()) {
            $userIdNo = $currentUser->id_no;
            
            if (!empty($userIdNo) && $selectedTermId !== null) {
                try {
                    // Count distinct student evaluation submissions where this user is the instructor
                    $studentEvaluationsReceived = DB::connection('lnu_poes')
                        ->table('student_evaluation_submissions as ses')
                        ->join('enrollment_courses as ec', 'ses.subject_id', '=', 'ec.id')
                        ->where('ec.id_no', $userIdNo)
                        ->when($selectedTermId, function ($q) use ($selectedTermId) {
                            $q->where('ses.term_id', $selectedTermId);
                        })
                        ->count();
                } catch (\Exception $e) {
                    Log::error('Error counting student evaluations: ' . $e->getMessage());
                    $studentEvaluationsReceived = 0;
                }
            }
        }

        // Determine what to show for evaluationsReceived based on user role
        $evaluationsReceivedForDisplay = 0;

        if ($currentUser->isDean()) {
            // Dean: Show BOTH their own student evaluations (if they teach) AND total college evaluations
            $collegeId = $currentUser->dean?->college_id ?? null;
            if ($collegeId) {
                $facultyIdNos = User::query()
                    ->whereNotNull('id_no')
                    ->where('id_no', '!=', '')
                    ->where('college_id', $collegeId)
                    ->where('id', '!=', $currentUser->id)
                    ->pluck('id_no')
                    ->filter()
                    ->values()
                    ->all();
                
                if (!empty($facultyIdNos)) {
                    $collegeEvaluations = DB::connection('lnu_poes')
                        ->table('student_evaluation_submissions as ses')
                        ->join('enrollment_courses as ec', 'ses.subject_id', '=', 'ec.id')
                        ->whereIn('ec.id_no', $facultyIdNos)
                        ->when($selectedTermId, function ($q) use ($selectedTermId) {
                            $q->where('ses.term_id', $selectedTermId);
                        })
                        ->count();
                    
                    // Add the Dean's own student evaluations (if any) to the college total
                    $evaluationsReceivedForDisplay = $collegeEvaluations + $studentEvaluationsReceived;
                } else {
                    // If no faculty found, just show the Dean's own evaluations
                    $evaluationsReceivedForDisplay = $studentEvaluationsReceived;
                }
            } else {
                // If no college ID, just show the Dean's own evaluations
                $evaluationsReceivedForDisplay = $studentEvaluationsReceived;
            }
        } elseif ($canAccessEvaluation && ($currentUser->isUnitHead() || $currentUser->isAssociateDean())) {
            // Evaluator (Unit Head / Associate Dean): Show how many faculty/unit heads they evaluated
            $evaluationsReceivedForDisplay = $evaluatedCount;
        } else {
            // Faculty: Show student evaluations they received
            $evaluationsReceivedForDisplay = $studentEvaluationsReceived;
        }

        $dashboardProps = $this->commonInertiaProps($currentUser, [
            'summaryCards' => [
                [
                    'label' => 'Total Instructors',
                    'value' => $totalFaculty,
                    'helper' => 'Faculty members in your unit.',
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
            // Dashboard specific props
            'averageSetRating' => $averageSetRating !== null ? number_format($averageSetRating, 2) . '%' : 'N/A',
            'averageSefRating' => $averageSefRating !== null ? number_format($averageSefRating, 2) . '%' : 'N/A',
            'evaluationsReceived' => $evaluationsReceivedForDisplay, // FIXED: Proper count for Deans
            'subjectsHandled' => $subjectsHandled,
            'facultyGrade' => $facultyGrade,
            'mySubjects' => $mySubjects,
            'recentGrades' => $recentGrades,
            'schoolYear' => $this->getCurrentSchoolYear(),
            'hasPendingEvaluations' => false,
            'evaluationSections' => $evaluationSections,
            // Term filter data
            'availableTerms' => $availableTerms,
            'selectedTerm' => $selectedTermId,
            'selectedTermLabel' => $selectedTermLabel,
            // Unit Head specific data
            'totalFaculty' => $totalFaculty,
            'evaluatedCount' => $evaluatedCount,
            'pendingCount' => $pendingCount,
            'facultyEvaluationList' => $facultyEvaluationList,
            // Dean dashboard data
            'deanFacultyRankings' => $deanFacultyRankings,
            'deanCompletedFaculty' => $deanCompletedFaculty,
            'deanSetRankings' => $deanSetRankings,
            'deanSefRankings' => $deanSefRankings,
        ]);

        return Inertia::render('DashboardPage', $dashboardProps);
    }

    // ... (keep all existing helper methods)

    /**
     * Build display name from user object
     */
    private function buildDisplayName($user): string
    {
        $firstName = trim($user->firstname ?? '');
        $lastName = trim($user->lastname ?? '');
        $extName = trim($user->extname ?? '');

        $displayName = trim($firstName . ' ' . $lastName);
        if (!empty($extName)) {
            $displayName .= ' ' . $extName;
        }
        return $displayName ?: $user->id_no ?? 'Unknown';
    }

    /**
     * Build initials from display name or ID
     */
    private function buildInitials(string $displayName, string $idNo): string
    {
        $initials = '';
        $nameWords = preg_split('/\s+/', trim($displayName));
        foreach ($nameWords as $word) {
            if ($word !== '' && !preg_match('/^(Jr|Sr|III|IV|II)$/i', $word)) {
                $initials .= strtoupper(mb_substr($word, 0, 1));
                if (mb_strlen($initials) >= 2) break;
            }
        }
        return $initials ?: strtoupper(mb_substr($idNo, 0, 2));
    }

    /**
     * Get current school year
     */
    private function getCurrentSchoolYear()
    {
        $year = date('Y');
        $month = date('m');
        if ($month >= 6) {
            return $year . '-' . ($year + 1);
        }
        return ($year - 1) . '-' . $year;
    }

    /**
     * Get school year metadata by ID
     */
    private function getSchoolYearMetaById(): array
    {
        $schoolYears = DB::connection('lnu_poes')
            ->table('school_years')
            ->get();
        
        $metaById = [];
        
        foreach ($schoolYears as $sy) {
            $metaById[$sy->id] = [
                'year_from' => $sy->school_year_from,
                'year_to' => $sy->school_year_to,
                'semester' => $sy->semester,
            ];
        }
        
        return $metaById;
    }
    
    /**
     * Convert semester number to text
     */
    private function getSemesterText($semester)
    {
        switch ($semester) {
            case 1: return '1st Semester';
            case 2: return '2nd Semester';
            case 3: return 'Summer';
            default: return $semester ? "Semester {$semester}" : null;
        }
    }

    /**
     * OPTIMIZED: Build dean dashboard ranking and completion data with batch loading
     */
    private function getDeanDashboardDataOptimized($currentUser, ?int $selectedTermId = null): array
    {
        if (!$currentUser?->isDean()) {
            return [
                'rankings' => [],
                'completed' => [],
                'setRankings' => [],
                'sefRankings' => [],
            ];
        }

        $collegeId = $currentUser->dean?->college_id ?? null;
        if (!$collegeId) {
            return [
                'rankings' => [],
                'completed' => [],
                'setRankings' => [],
                'sefRankings' => [],
            ];
        }

        // Get ALL faculty users under this college
        $facultyUsers = User::query()
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->where('college_id', $collegeId)
            ->where('id', '!=', $currentUser->id)
            ->with(['college', 'unit'])
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->get();

        if ($facultyUsers->isEmpty()) {
            return [
                'rankings' => [],
                'completed' => [],
                'setRankings' => [],
                'sefRankings' => [],
            ];
        }

        // Get all faculty ID numbers for batch loading
        $facultyIdNos = $facultyUsers->pluck('id_no')->filter()->values()->all();
        
        // BATCH LOAD ALL RATINGS IN TWO QUERIES (not one per faculty)
        $batchSetRatings = collect();
        $batchSefRatings = collect();
        
        if (!empty($facultyIdNos)) {
            $batchSetRatings = $this->getBatchFacultySetRatings($facultyIdNos, $selectedTermId);
            $batchSefRatings = $this->getBatchFacultySefRatings($facultyIdNos, $selectedTermId);
        }

        $allRankings = [];
        $completedFaculty = [];

        foreach ($facultyUsers as $faculty) {
            $idNo = $faculty->id_no;
            $displayName = $this->buildDisplayName($faculty);
            
            // Get ratings from batch cache (NO DATABASE QUERIES PER FACULTY)
            $setRating = $batchSetRatings->get($idNo);
            $sefRating = $batchSefRatings->get($idNo);
            
            // Check completion status
            $setCompleted = $setRating !== null;
            $sefCompleted = $sefRating !== null;
            
            // IFE - Check if both SET and SEF are completed as a proxy
            $ifeCompleted = $setCompleted && $sefCompleted;
            
            // FEDA - Check if FEDA form is submitted
            $fedaCompleted = FacultyDevelopmentForm::hasSubmittedFormFor($idNo, $selectedTermId);

            // Add to rankings if they have at least one rating
            $allRankings[] = [
                'id_no' => $idNo,
                'name' => $displayName,
                'department' => $faculty->unit?->name ?? 'No department',
                'set_rating' => $setRating !== null ? round((float) $setRating, 2) : null,
                'sef_rating' => $sefRating !== null ? round((float) $sefRating, 2) : null,
            ];

            // Add to completed faculty list
            $completedFaculty[] = [
                'id_no' => $idNo,
                'name' => $displayName,
                'department' => $faculty->unit?->name ?? 'No department',
                'set' => $setCompleted,
                'sef' => $sefCompleted,
                'ife' => $ifeCompleted,
                'feda' => $fedaCompleted,
            ];
        }

        // Separate rankings for SET and SEF
        $setRankings = collect($allRankings)
            ->filter(function ($item) {
                return $item['set_rating'] !== null;
            })
            ->sortByDesc(function ($item) {
                return $item['set_rating'];
            })
            ->take(5)
            ->values()
            ->all();

        $sefRankings = collect($allRankings)
            ->filter(function ($item) {
                return $item['sef_rating'] !== null;
            })
            ->sortByDesc(function ($item) {
                return $item['sef_rating'];
            })
            ->take(5)
            ->values()
            ->all();

        $rankings = $setRankings;

        $completedFaculty = collect($completedFaculty)
            ->sortByDesc(function ($item) {
                $score = 0;
                foreach (['set', 'sef', 'ife', 'feda'] as $flag) {
                    if ($item[$flag]) {
                        $score++;
                    }
                }
                return $score;
            })
            ->values()
            ->all();

        return [
            'rankings' => $rankings,
            'completed' => $completedFaculty,
            'setRankings' => $setRankings,
            'sefRankings' => $sefRankings,
        ];
    }

    /**
     * Get overall SET ratings for multiple faculty in ONE optimized query
     * Copy this from ReportsController
     */
    private function getBatchFacultySetRatings(array $facultyIdNos, ?int $termId = null): \Illuminate\Support\Collection
    {
        if (empty($facultyIdNos)) {
            return collect();
        }

        try {
            $subjects = DB::connection('lnu_poes')
                ->table('enrollment_courses as ec')
                ->join('student_evaluation_submissions as ses', 'ec.id', '=', 'ses.subject_id')
                ->select(
                    'ec.id_no',
                    'ec.course_code',
                    'ec.section_code',
                    DB::raw('COUNT(DISTINCT ses.student_id_number) as student_count'),
                    DB::raw('AVG(ses.rating_percentage) as avg_rating')
                )
                ->whereIn('ec.id_no', $facultyIdNos)
                ->whereNotNull('ses.rating_percentage')
                ->when($termId && $termId !== '' && $termId !== 'all', function ($q) use ($termId) {
                    $q->where('ec.school_year_id', $termId)
                      ->where('ses.term_id', $termId);
                })
                ->groupBy('ec.id_no', 'ec.course_code', 'ec.section_code')
                ->get();
            
            $facultyTotals = [];
            foreach ($subjects as $subject) {
                $idNo = $subject->id_no;
                
                if (!isset($facultyTotals[$idNo])) {
                    $facultyTotals[$idNo] = ['total_weighted' => 0, 'total_students' => 0];
                }
                
                $facultyTotals[$idNo]['total_weighted'] += $subject->student_count * $subject->avg_rating;
                $facultyTotals[$idNo]['total_students'] += $subject->student_count;
            }
            
            $results = [];
            foreach ($facultyTotals as $idNo => $totals) {
                if ($totals['total_students'] > 0) {
                    $results[$idNo] = round($totals['total_weighted'] / $totals['total_students'], 2);
                } else {
                    $results[$idNo] = null;
                }
            }
            
            return collect($results);
            
        } catch (\Exception $e) {
            Log::error('Error batch loading SET ratings: ' . $e->getMessage());
            return collect();
        }
    }

    /**
     * Get SEF ratings for multiple faculty in ONE optimized query
     */
    private function getBatchFacultySefRatings(array $facultyIdNos, ?int $termId = null): \Illuminate\Support\Collection
    {
        if (empty($facultyIdNos)) {
            return collect();
        }

        try {
            $query = SupervisorEvaluationSubmission::query()
                ->whereIn('instructor_id_no', $facultyIdNos)
                ->select('instructor_id_no')
                ->selectRaw('AVG(rating_percentage) as avg_rating');

            if ($termId && $termId !== '' && $termId !== 'all') {
                $query->where('term_id', $termId);
            }

            $rows = $query->groupBy('instructor_id_no')->get();
            return $rows->pluck('avg_rating', 'instructor_id_no');
            
        } catch (\Exception $e) {
            Log::error('Error batch loading SEF ratings: ' . $e->getMessage());
            return collect();
        }
    }

    /**
     * Get faculty evaluations with optional term filtering
     */
    private function getFacultyEvaluations($termId = null)
    {
        $query = DB::connection('lnu_poes')
            ->table('enrollment_courses')
            ->select('id_no', 'instructor')
            ->distinct();
        
        // Apply term filter if provided
        if ($termId !== null) {
            $query->where('school_year_id', $termId);
        }
        
        return $query->get();
    }

    /**
     * Get average SEF rating that a faculty member received (as an instructor)
     */
    private function getFacultyReceivedSefRating(?string $instructorIdNo, ?int $termId = null): ?float
    {
        if (empty($instructorIdNo)) {
            return null;
        }

        $query = SupervisorEvaluationSubmission::query()
            ->where('instructor_id_no', $instructorIdNo);
        
        if ($termId !== null) {
            $query->where('term_id', $termId);
        }
        
        $avg = $query->avg('rating_percentage');
            
        return $avg !== null ? round((float) $avg, 2) : null;
    }

    /**
     * Get overall SET rating for a faculty member
     */
    private function getFacultyOverallSetRating(?string $idNo, ?string $instructor = null, ?int $termId = null): ?float
    {
        $normalizedIdNo = trim((string) ($idNo ?? ''));
        $normalizedInstructor = trim((string) ($instructor ?? ''));

        if ($normalizedIdNo === '' && $normalizedInstructor === '') {
            return null;
        }

        try {
            $query = DB::connection('lnu_poes')
                ->table('enrollment_courses as ec')
                ->join('student_evaluation_submissions as ses', 'ec.id', '=', 'ses.subject_id')
                ->select('ec.course_code', 'ec.section_code')
                ->selectRaw('COUNT(DISTINCT ses.student_id_number) as student_count')
                ->selectRaw('AVG(ses.rating_percentage) as avg_rating')
                ->whereNotNull('ses.rating_percentage')
                ->groupBy('ec.course_code', 'ec.section_code');

            if ($termId !== null) {
                $query->where('ec.school_year_id', $termId);
            }

            if ($normalizedIdNo !== '') {
                $query->where('ec.id_no', $normalizedIdNo);
            } elseif ($normalizedInstructor !== '') {
                $tokens = preg_split('/[^\pL\pN]+/u', mb_strtoupper($normalizedInstructor)) ?: [];
                $tokens = array_values(array_filter($tokens, fn($token) => mb_strlen($token) > 1));

                foreach ($tokens as $token) {
                    $query->where('ec.instructor', 'like', '%' . $token . '%');
                }
            }

            $subjects = $query->get();

            if ($subjects->isEmpty()) {
                return null;
            }

            $totalWeightedScore = 0;
            $totalStudents = 0;

            foreach ($subjects as $subject) {
                $studentCount = (int) $subject->student_count;
                $avgRating = (float) $subject->avg_rating;
                
                $totalWeightedScore += $studentCount * $avgRating;
                $totalStudents += $studentCount;
            }

            if ($totalStudents === 0) {
                return null;
            }

            return round($totalWeightedScore / $totalStudents, 2);
            
        } catch (\Exception $e) {
            Log::error('Error getting faculty overall SET rating: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get faculty subjects with course details
     */
    private function getFacultySubjects(?string $idNo, ?string $instructor = null, ?int $termId = null): array
    {
        $normalizedIdNo = trim((string) ($idNo ?? ''));
        $normalizedInstructor = trim((string) ($instructor ?? ''));

        if ($normalizedIdNo === '' && $normalizedInstructor === '') {
            return [];
        }

        try {
            $query = DB::connection('lnu_poes')
                ->table('enrollment_courses')
                ->select(
                    'course_code',
                    'course_description as course_title',
                    'section_code',
                    'school_year_id'
                )
                ->distinct();

            if ($termId !== null) {
                $query->where('school_year_id', $termId);
            }

            if ($normalizedIdNo !== '') {
                $query->where('id_no', $normalizedIdNo);
            } elseif ($normalizedInstructor !== '') {
                $tokens = preg_split('/[^\pL\pN]+/u', mb_strtoupper($normalizedInstructor)) ?: [];
                $tokens = array_values(array_filter($tokens, fn($token) => mb_strlen($token) > 1));

                if (!empty($tokens)) {
                    $query->where(function ($q) use ($tokens) {
                        foreach ($tokens as $token) {
                            $q->orWhere('instructor', 'like', '%' . $token . '%');
                        }
                    });
                } else {
                    return [];
                }
            } else {
                return [];
            }

            $results = $query->get()->map(function ($subject) {
                return [
                    'course_code' => $subject->course_code,
                    'course_title' => $subject->course_title ?? $subject->course_code,
                    'section_code' => $subject->section_code ?? 'N/A',
                    'grade' => 'N/A',
                ];
            })->values()->all();

            return $results;
            
        } catch (\Exception $e) {
            Log::error('Error getting faculty subjects: ' . $e->getMessage());
            return [];
        }
    }
}