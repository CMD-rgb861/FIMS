<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubmissionController extends Controller
{
    public function getSubmissions(Request $request)
    {
        $validated = $request->validate([
            'course_code' => 'required|string',
            'course_description' => 'nullable|string',
            'year_section' => 'nullable|string',
            'instructor_id' => 'nullable|string',
            'term_id' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);

        try {
            // Extract section code from year_section if provided
            $sectionCode = null;
            if (!empty($validated['year_section'])) {
                $sectionCode = $this->extractSectionCode($validated['year_section']);
            }
            
            // Build query similar to your working breakdown method
            $query = DB::connection('lnu_poes')
                ->table('enrollment_courses as ec')
                ->join('student_evaluation_submissions as ses', 'ec.id', '=', 'ses.subject_id')
                ->select(
                    'ses.id as submission_id',
                    'ses.student_id_number',
                    'ses.submitted_at',
                    'ses.rating_percentage',
                    'ses.total_score',
                    'ec.course_description',
                )
                ->where('ec.course_code', $validated['course_code'])
                ->whereNotNull('ses.submitted_at');

            // Apply instructor filter (matches your working controller)
            if (!empty($validated['instructor_id'])) {
                $query->where(function ($q) use ($validated) {
                    $q->where('ses.instructor_id', $validated['instructor_id'])
                      ->orWhereColumn('ses.subject_id', 'ec.id');
                });
            }

            // Apply section filter (matches your working controller)
            if ($sectionCode !== null) {
                $query->whereRaw("TRIM(ec.section_code) = ?", [$sectionCode]);
            }

            // Apply term filter
            if (!empty($validated['term_id']) && $validated['term_id'] !== 'all') {
                $query->where('ec.school_year_id', $validated['term_id']);
                $query->where('ses.term_id', $validated['term_id']);
            }

            $paginatedSubmissions = $query
                ->orderBy('ses.submitted_at', 'desc')
                ->paginate(
                    $perPage,
                    ['*'],
                    'page',
                    $page
                );

            $submissions = collect($paginatedSubmissions->items());

            if ($submissions->count() === 0) {
                return response()->json([
                    'success' => true,
                    'students' => [],
                    'course_description' => $validated['course_description'] ?? null,
                    'pagination' => [
                        'current_page' => $paginatedSubmissions->currentPage(),
                        'last_page' => $paginatedSubmissions->lastPage(),
                        'per_page' => $paginatedSubmissions->perPage(),
                        'total' => $paginatedSubmissions->total(),
                        'from' => $paginatedSubmissions->firstItem(),
                        'to' => $paginatedSubmissions->lastItem(),
                    ],
                ]);
            }

            // Get unique student ID numbers
            $studentIdNumbers = $submissions->pluck('student_id_number')
                ->filter() // Remove null/empty values
                ->unique()
                ->values()
                ->toArray();
            
            if (empty($studentIdNumbers)) {
                // If no student ID numbers, return submissions without student names
                $students = $submissions->map(function ($submission) {
                    return (object)[
                        'submission_id' => $submission->submission_id,
                        'student_id_number' => $submission->student_id_number ?? 'N/A',
                        'student_name' => 'Unknown Student',
                        'submitted_at' => $submission->submitted_at,
                        'rating_percentage' => $submission->rating_percentage,
                        'total_score' => $submission->total_score,
                    ];
                })->values();
                
                return response()->json([
                    'success' => true,
                    'students' => $students,
                    'course_description' => $submissions->first()->course_description ?? $validated['course_description'] ?? null,
                    'pagination' => [
                        'current_page' => $paginatedSubmissions->currentPage(),
                        'last_page' => $paginatedSubmissions->lastPage(),
                        'per_page' => $paginatedSubmissions->perPage(),
                        'total' => $paginatedSubmissions->total(),
                        'from' => $paginatedSubmissions->firstItem(),
                        'to' => $paginatedSubmissions->lastItem(),
                    ],
                ]);
            }
            
            // Get student details from local database
            $users = DB::connection('mysql')
                ->table('users')
                ->select('id_no', 'firstname', 'lastname')
                ->whereIn('id_no', $studentIdNumbers)
                ->get()
                ->keyBy('id_no');

            // Combine the data
            $students = $submissions->map(function ($submission) use ($users) {
                $user = $users->get($submission->student_id_number);
                return (object)[
                    'submission_id' => $submission->submission_id,
                    'student_id_number' => $submission->student_id_number,
                    'student_name' => $user ? trim($user->firstname . ' ' . $user->lastname) : 'Unknown Student',
                    'submitted_at' => $submission->submitted_at,
                    'rating_percentage' => $submission->rating_percentage,
                    'total_score' => $submission->total_score,
                ];
            })->values();

            // Log the count for debugging
            Log::info('Submissions fetched', [
                'total_submissions' => $submissions->count(),
                'unique_students' => $students->count(),
                'course_code' => $validated['course_code'],
                'section_code' => $sectionCode,
                'term_id' => $validated['term_id'] ?? null
            ]);

            $courseDescription = $submissions->first()->course_description ?? $validated['course_description'] ?? null;

            return response()->json([
                'success' => true,
                'students' => $students,
                'course_description' => $courseDescription,
                'pagination' => [
                    'current_page' => $paginatedSubmissions->currentPage(),
                    'last_page' => $paginatedSubmissions->lastPage(),
                    'per_page' => $paginatedSubmissions->perPage(),
                    'total' => $paginatedSubmissions->total(),
                    'from' => $paginatedSubmissions->firstItem(),
                    'to' => $paginatedSubmissions->lastItem(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in getSubmissions: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch submissions: ' . $e->getMessage(),
                'students' => [],
                'total' => 0,
            ], 500);
        }
    }

    private function extractSectionCode(?string $yearSection): ?string
    {
        if (empty($yearSection)) {
            return null;
        }

        // Clean the input
        $yearSection = trim($yearSection);
        
        // Extract section code from patterns like "1-AM11" or "Year 1-AM11" or "3-AI31"
        if (preg_match('/-([A-Za-z0-9]+)$/', $yearSection, $matches)) {
            $sectionCode = trim($matches[1]);
            return $sectionCode !== '' ? $sectionCode : null;
        }
        
        // Handle patterns like "AM11" (just the section code)
        if (preg_match('/^[A-Za-z0-9]+$/', $yearSection)) {
            return $yearSection;
        }
        
        // Handle patterns like "Year 1 - AM11" with spaces
        if (preg_match('/-\s*([A-Za-z0-9]+)$/', $yearSection, $matches)) {
            $sectionCode = trim($matches[1]);
            return $sectionCode !== '' ? $sectionCode : null;
        }

        return null;
    }
}