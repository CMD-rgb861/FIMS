<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class ReportEvaluationController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();
        $instructorName = trim((string) (($currentUser->firstname ?? '') . ' ' . ($currentUser->lastname ?? '')));
        $termId = $request->query('term');


        $query = $this->buildEvaluationQuery(
            $instructorName !== '' ? $instructorName : null,
            $currentUser->id_no ?? null,
            $termId
        );

        // Optional raw scores
        $totalScores = (clone $query)->pluck('rating_percentage');

        // Overall unique evaluators (all subjects combined)
        $submittedStudentsCount = (clone $query)
            ->distinct('student_id_number')
            ->count('student_id_number');

        // SUBJECT-BASED COMPUTATION
        $subjectEvaluations = (clone $query)
            ->select('ec.course_code')
            ->select('ec.course_description')
            ->select('ec.instructor')
            ->selectRaw("COALESCE(NULLIF(MAX(TRIM(ec.year_level)), ''), 'N/A') as year_level")
            ->selectRaw("COALESCE(NULLIF(TRIM(ec.section_code), ''), 'N/A') as section_code")
            ->selectRaw('COUNT(DISTINCT ses.student_id_number) as total_evaluators')
            ->selectRaw('COUNT(ses.id) as total_submissions')
            ->selectRaw('AVG(ses.rating_percentage) as average_score')
            ->selectRaw('ROUND(SUM(ses.rating_percentage), 2) as total_score_sum')
            ->selectRaw('MIN(ses.submitted_at) as first_submission')
            ->selectRaw('MAX(ses.submitted_at) as last_submission')
            ->groupBy('ec.course_code')
            ->groupBy('ec.course_description')
            ->groupBy('ec.instructor')
            ->groupBy('ec.section_code')
            ->get()
            ->map(function ($item) {
                $item->weighted_score =
                    $item->total_evaluators * $item->average_score;

                return $item;
            });

        return Inertia::render('ReportsPage', [
            'totalScores' => $totalScores,
            'submittedStudentsCount' => $submittedStudentsCount,
            'subjectEvaluations' => $subjectEvaluations,
        ]);
    }

    public function breakdown(Request $request, string $instructor)
    {
        $instructorId = $request->query('instructor_id');
        $subjectId = $request->query('subject_id');
        $courseCode = $request->query('course_code');
        $yearSection = $request->query('year_section');
        $termId = $request->query('term');  

        $query = $this->buildEvaluationQuery($instructor, $instructorId, $courseCode, $yearSection, $termId);

        $rows = (clone $query)
            ->select('ec.course_code')
            ->select('ec.course_description')
            ->select('ec.instructor')
            ->selectRaw("COALESCE(NULLIF(MAX(TRIM(ec.year_level)), ''), 'N/A') as year_level")
            ->selectRaw("COALESCE(NULLIF(TRIM(ec.section_code), ''), 'N/A') as section_code")
            ->selectRaw('COUNT(DISTINCT ses.student_id_number) as total_unique_students')
            ->selectRaw('COUNT(ses.id) as total_submissions')
            ->selectRaw('AVG(ses.rating_percentage) as average_score')
            ->selectRaw('ROUND(SUM(ses.rating_percentage), 2) as total_score_sum')
            ->selectRaw('MIN(ses.submitted_at) as first_submission')
            ->selectRaw('MAX(ses.submitted_at) as last_submission')
            ->groupBy('ec.course_code')
            ->groupBy('ec.course_description')
            ->groupBy('ec.instructor')
            ->groupBy('ec.section_code')
            ->orderBy('ec.course_code')
            ->orderBy('ec.section_code')
            ->get();

        if ($rows->isEmpty()) {
            $normalizedCourseCode = trim((string) ($courseCode ?? ''));

            $setBreakdown = [[
                'seq' => 1,
                'course_code' => $normalizedCourseCode !== '' ? $normalizedCourseCode : null,
                'year_section' => $yearSection ?: '-',
                'no_of_students' => null,
                'average_set_rating' => '-',
                'weighted_set_score' => '-',
                'total_set' => '-',
                'no_of_students_value' => 0,
                'average_set_rating_value' => null,
                'weighted_set_score_value' => null,
                'total_set_value' => null,
                'total_unique_students' => 0,
                'total_submissions' => 0,
                'average_score' => null,
                'total_score_sum' => null,
                'first_submission' => null,
                'last_submission' => null,
            ]];
        } else {
            $setBreakdown = $rows->values()->map(function ($row, $index) use ($yearSection, $courseCode) {
                $uniqueStudents = (int) ($row->total_unique_students ?? 0);
                $averageScore = $row->average_score !== null ? (float) $row->average_score : null;
                $weightedScore = ($averageScore !== null && $uniqueStudents > 0)
                    ? $averageScore * $uniqueStudents
                    : null;
                $totalSet = $averageScore !== null ? $averageScore : null;

                $sectionCode = trim((string) ($row->section_code ?? ''));
                $yearLevel = $this->extractYearLevelFromSectionCode($sectionCode) ?? trim((string) ($row->year_level ?? ''));

                if ($yearLevel !== '' && $sectionCode !== '') {
                    $yearSectionLabel = $yearLevel . '-' . $sectionCode;
                } elseif ($yearLevel !== '') {
                    $yearSectionLabel = $yearLevel;
                } elseif ($sectionCode !== '') {
                    $yearSectionLabel = $sectionCode;
                } else {
                    $yearSectionLabel = $yearSection ?: '-';
                }

                return [
                    'seq' => $index + 1,
                    'course_code' => trim((string) ($row->course_code ?? '')) !== ''
                        ? trim((string) $row->course_code)
                        : ($courseCode !== null && trim((string) $courseCode) !== '' ? trim((string) $courseCode) : null),
                    'year_section' => $yearSectionLabel,
                    'no_of_students' => $uniqueStudents > 0 ? $uniqueStudents : null,
                    'average_set_rating' => $averageScore !== null ? number_format($averageScore, 2) : '-',
                    'weighted_set_score' => $weightedScore !== null ? number_format($weightedScore, 2) : '-',
                    'total_set' => $totalSet !== null ? number_format($totalSet, 2) : '-',
                    'no_of_students_value' => $uniqueStudents,
                    'average_set_rating_value' => $averageScore,
                    'weighted_set_score_value' => $weightedScore,
                    'total_set_value' => $totalSet,
                    'total_unique_students' => $uniqueStudents,
                    'total_submissions' => (int) ($row->total_submissions ?? 0),
                    'average_score' => $averageScore,
                    'total_score_sum' => $row->total_score_sum !== null ? (float) $row->total_score_sum : null,
                    'first_submission' => $row->first_submission ?? null,
                    'last_submission' => $row->last_submission ?? null,
                ];
            })->all();
        }

        return response()->json([
            'instructor' => $instructor,
            'set_breakdown' => $setBreakdown,
            'sef_breakdown' => null,
        ]);
    }

    private function buildEvaluationQuery(?string $instructor = null, ?string $instructorId = null, ?string $courseCode = null, ?string $yearSection = null, ?string $termId = null)
        {
            $query = DB::connection('lnu_poes')
                ->table('enrollment_courses as ec')
                ->join('student_evaluation_submissions as ses', 'ec.id', '=', 'ses.subject_id');

            // Add term filter
            if ($termId !== null && $termId !== '' && $termId !== 'all') {
                $query->where('ec.school_year_id', $termId);
                $query->where('ses.term_id', $termId);
            }

            // Apply course code filter
            if (!empty($courseCode)) {
                $query->where('ec.course_code', $courseCode);
            }

            // Apply section filtering - ONLY filter by section_code, NOT by year_level
            if (!empty($yearSection)) {
                $sectionCode = $this->extractSectionCode($yearSection);
                
                // Only apply section code filter if we have a section code
                if ($sectionCode !== null) {
                    $query->whereRaw("TRIM(ec.section_code) = ?", [$sectionCode]);
                }
                
                // DO NOT filter by year_level since it's often null in the database
                // The year level is usually embedded in the section_code (e.g., "AM11" contains "1" for 1st year)
            }

            // Match by instructor_id OR subject_id (to handle null instructor_id cases)
            if (!empty($instructorId)) {
                $query->where(function ($q) use ($instructorId) {
                    $q->where('ses.instructor_id', $instructorId)
                    ->orWhereColumn('ses.subject_id', 'ec.id');
                });
            } else {
                // Fall back to instructor name matching
                $tokens = $this->extractInstructorTokens($instructor);
                if (!empty($tokens)) {
                    foreach ($tokens as $token) {
                        $query->where('ec.instructor', 'like', '%' . $token . '%');
                    }
                }
            }

            return $query;
        }

    private function extractInstructorTokens(?string $instructor): array
    {
        if ($instructor === null) {
            return [];
        }

        $tokens = preg_split('/[^\pL\pN]+/u', mb_strtoupper(trim($instructor))) ?: [];

        return array_values(array_filter($tokens, function ($token) {
            return mb_strlen($token) > 1;
        }));
    }

    private function extractYearLevel(?string $yearSection): ?string
    {
        if ($yearSection === null || $yearSection === '') {
            return null;
        }

        if (preg_match('/^(?:Year\s*)?([0-9]+)(?:\s*-\s*.*)?$/i', trim($yearSection), $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractYearLevelFromSectionCode(?string $sectionCode): ?string
    {
        if ($sectionCode === null || $sectionCode === '') {
            return null;
        }

        preg_match_all('/\d/', $sectionCode, $matches);
        $digits = $matches[0] ?? [];

        if (count($digits) >= 2) {
            return $digits[count($digits) - 2];
        }

        if (count($digits) === 1) {
            return $digits[0];
        }

        return null;
    }

    private function extractSectionCode(?string $yearSection): ?string
    {
        if ($yearSection === null || $yearSection === '') {
            return null;
        }

        if (preg_match('/-([^:]+)/', $yearSection, $matches)) {
            $sectionCode = trim($matches[1]);

            return $sectionCode !== '' ? $sectionCode : null;
        }

        return null;
    }
}