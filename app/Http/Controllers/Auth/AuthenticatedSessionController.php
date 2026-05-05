<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'id_no' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $idNo = trim($credentials['id_no']);
        $inputPassword = $credentials['password'];

        $user = User::query()
            ->where('id_no', $idNo)
            ->first();

        $storedPassword = (string) ($user->password ?? '');
        $passwordMatches = $storedPassword !== ''
            && (Hash::check($inputPassword, $storedPassword) || hash_equals($storedPassword, $inputPassword));

        if (! $user || ! $passwordMatches) {
            return back()
                ->withErrors(['id_no' => 'The provided credentials are incorrect.'])
                ->onlyInput('id_no');
        }

        if (hash_equals($storedPassword, $inputPassword)) {
            $user->forceFill(['password' => Hash::make($inputPassword)])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();

        $isUnitHead = $user->isUnitHead();

        $request->session()->put('fims_id_no', $user->id_no);
        $request->session()->put('fims_role', $isUnitHead ? 'unit_head' : 'faculty');

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
