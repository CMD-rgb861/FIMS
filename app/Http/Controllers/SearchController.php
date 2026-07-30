<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


// Search Bar for all the Printing Modals (IFE, SEF, and SET)
class SearchController extends Controller
{
    /**
     * Search faculty by name or ID for SEF/IFE modals
     */
    public function searchFaculty(Request $request)
    {
        $validated = $request->validate([
            'term_id' => 'nullable|string',
            'search' => 'required|string|min:1',
            'faculty_ids' => 'required|array'
        ]);
        
        $search = $validated['search'];
        $facultyIds = $validated['faculty_ids'];
        
        $results = User::query()
            ->whereIn('id_no', $facultyIds)
            ->where(function($query) use ($search) {
                $query->where('firstname', 'like', '%' . $search . '%')
                    ->orWhere('lastname', 'like', '%' . $search . '%')
                    ->orWhere('id_no', 'like', '%' . $search . '%')
                    ->orWhere(DB::raw("CONCAT(firstname, ' ', lastname)"), 'like', '%' . $search . '%');
            })
            ->select('id_no as employee_id_no', 'firstname', 'lastname')
            ->get()
            ->map(function($user) {
                return [
                    'employee_id_no' => $user->employee_id_no,
                    'instructor' => trim($user->firstname . ' ' . $user->lastname),
                ];
            });
        
        return response()->json($results);
    }

    /**
     * Search subjects for SET modal
     */
    public function searchSubjects(Request $request)
    {
        $validated = $request->validate([
            'search' => 'required|string|min:1',
            'subjects' => 'required|array'
        ]);
        
        $search = strtolower($validated['search']);
        $subjects = $validated['subjects'];
        
        // Client-side filtering (since subjects are already loaded)
        $filtered = array_filter($subjects, function($subject) use ($search) {
            return stripos($subject['course_code'] ?? '', $search) !== false ||
                   stripos($subject['course_description'] ?? '', $search) !== false ||
                   stripos($subject['year_section'] ?? '', $search) !== false;
        });
        
        return response()->json(array_values($filtered));
    }
}