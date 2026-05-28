<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use App\Models\UnitHeadGrade;
use App\Models\Poes\PoesSubjects;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubjectsController extends Controller
{
    use FacultyData;

    public function index(Request $request)
    {
        $currentUser = $request->user();
        $facultyEvaluations = $this->getFacultyEvaluations();
        
        // Get available terms from the school_years table (all terms, not just active)
        $availableTerms = DB::connection('lnu_poes')
            ->table('school_years')
            ->orderByDesc('school_year_from')
            ->orderByDesc('semester')
            ->get()
            ->map(function ($term) {
                $semesterText = '';
                switch ($term->semester) {
                    case 1: $semesterText = '1st Semester'; break;
                    case 2: $semesterText = '2nd Semester'; break;
                    case 3: $semesterText = 'Summer'; break;
                    default: $semesterText = "Semester {$term->semester}";
                }
                
                return [
                    'id' => $term->id,
                    'label' => "S.Y. {$term->school_year_from}-{$term->school_year_to} - {$semesterText}",
                ];
            })
            ->toArray();
        
        // Handle term parameter - expecting ID
        $termParam = $request->query('term', null);

        $selectedTermId = null;

        // If a specific term is requested
        if ($termParam && $termParam !== 'all' && $termParam !== '') {
            $selectedTermId = is_numeric($termParam) ? (int) $termParam : null;
        } 
        // If no term is selected, default to the latest term (most recent school year and semester)
        elseif (empty($termParam) || $termParam === '') {
            // Get the latest term (most recent school_year_from and semester)
            $latestTerm = DB::connection('lnu_poes')
                ->table('school_years')
                ->orderByDesc('school_year_from')
                ->orderByDesc('semester')
                ->first();
            
            if ($latestTerm) {
                $selectedTermId = $latestTerm->id;
            }
        }
        
        // Get subjects with pagination and term filter using ID
        $subjects = $this->getUserSubjects($currentUser, $selectedTermId);
        
        // Get school year metadata for additional context
        $schoolYearMetaById = $this->getSchoolYearMetaById();
        
        // Transform subjects
        $transformedSubjects = $subjects->map(function ($row) use ($schoolYearMetaById) {
            $schoolYearId = $row->school_year_id;
            $meta = $schoolYearMetaById[$schoolYearId] ?? null;
            
            return [
                'id' => $row->id,
                'course_code' => $row->course_code ?? '',
                'course_description' => $row->course_description ?? '',
                'course_units' => $row->course_units ?? null,
                'section_code' => $row->section_code ?? null,
                'schedule_time' => $row->schedule_time ?? null,
                'schedule_days' => $row->schedule_days ?? null,
                'room' => $row->room ?? null,
                'school_year_id' => $schoolYearId,
                'school_year_label' => $meta ? "S.Y. {$meta['year_from']}-{$meta['year_to']}" : null,
                'semester' => $meta ? $this->getSemesterText($meta['semester']) : null,
                'instructor' => $row->instructor ?? null,
                'id_no' => $row->id_no ?? null,
            ];
        })->values()->all();
        
        $subjectsProps = $this->commonInertiaProps($currentUser, [
            'subjects' => $transformedSubjects,
            'subjectPagination' => [
                'current_page' => $subjects->currentPage(),
                'last_page' => $subjects->lastPage(),
                'per_page' => $subjects->perPage(),
                'total' => $subjects->total(),
            ],
            'availableTerms' => $availableTerms,
            'selectedTerm' => $selectedTermId,
            'hasPendingEvaluations' => UnitHeadGrade::query()
                ->where('user_id', $currentUser->id)
                ->distinct('instructor')
                ->count('instructor') < count($facultyEvaluations),
        ]);
        
        return Inertia::render('SubjectsPage', $subjectsProps);
    }
    
    /**
     * Get user subjects with optimized query and term filter using ID
     * - Groups by course_code + section_code + school_year_id to prevent duplicates
     */
    private function getUserSubjects($currentUser, $selectedTermId = null)
    {
        $lastName = trim((string) ($currentUser->lastname ?? ''));
        $idNo = $currentUser->id_no ?? '';
        
        $query = PoesSubjects::query();
        
        // Build conditions efficiently
        if ($idNo) {
            $query->where(function ($q) use ($idNo) {
                $q->where('id_number', $idNo)
                ->orWhere('id_no', $idNo);
            });
        } elseif ($lastName !== '') {
            $query->whereRaw('LOWER(TRIM(instructor)) LIKE ?', ['%' . mb_strtolower($lastName) . '%']);
        } else {
            // If no id_no and no last name, return empty result
            return PoesSubjects::query()->whereRaw('1 = 0')->paginate(10);
        }
        
        // Apply term filter using ID
        if ($selectedTermId && $selectedTermId !== 'all') {
            $query->where('school_year_id', $selectedTermId);
        }
        
        // Group by unique subject combination
        // This selects MIN(id) to get a representative row for each group
        $query->select(
            'course_code',
            'school_year_id',
            DB::raw('MIN(id) as id'),
            DB::raw('MAX(course_description) as course_description'),
            DB::raw('MAX(course_units) as course_units'),
            DB::raw('MAX(schedule_time) as schedule_time'),
            DB::raw('MAX(schedule_days) as schedule_days'),
            DB::raw('MAX(room) as room'),
            DB::raw('MAX(instructor) as instructor'),
            DB::raw('MAX(id_no) as id_no'),
            DB::raw('MAX(id_number) as id_number'),
            DB::raw('COALESCE(section_code, "NO_SECTION") as section_code')
        )
        ->groupBy('course_code', 'section_code', 'school_year_id')
        ->orderByRaw('CAST(school_year_id AS UNSIGNED) DESC')
        ->orderBy('course_code');
        
        // Order and paginate
        return $query->paginate(10);
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
     * Get detailed view of a specific subject
     */
    public function show(Request $request, $id)
    {
        $currentUser = $request->user();
        
        $subject = PoesSubjects::findOrFail($id);
        
        // Verify user has access to this subject
        $lastName = trim((string) ($currentUser->lastname ?? ''));
        $idNo = $currentUser->id_no ?? '';
        
        $hasAccess = false;
        if ($idNo) {
            $hasAccess = ($subject->id_number == $idNo || $subject->id_no == $idNo);
        } elseif ($lastName !== '') {
            $hasAccess = stripos($subject->instructor, $lastName) !== false;
        }
        
        if (!$hasAccess && !$currentUser->isAdmin() && !$currentUser->isDean() && !$currentUser->isAssociateDean() && !$currentUser->isUnitHead()) {
            abort(403, 'Unauthorized access to this subject.');
        }
        
        // Get term info
        $termLabel = null;
        if ($subject->school_year_id) {
            $term = DB::connection('lnu_poes')
                ->table('school_years')
                ->where('id', $subject->school_year_id)
                ->first();
            
            if ($term) {
                $semesterText = $this->getSemesterText($term->semester);
                $termLabel = "S.Y. {$term->school_year_from}-{$term->school_year_to} - {$semesterText}";
            }
        }
        
        $subjectData = [
            'id' => $subject->id,
            'course_code' => $subject->course_code,
            'course_description' => $subject->course_description,
            'course_units' => $subject->course_units,
            'section_code' => $subject->section_code,
            'schedule_time' => $subject->schedule_time,
            'schedule_days' => $subject->schedule_days,
            'room' => $subject->room,
            'instructor' => $subject->instructor,
            'id_no' => $subject->id_no,
            'term_label' => $termLabel,
            'school_year_id' => $subject->school_year_id,
        ];
        
        $props = $this->commonInertiaProps($currentUser, [
            'subject' => $subjectData,
        ]);
        
        return Inertia::render('SubjectDetailPage', $props);
    }
}