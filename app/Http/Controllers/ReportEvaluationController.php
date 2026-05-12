<?php

namespace App\Http\Controllers;

use App\Models\Poes\PoesEvalSubmissions;
use Illuminate\Http\Request;

class ReportEvaluationController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        // Get total_scores filtered by current instructor and subject
        $totalScores = PoesEvalSubmissions::query()
            ->where('instructor_id', $currentUser->id)
            ->pluck('total_score');

        return view('reports', [
            'totalScores' => $totalScores,
        ]);
    }
}