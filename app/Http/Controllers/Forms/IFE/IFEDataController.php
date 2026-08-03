<?php

namespace App\Http\Controllers\Forms\IFE;

use App\Http\Controllers\Controller;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\User;
use App\Models\Dean;
use App\Models\AssociateDean;
use Illuminate\Support\Facades\DB;

class IFEDataController extends Controller
{
    /**
     * Get all data needed for IFE PDF
     */
    public function getFacultyData($facultyId, $termId)
    {
        return [
            'faculty_info' => $this->getFacultyInfo($facultyId),
            'set_data' => $this->getSETData($facultyId, $termId),
            'sef_data' => $this->getSEFData($facultyId, $termId),
            'comments' => $this->getComments($facultyId, $termId),
        ];
    }

    /**
     * Get faculty information
     */
    private function getFacultyInfo($facultyId)
    {
        $faculty = User::with(['college', 'unit'])
            ->where('id_no', $facultyId)
            ->first();
        
        $collegeName = $faculty?->college?->name ?? '';
        $departmentName = $faculty?->unit?->name ?? '';
        $collegeId = $faculty?->college_id ?? null;
        
        // Get Dean and Associate Dean names
        $deans = $this->getCollegeDeans($collegeId);
        
        return [
            'name' => $faculty ? trim(($faculty->firstname ?? '') . ' ' . ($faculty->lastname ?? '')) : 'Faculty Member',
            'college' => collect([$departmentName, $collegeName])->filter()->implode(' / ') ?: 'N/A',
            'academic_rank' => $faculty->academic_rank ?? 'N/A',
            'id_no' => $facultyId,
            'college_id' => $collegeId,
            'dean_name' => $deans['dean_name'],
            'associate_dean_name' => $deans['associate_dean_name'],
        ];
    }

    /**
     * Get Dean and Associate Dean for a college
     */
    private function getCollegeDeans($collegeId)
    {
        if (!$collegeId) {
            return [
                'dean_name' => '',
                'associate_dean_name' => '',
            ];
        }

        // Get Dean using raw DB query
        $dean = DB::table('deans')
            ->where('college_id', $collegeId)
            ->first();

        // Get Associate Dean using raw DB query
        $associateDean = DB::table('associate_deans')
            ->where('college_id', $collegeId)
            ->first();

        $deanName = '';
        if ($dean && $dean->user_id) {
            $deanUser = User::find($dean->user_id);
            if ($deanUser) {
                $deanName = trim(($deanUser->firstname ?? '') . ' ' . ($deanUser->lastname ?? ''));
            }
        }

        $associateDeanName = '';
        if ($associateDean && $associateDean->user_id) {
            $associateDeanUser = User::find($associateDean->user_id);
            if ($associateDeanUser) {
                $associateDeanName = trim(($associateDeanUser->firstname ?? '') . ' ' . ($associateDeanUser->lastname ?? ''));
            }
        }

        return [
            'dean_name' => $deanName,
            'associate_dean_name' => $associateDeanName,
        ];
    }

    /**
     * Get SET data (Student Evaluation of Teachers)
     */
    private function getSETData($facultyId, $termId)
    {
        try {
            // Get all sections for each course
            $allSections = DB::connection('lnu_poes')
                ->table('enrollment_courses as ec')
                ->join('student_evaluation_submissions as ses', 'ec.id', '=', 'ses.subject_id')
                ->select(
                    'ec.course_code',
                    'ec.section_code',
                    'ec.year_level',
                    DB::raw('COUNT(DISTINCT ses.student_id_number) as student_count'),
                    DB::raw('AVG(ses.rating_percentage) as avg_rating')
                )
                ->where('ec.id_no', $facultyId)
                ->where('ec.school_year_id', $termId)
                ->whereNotNull('ses.rating_percentage')
                ->groupBy('ec.course_code', 'ec.section_code', 'ec.year_level')
                ->get();

            if ($allSections->isEmpty()) {
                return [
                    'rows' => [],
                    'overall_rating' => null,
                    'total_students' => 0,
                    'total_weighted_score' => 0,
                ];
            }

            // Group by course_code AND base section (e.g., AI31, AI32, etc.)
            $groupedByCourseAndBaseSection = [];
            foreach ($allSections as $section) {
                $courseCode = $section->course_code;
                $sectionCode = $section->section_code ?? '';
                
                // Extract the base section (letters + numbers without the year prefix)
                // e.g., "AI31" from "3-AI31" or "4-AI31"
                $baseSection = preg_replace('/^\d+-/', '', $sectionCode);
                
                // If no base section found, use the section code as-is
                if (empty($baseSection)) {
                    $baseSection = $sectionCode;
                }
                
                $key = $courseCode . '|' . $baseSection;
                
                if (!isset($groupedByCourseAndBaseSection[$key])) {
                    $groupedByCourseAndBaseSection[$key] = [
                        'course_code' => $courseCode,
                        'base_section' => $baseSection,
                        'sections' => [],
                        'year_levels' => [],
                        'total_students' => 0,
                        'total_weighted' => 0,
                    ];
                }
                
                // Store section info
                $yearLevel = $section->year_level ?? '';
                $sectionLabel = $yearLevel && $sectionCode ? $yearLevel . '-' . $sectionCode : ($yearLevel ?: $sectionCode);
                
                $groupedByCourseAndBaseSection[$key]['sections'][] = $sectionLabel;
                $groupedByCourseAndBaseSection[$key]['year_levels'][] = $yearLevel;
                
                // Aggregate totals
                $studentCount = (int) $section->student_count;
                $avgRating = (float) $section->avg_rating;
                
                $groupedByCourseAndBaseSection[$key]['total_students'] += $studentCount;
                $groupedByCourseAndBaseSection[$key]['total_weighted'] += $studentCount * $avgRating;
            }

            // Format rows for the SET computation table
            $dataRows = [];
            $totalWeightedScore = 0;
            $totalStudents = 0;
            $index = 0;

            foreach ($groupedByCourseAndBaseSection as $key => $data) {
                $index++;
                
                // Determine the primary section (prefer "3-" over "4-")
                $primarySection = $data['sections'][0];
                
                // If we have multiple sections, prefer the one starting with "3-"
                if (count($data['sections']) > 1) {
                    foreach ($data['sections'] as $section) {
                        if (strpos($section, '3-') === 0) {
                            $primarySection = $section;
                            break;
                        }
                    }
                }
                
                $studentCount = $data['total_students'];
                $avgRating = $studentCount > 0 ? $data['total_weighted'] / $studentCount : 0;
                $weightedScore = $data['total_weighted'];
                
                $totalWeightedScore += $weightedScore;
                $totalStudents += $studentCount;
                
                $dataRows[] = [
                    'seq' => (string)$index,
                    'course_code' => $data['course_code'],
                    'year_section' => $primarySection, // Show only the primary section (e.g., 3-AI31)
                    'student_count' => (string)$studentCount,
                    'avg_set_rating' => number_format($avgRating, 2),
                    'weighted_score' => number_format($weightedScore, 2),
                ];
            }

            // Calculate overall SET rating
            $overallSetRating = $totalStudents > 0 ? round($totalWeightedScore / $totalStudents, 2) : null;

            return [
                'rows' => $dataRows,
                'overall_rating' => $overallSetRating,
                'total_students' => $totalStudents,
                'total_weighted_score' => round($totalWeightedScore, 2),
            ];
        } catch (\Exception $e) {
            return [
                'rows' => [],
                'overall_rating' => null,
                'total_students' => 0,
                'total_weighted_score' => 0,
            ];
        }
    }

    /**
     * Get SEF data (Supervisor Evaluation of Faculty)
     */
    private function getSEFData($facultyId, $termId)
    {
        try {
            $submissions = SupervisorEvaluationSubmission::query()
                ->where('instructor_id_no', $facultyId)
                ->where('term_id', $termId)
                ->get();

            if ($submissions->isEmpty()) {
                return [
                    'overall_rating' => null,
                    'total_evaluators' => 0,
                ];
            }

            $totalPercentage = 0;
            foreach ($submissions as $submission) {
                $totalPercentage += $submission->rating_percentage ?? 0;
            }

            return [
                'overall_rating' => round($totalPercentage / $submissions->count(), 2),
                'total_evaluators' => $submissions->count(),
            ];
        } catch (\Exception $e) {
            return [
                'overall_rating' => null,
                'total_evaluators' => 0,
            ];
        }
    }

    /**
     * Get comments from students and supervisors
     */
    private function getComments($facultyId, $termId)
    {
        try {
            // Student comments
            $studentComments = DB::connection('lnu_poes')
                ->table('student_evaluation_submissions as ses')
                ->join('enrollment_courses as ec', 'ec.id', '=', 'ses.subject_id')
                ->where('ec.id_no', $facultyId)
                ->where('ec.school_year_id', $termId)
                ->whereNotNull('ses.comment')
                ->where('ses.comment', '!=', '')
                ->pluck('ses.comment')
                ->toArray();

            // Supervisor comments
            $supervisorComments = SupervisorEvaluationSubmission::query()
                ->where('instructor_id_no', $facultyId)
                ->where('term_id', $termId)
                ->whereNotNull('comments')
                ->where('comments', '!=', '')
                ->pluck('comments')
                ->toArray();

            // Format for PDF (limit to 10 each)
            $formattedStudentComments = [];
            foreach (array_slice($studentComments, 0, 10) as $index => $comment) {
                $formattedStudentComments[] = [
                    'seq' => (string)($index + 1),
                    'comment' => trim($comment),
                ];
            }

            $formattedSupervisorComments = [];
            foreach (array_slice($supervisorComments, 0, 10) as $index => $comment) {
                $formattedSupervisorComments[] = [
                    'seq' => (string)($index + 1),
                    'comment' => trim($comment),
                ];
            }

            return [
                'student' => $formattedStudentComments,
                'supervisor' => $formattedSupervisorComments,
            ];
        } catch (\Exception $e) {
            return [
                'student' => [],
                'supervisor' => [],
            ];
        }
    }

    /**
     * Batch get data for multiple faculty
     */
    public function getBatchFacultyData($facultyIds, $termId)
    {
        $results = [];
        foreach ($facultyIds as $facultyId) {
            $results[$facultyId] = $this->getFacultyData($facultyId, $termId);
        }
        return $results;
    }
}