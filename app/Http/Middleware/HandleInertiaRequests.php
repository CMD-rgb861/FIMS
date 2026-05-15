<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $sharedUser = null;

        if ($user !== null) {
            $profilePhotoUrl = $user->personalInformation?->profile_photo_path
                ? asset('storage/' . $user->personalInformation->profile_photo_path)
                : null;

            $sharedUser = [
                    'id' => $user->id,
                    'id_no' => $user->id_no,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'middlename' => $user->middlename,
                    'extname' => $user->extname,
                    'profile_photo_url' => $profilePhotoUrl,

                    // NO METHOD CALLS (IMPORTANT)
                    'role' => $user->role ?? null,
                    'isAdmin' => $user->role === 'admin',
                    'isDean' => $user->role === 'dean',
                    'isUnitHead' => $user->role === 'unit_head',
                ];
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $sharedUser,
            ],
        ];
    }
}
