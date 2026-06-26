<?php
// app/Http/Controllers/Auth/SsoController.php (FIMS App)

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SsoToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SsoController extends Controller
{
    public function validateToken(Request $request)
    {
        $token = $request->query('sso_token');
        $id_no = $request->query('id_no');

        if (!$token || !$id_no) {
            Log::warning('SSO validation: Missing parameters');
            return redirect()->route('home')->withErrors([
                'error' => 'Missing SSO credentials.'
            ]);
        }

        // Clean up any expired tokens first
        SsoToken::where('expires_at', '<', now())->delete();

        // Check if token already exists and is valid
        $ssoToken = SsoToken::where('token', $token)
            ->where('id_no', $id_no)
            ->first();

        if(!$ssoToken) {
            Log::warning('SSO validation: Invalid token', ['token' => $token, 'id_no' => $id_no]);
            return redirect()->route('home')->withErrors([
                'error' => 'Invalid SSO token. Please return to SSO and try again.'
            ]);
        }

            // Token exists - check if expired
        if ($ssoToken->expires_at->isPast()) {
                // Delete expired token
            $ssoToken->delete();
                
            return redirect()->route('home')->withErrors([
                'error' => 'SSO token has expired. Please return to SSO and try again.'
            ]);
        } 

        // Find user in FIMS
        $user = User::where('id_no', $id_no)->first();


        $college = $request->query('college');
        $unit = $request->query('unit');


        if (!$user) {
            Log::warning('SSO validation: User not found', ['id_no' => $id_no]);
            
            // Delete the token since validation failed
            $ssoToken->delete();
            
            return redirect()->route('home')->withErrors([
                'error' => 'User not found in this system.'
            ]);
        }

        // Update user's selected college/unit
        if (!empty($college) && !empty($unit)) {
            $user->update([
                'college_id' => $college,
                'unit_id' => $unit,
            ]);
        }

        // Store token in session for later cleanup
        session(['sso_token' => $token]);

        SsoToken::where('id_no', $id_no)->delete();

        // Auto-login the user
        Auth::login($user);
        $request->session()->regenerate();

        Log::info('SSO authentication successful', [
            'user_id' => $user->id,
            'id_no' => $id_no,
            'token' => substr($token, 0, 10) . '...'
        ]);

        return redirect()->intended(route('dashboard'));
    }
}