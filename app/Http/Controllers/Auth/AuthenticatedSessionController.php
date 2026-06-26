<?php
// app/Http/Controllers/Auth/AuthenticatedSessionController.php (FIMS App)

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\SsoToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => false,
            'status' => session('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        return redirect()->intended(route('home', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        // ===== DELETE THE SSO TOKEN ON LOGOUT =====
        $ssoToken = session('sso_token');
        
        if ($ssoToken) {
            // Delete the token from sso_tokens table
            SsoToken::where('token', $ssoToken)->delete();
            
            // Clear SSO token from session
            session()->forget('sso_token');
        }
        
        // Logout from FIMS
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Redirect to SSO Dashboard
        return redirect()->away('https://10.5.70.45/ids/fims/home/n');
    }
}