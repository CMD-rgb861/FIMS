<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\User;
use App\Models\FacultyDevelopmentForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FEDAController extends Controller
{
    use FacultyData;

    private const CACHE_TTL = 3600;

    public function index(Request $request)
    {
        $currentUser = $request->user();
        $canAccessEvaluation = $this->canAccessEvaluationForUser($currentUser);
        abort_if(! $canAccessEvaluation, 403);

        $schoolYears = $this->getSchoolYearsList();
        $selectedSchoolYearId = $this->resolveSelectedSchoolYearId($request);

        if ($selectedSchoolYearId === null) {
            return $this->renderEmptyState($currentUser, 'No active school year is configured.');
        }

        $selectedSchoolYear = $this->getSchoolYearById($selectedSchoolYearId);
        if (! $selectedSchoolYear) {
            return $this->renderEmptyState($currentUser, 'Selected school year not found.');
        }

        $facultyUsers = $this->getFacultyUsersForFEDA($currentUser, $selectedSchoolYearId, $selectedSchoolYear);
        $termLabel = $this->buildTermLabel($selectedSchoolYear);
        $instructors = $this->buildInstructorsPayload($facultyUsers, $selectedSchoolYearId, $termLabel);

        $props = $this->commonInertiaProps($currentUser, [
            'schoolYears' => $schoolYears,
            'instructors' => $instructors,
            'selectedTerm' => (string) $selectedSchoolYearId,
            'activeSchoolYear' => [
                'id' => $selectedSchoolYearId,
                'label' => $termLabel,
            ],
            'term_label' => $termLabel,
            'hasActiveTerm' => true,
            'totalInstructors' => count($instructors),
            'error' => null,
        ]);

        return redirect()->route('evaluation');
    }

    public function getInstructors(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        $canAccessEvaluation = $this->canAccessEvaluationForUser($currentUser);
        abort_if(! $canAccessEvaluation, 403);

        $selectedSchoolYearId = $this->resolveSelectedSchoolYearId($request);
        if ($selectedSchoolYearId === null) {
            return response()->json([
                'success' => false,
                'message' => 'No active school year is configured.',
                'instructors' => [],
            ]);
        }

        $selectedSchoolYear = $this->getSchoolYearById($selectedSchoolYearId);
        if (! $selectedSchoolYear) {
            return response()->json([
                'success' => false,
                'message' => 'Selected school year not found.',
                'instructors' => [],
            ]);
        }

        $facultyUsers = $this->getFacultyUsersForFEDA($currentUser, $selectedSchoolYearId, $selectedSchoolYear);
        $termLabel = $this->buildTermLabel($selectedSchoolYear);
        $instructors = $this->buildInstructorsPayload($facultyUsers, $selectedSchoolYearId, $termLabel);

        return response()->json([
            'success' => true,
            'instructors' => $instructors,
            'schoolYears' => $this->getSchoolYearsList(),
            'selectedSchoolYear' => (string) $selectedSchoolYearId,
            'activeSchoolYear' => [
                'id' => $selectedSchoolYearId,
                'label' => $termLabel,
            ],
            'term_label' => $termLabel,
        ]);
    }

    /**
     * Get FEDA data for a specific faculty (for modal display)
     * GET /feda/faculty/{facultyId}/data
     */
    public function getFacultyData(Request $request, $facultyId)
    {
        $termId = $request->query('term_id');
        
        if (!$termId) {
            return response()->json([
                'success' => false,
                'message' => 'Term ID is required',
                'has_data' => false,
            ]);
        }

        // Get the faculty user
        $faculty = User::where('id_no', $facultyId)->first();
        
        if (!$faculty) {
            return response()->json([
                'success' => false,
                'message' => 'Faculty not found',
                'has_data' => false,
            ]);
        }

        // Get SEF data (supervisor evaluations)
        $sefData = $this->getFacultySefData($facultyId, $termId);
        
        // Get SET data (student evaluations)
        $overallSetRating = $this->getFacultyOverallSetRating(
            $facultyId,
            trim(($faculty->firstname ?? '') . ' ' . ($faculty->lastname ?? '')),
            (int) $termId
        );

        // Get SEF rating from submissions
        $overallSefRating = $sefData['overall_sef_rating'] ?? null;
        $comments = $sefData['comments'] ?? '';
        $ratingsBreakdown = $sefData['ratings_breakdown'] ?? null;

        // Get existing development plan
        $developmentPlan = null;
        $fedaForm = FacultyDevelopmentForm::where('id_no', $facultyId)
            ->where('term_id', $termId)
            ->first();

        if ($fedaForm) {
            $developmentPlan = [
                'areas_for_improvement' => $fedaForm->areas_for_improvement ?? '',
                'proposed_activities' => $fedaForm->proposed_learning_and_development_activities ?? '',
                'action_plan' => $fedaForm->action_plan ?? '',
                'submitted_at' => $fedaForm->submitted_at,
                'submitted_by' => $fedaForm->submitted_by,
                'is_submitted' => $fedaForm->isSubmitted(),
            ];
        }

        return response()->json([
            'success' => true,
            'has_data' => true,
            'faculty_info' => [
                'name' => trim($faculty->firstname . ' ' . $faculty->lastname),
                'id_no' => $faculty->id_no,
                'college' => $faculty->college?->name ?? 'N/A',
                'program' => $faculty->unit?->name ?? 'N/A',
                'academic_rank' => $faculty->academic_rank ?? 'N/A',
            ],
            'overall_set_rating' => $overallSetRating !== null ? round($overallSetRating, 2) : null,
            'overall_sef_rating' => $overallSefRating !== null ? round($overallSefRating, 2) : null,
            'comments' => $comments,
            'ratings_breakdown' => $ratingsBreakdown,
            'development_plan' => $developmentPlan,
        ]);
    }

    /**
     * Save or update FEDA form data
     * POST /feda/save
     */
    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_no' => 'required|integer|exists:users,id_no',
            'term_id' => 'required|integer|exists:lnu_poes.school_years,id',
            'areas_for_improvement' => 'nullable|string|max:5000',
            'proposed_activities' => 'nullable|string|max:5000',
            'action_plan' => 'nullable|string|max:5000',
            'submit' => 'nullable|boolean',
        ]);

        $user = Auth::user();
        
        // Check if user has permission to save FEDA form
        $canAccessEvaluation = $this->canAccessEvaluationForUser($user);
        if (!$canAccessEvaluation) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to save FEDA forms.',
            ], 403);
        }

        // Check if the faculty exists
        $faculty = User::where('id_no', $validated['id_no'])->first();
        if (!$faculty) {
            return response()->json([
                'success' => false,
                'message' => 'Faculty not found.',
            ], 404);
        }

        $fedaForm = FacultyDevelopmentForm::updateOrCreate(
            [
                'id_no' => $validated['id_no'],
                'term_id' => $validated['term_id'],
            ],
            [
                'areas_for_improvement' => $validated['areas_for_improvement'] ?? null,
                'proposed_learning_and_development_activities' => $validated['proposed_activities'] ?? null,
                'action_plan' => $validated['action_plan'] ?? null,
                'updated_by' => $user->id,
            ]
        );

        // If submit flag is true, mark as submitted
        if (isset($validated['submit']) && $validated['submit']) {
            $fedaForm->markAsSubmitted($user->id);
        }

        return response()->json([
            'success' => true,
            'message' => $validated['submit'] ? 'FEDA form submitted successfully' : 'FEDA form saved successfully',
            'data' => $fedaForm,
        ]);
    }

    /**
     * Remove an existing FEDA form
     * DELETE /feda/{id_no}/{term_id}
     */
    public function destroy(Request $request, $idNo, $termId)
    {
        $user = $request->user();
        
        // Check if user has permission to delete FEDA form
        if (!$this->canAccessEvaluationForUser($user)) {
            abort(403, 'You do not have permission to delete FEDA forms.');
        }

        // Find the specific FEDA form
        $fedaForm = FacultyDevelopmentForm::where('id_no', $idNo)
            ->where('term_id', $termId)
            ->firstOrFail();

        try {
            $fedaForm->delete();
            
            // Redirect back so Inertia reloads the page automatically
            return redirect()->back();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to delete FEDA form: ' . $e->getMessage());
            return redirect()->back()->withErrors(['error' => 'Failed to remove FEDA form.']);
        }
    }

    /**
     * Get PDF URL for FEDA form
     * GET /feda/pdf-url/{facultyId}
     */
    public function getPdfUrl($facultyId, Request $request)
    {
        $termId = $request->query('term_id');
        
        if (!$termId) {
            return response()->json(['error' => 'Term ID is required'], 422);
        }
        
        // Check permissions
        $user = Auth::user();
        if (!$this->canAccessEvaluationForUser($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        // Build URL with development plan data
        $url = route('feda.form.pdf', ['id' => $facultyId]) . '?' . http_build_query([
            'term_id' => $termId,
            'areas_for_improvement' => $request->query('areas_for_improvement', ''),
            'proposed_activities' => $request->query('proposed_activities', ''),
            'action_plan' => $request->query('action_plan', '')
        ]);
        
        return response()->json(['pdf_url' => $url]);
    }
    /**
     * Get SEF data for a specific faculty
     */
    private function getFacultySefData($facultyId, $termId)
    {
        $submissions = SupervisorEvaluationSubmission::query()
            ->where('instructor_id_no', $facultyId)
            ->where('term_id', $termId)
            ->with(['answers' => function($q) {
                $q->select('submission_id', 'question_key', 'score')
                    ->orderBy('question_key');
            }])
            ->get();

        $respondentCount = $submissions->count();

        if ($respondentCount === 0) {
            return [
                'has_data' => false,
                'overall_sef_rating' => null,
                'total_evaluators' => 0,
                'comments' => '',
                'ratings_breakdown' => null,
            ];
        }

        $totalPercentage = 0;
        foreach ($submissions as $submission) {
            $totalPercentage += $submission->rating_percentage ?? 0;
        }

        $overallPercentage = $respondentCount > 0 ? round($totalPercentage / $respondentCount, 2) : null;

        $comments = $submissions
            ->pluck('comments')
            ->filter()
            ->map(function ($c) { return trim($c); })
            ->filter()
            ->unique()
            ->implode("\n\n");

        // Get ratings breakdown for 15 benchmarks
        $ratings = array_fill(0, 15, 0);
        $ratingCounts = array_fill(0, 15, 0);

        foreach ($submissions as $submission) {
            foreach ($submission->answers as $answer) {
                $questionNum = $this->extractQuestionNumber($answer->question_key);
                if ($questionNum >= 1 && $questionNum <= 15) {
                    $index = $questionNum - 1;
                    $ratings[$index] += $answer->score;
                    $ratingCounts[$index]++;
                }
            }
        }

        for ($i = 0; $i < 15; $i++) {
            if ($ratingCounts[$i] > 0) {
                $ratings[$i] = round($ratings[$i] / $ratingCounts[$i], 2);
            } else {
                $ratings[$i] = null;
            }
        }

        return [
            'has_data' => true,
            'overall_sef_rating' => $overallPercentage,
            'total_evaluators' => $respondentCount,
            'comments' => $comments,
            'ratings_breakdown' => $ratings,
        ];
    }

    /**
     * Get faculty overall SET rating (copied from ReportsController)
     */
    private function getFacultyOverallSetRating(?string $idNo, ?string $instructor = null, ?int $termId = null): ?float
    {
        $normalizedIdNo = trim((string) ($idNo ?? ''));
        $normalizedInstructor = trim((string) ($instructor ?? ''));

        if ($normalizedIdNo === '' && $normalizedInstructor === '') {
            return null;
        }

        static $cache = [];
        $cacheKey = ($normalizedIdNo !== '' ? 'id:' . $normalizedIdNo : 'name:' . mb_strtoupper($normalizedInstructor)) . '|' . ($termId ?? 'all');

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        try {
            // Get all subjects and their submissions for this instructor
            $query = DB::connection('lnu_poes')
                ->table('enrollment_courses as ec')
                ->join('student_evaluation_submissions as ses', 'ec.id', '=', 'ses.subject_id')
                ->select('ec.course_code', 'ec.section_code')
                ->selectRaw('COUNT(DISTINCT ses.student_id_number) as student_count')
                ->selectRaw('AVG(ses.rating_percentage) as avg_rating')
                ->whereNotNull('ses.rating_percentage')
                ->groupBy('ec.course_code', 'ec.section_code');

            // Apply instructor filter using id_no first for strict matching.
            if ($normalizedIdNo !== '') {
                $query->where('ec.id_no', $normalizedIdNo);
            } elseif ($normalizedInstructor !== '') {
                // Fallback only when id_no is unavailable.
                $tokens = preg_split('/[^\pL\pN]+/u', mb_strtoupper($normalizedInstructor)) ?: [];
                $tokens = array_values(array_filter($tokens, fn($token) => mb_strlen($token) > 1));

                foreach ($tokens as $token) {
                    $query->where('ec.instructor', 'like', '%' . $token . '%');
                }
            }

            // Apply term filter
            if ($termId !== null && $termId !== '' && $termId !== 'all') {
                $query->where('ec.school_year_id', $termId);
                $query->where('ses.term_id', $termId);
            }

            $subjects = $query->get();

            if ($subjects->isEmpty()) {
                return null;
            }

            // Calculate weighted average
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

            $overallRating = round($totalWeightedScore / $totalStudents, 2);
            $cache[$cacheKey] = $overallRating;

            return $overallRating;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error getting SET rating: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract question number from various question key formats
     */
    private function extractQuestionNumber($questionKey)
    {
        if (preg_match('/^q(\d+)$/i', $questionKey, $matches)) {
            return (int) $matches[1];
        }
        
        if (preg_match('/(?:benchmark|question)\s*(\d+)/i', $questionKey, $matches)) {
            return (int) $matches[1];
        }
        
        if (is_numeric($questionKey)) {
            return (int) $questionKey;
        }
        
        if (preg_match('/(\d+)/', $questionKey, $matches)) {
            return (int) $matches[1];
        }
        
        return null;
    }

    private function renderEmptyState($user, string $message)
    {
        return redirect()->route('evaluation');
    }

    private function resolveSelectedSchoolYearId(Request $request): ?int
    {
        $selectedSchoolYearId = $request->query('term');

        if (! $selectedSchoolYearId || $selectedSchoolYearId === 'current' || $selectedSchoolYearId === 'all') {
            $activeSchoolYear = $this->getActiveSchoolYear();

            return $activeSchoolYear ? (int) $activeSchoolYear->id : null;
        }

        return (int) $selectedSchoolYearId;
    }

    private function buildInstructorsPayload(array $facultyUsers, int $schoolYearId, string $termLabel): array
    {
        return collect($facultyUsers)->map(function ($faculty) use ($schoolYearId, $termLabel) {
            return [
                'id' => $faculty['id'],
                'id_no' => $faculty['id_no'],
                'name' => $faculty['name'],
                'initials' => $faculty['initials'],
                'college_id' => $faculty['college_id'],
                'unit_id' => $faculty['unit_id'],
                'college' => $faculty['college'],
                'program' => $faculty['program'],
                'academic_rank' => $faculty['academic_rank'],
                'course_code' => $faculty['course_code'],
                'course_title' => $faculty['course_title'],
                'term_label' => $termLabel,
                'term_id' => $schoolYearId,
            ];
        })->values()->all();
    }

    private function getFacultyUsersForFEDA($user, int $schoolYearId, $schoolYear): array
    {
        if (! $user) {
            return [];
        }

        $isAdmin = in_array($user->role ?? '', ['admin', 'administrator', 'super_admin']);
        $isUnitHead = in_array($user->role ?? '', ['unit_head', 'department_head', 'program_head']);
        if (! $isUnitHead && $user->unitHead) {
            $isUnitHead = true;
        }

        $isDean = in_array($user->role ?? '', ['dean', 'college_dean']);
        $isAssociateDean = in_array($user->role ?? '', ['associate_dean', 'assoc_dean']);

        if ($isAssociateDean) {
            $associateDean = $user->associateDean;
            if (! $associateDean || ! $associateDean->college_id) {
                return [];
            }

            $collegeId = $associateDean->college_id;
            $unitHeadUsers = User::query()
                ->whereHas('unitHead')
                ->where('college_id', $collegeId)
                ->with(['college', 'unit'])
                ->orderBy('lastname')
                ->orderBy('firstname')
                ->get();

            if ($unitHeadUsers->isEmpty()) {
                return [];
            }

            $idNos = $unitHeadUsers->pluck('id_no')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (empty($idNos)) {
                return [];
            }

            $allCourses = DB::connection('lnu_poes')
                ->table('enrollment_courses')
                ->where('school_year_id', $schoolYearId)
                ->whereIn('id_no', $idNos)
                ->select(['id_no', 'course_code', 'course_description'])
                ->get();

            $coursesByIdNo = $allCourses->groupBy('id_no');
            $unitHeadEvaluations = [];

            foreach ($unitHeadUsers as $unitHeadUser) {
                $courses = $coursesByIdNo->get($unitHeadUser->id_no);
                if (! $courses || $courses->isEmpty()) {
                    continue;
                }

                $firstCourse = $courses->first();
                $displayName = $this->buildDisplayName($unitHeadUser);
                $initials = $this->buildInitials($displayName, $unitHeadUser->id_no);

                $unitHeadEvaluations[] = [
                    'id' => $unitHeadUser->id,
                    'id_no' => $unitHeadUser->id_no,
                    'name' => $displayName,
                    'initials' => $initials,
                    'college_id' => $unitHeadUser->college_id,
                    'unit_id' => $unitHeadUser->unit_id,
                    'college' => $unitHeadUser->college?->name ?? 'N/A',
                    'program' => $unitHeadUser->unit?->name ?? 'N/A',
                    'academic_rank' => $unitHeadUser->academic_rank ?? 'N/A',
                    'course_code' => $firstCourse->course_code ?? '',
                    'course_title' => $firstCourse->course_description ?? '',
                ];
            }

            return $unitHeadEvaluations;
        }

        $facultyQuery = User::query()
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->with(['college', 'unit'])
            ->orderBy('lastname')
            ->orderBy('firstname');

        if ($isDean) {
            return [];
        } elseif ($isUnitHead) {
            $unitHead = $user->unitHead;
            if (! $unitHead || ! $unitHead->unit_id) {
                return [];
            }

            $facultyQuery->where('unit_id', $unitHead->unit_id);
        } elseif (! $isAdmin) {
            return [];
        }

        $facultyUsers = $facultyQuery->get(['id', 'id_no', 'firstname', 'lastname', 'middlename', 'extname', 'college_id', 'unit_id', 'academic_rank']);
        if ($facultyUsers->isEmpty()) {
            return [];
        }

        $idNos = $facultyUsers->pluck('id_no')->filter()->unique()->values()->all();
        if (empty($idNos)) {
            return [];
        }

        $allCourses = DB::connection('lnu_poes')
            ->table('enrollment_courses')
            ->where('school_year_id', $schoolYearId)
            ->whereIn('id_no', $idNos)
            ->select(['id_no', 'course_code', 'course_description'])
            ->get();

        $coursesByIdNo = $allCourses->groupBy('id_no');
        $facultyEvaluations = [];

        foreach ($facultyUsers as $faculty) {
            $courses = $coursesByIdNo->get($faculty->id_no);
            if (! $courses || $courses->isEmpty()) {
                continue;
            }

            $firstCourse = $courses->first();
            $displayName = $this->buildDisplayName($faculty);
            $initials = $this->buildInitials($displayName, $faculty->id_no);

            $facultyEvaluations[] = [
                'id' => $faculty->id,
                'id_no' => $faculty->id_no,
                'name' => $displayName,
                'initials' => $initials,
                'college_id' => $faculty->college_id,
                'unit_id' => $faculty->unit_id,
                'college' => $faculty->college?->name ?? 'N/A',
                'program' => $faculty->unit?->name ?? 'N/A',
                'academic_rank' => $faculty->academic_rank ?? 'N/A',
                'course_code' => $firstCourse->course_code ?? '',
                'course_title' => $firstCourse->course_description ?? '',
            ];
        }

        return $facultyEvaluations;
    }

    private function getActiveSchoolYear()
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
            Cache::put('active_school_year', $fresh, now()->addSeconds(self::CACHE_TTL));
        }

        return $fresh;
    }

    private function getSchoolYearById(int $id)
    {
        return DB::connection('lnu_poes')
            ->table('school_years')
            ->where('id', $id)
            ->first();
    }

    private function getSchoolYearsList(): array
    {
        $cached = Cache::get('school_years_list');
        if (is_array($cached) && ! empty($cached) && isset($cached[0]['label'], $cached[0]['value'])) {
            return $cached;
        }

        $rows = DB::connection('lnu_poes')
            ->table('school_years')
            ->select(['id', 'school_year_from', 'school_year_to', 'semester'])
            ->orderByDesc('school_year_to')
            ->orderByDesc('school_year_from')
            ->orderByDesc('semester')
            ->get();

        if ($rows->isEmpty()) {
            $fallback = [
                ['label' => 'S.Y. 2025-2026 - 2nd Semester', 'value' => '2025-2026-2nd Semester'],
                ['label' => 'S.Y. 2025-2026 - 1st Semester', 'value' => '2025-2026-1st Semester'],
            ];
            Cache::put('school_years_list', $fallback, now()->addSeconds(self::CACHE_TTL));

            return $fallback;
        }

        $result = $rows->map(function ($row) {
            return [
                'label' => sprintf(
                    'S.Y. %s-%s - %s',
                    $row->school_year_from,
                    $row->school_year_to,
                    match ((int) $row->semester) {
                        1 => '1st Semester',
                        2 => '2nd Semester',
                        3 => 'Summer',
                        default => 'Semester ' . $row->semester,
                    }
                ),
                'value' => (string) $row->id,
            ];
        })->values()->all();

        Cache::put('school_years_list', $result, now()->addSeconds(self::CACHE_TTL));

        return $result;
    }

    private function buildDisplayName($faculty): string
    {
        $firstName = trim($faculty->firstname ?? '');
        $lastName = trim($faculty->lastname ?? '');
        $extName = trim($faculty->extname ?? '');

        $displayName = trim($firstName . ' ' . $lastName);
        if (! empty($extName)) {
            $displayName .= ' ' . $extName;
        }

        return $displayName ?: $faculty->id_no;
    }

    private function buildInitials(string $displayName, string $idNo): string
    {
        $initials = '';
        $nameWords = preg_split('/\s+/', trim($displayName));

        foreach ($nameWords as $word) {
            if ($word !== '' && ! preg_match('/^(Jr|Sr|III|IV|II)$/i', $word)) {
                $initials .= strtoupper(mb_substr($word, 0, 1));
                if (mb_strlen($initials) >= 2) {
                    break;
                }
            }
        }

        return $initials ?: strtoupper(mb_substr($idNo, 0, 2));
    }

    private function buildTermLabel($schoolYear): string
    {
        return 'S.Y. ' . $schoolYear->school_year_from . '-' . $schoolYear->school_year_to . ' - ' . match ((int) $schoolYear->semester) {
            1 => '1st Semester',
            2 => '2nd Semester',
            3 => 'Summer',
            default => 'Semester ' . $schoolYear->semester,
        };
    }

    private function canAccessEvaluationForUser($user): bool
    {
        if (! $user) {
            return false;
        }

        $role = $user->role ?? '';

        if (in_array($role, ['admin', 'administrator', 'super_admin'])) {
            return true;
        }

        if (in_array($role, ['unit_head', 'department_head', 'program_head'])) {
            return true;
        }

        if ($user->unitHead) {
            return true;
        }

        if (in_array($role, ['associate_dean', 'assoc_dean'])) {
            return true;
        }

        if ($user->associateDean) {
            return true;
        }

        if (in_array($role, ['dean', 'college_dean'])) {
            return false;
        }

        return false;
    }
}