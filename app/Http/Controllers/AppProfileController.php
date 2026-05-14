<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AppProfileController extends Controller
{
    use FacultyData;
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $personalInformation = $user?->personalInformation;

        return Inertia::render('ProfilePage', $this->commonInertiaProps($user, [
            'profileUpdateUrl' => route('my-profile.update'),
            'status' => session('status', ''),
            'user' => [
                'id_no' => $user?->id_no,
                'firstname' => $user?->firstname,
                'lastname' => $user?->lastname,
                'middlename' => $user?->middlename,
                'extname' => $user?->extname,
                'dob' => $personalInformation?->dob?->format('Y-m-d'),
                'sex' => $personalInformation?->sex,
                'civil_status' => $personalInformation?->civil_status,
                'email' => $personalInformation?->email,
                'contact_no' => $personalInformation?->contact_no,
                'profile_photo_url' => $personalInformation?->profile_photo_path
                    ? asset('storage/' . $personalInformation->profile_photo_path)
                    : null,
            ],
            'oldInput' => $request->session()->get('_old_input', []),
            'hasPendingEvaluations' => false,
        ]));
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $personalInformation = $user->personalInformation;

        $validated = $request->validate([
            'id_no' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'id_no')->ignore($user->id),
            ],
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'extname' => ['nullable', 'string', 'max:255'],
            'dob' => ['nullable', 'date'],
            'sex' => ['nullable', Rule::in(['male', 'female'])],
            'civil_status' => ['nullable', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('personal_informations', 'email')->ignore($personalInformation?->id),
            ],
            'contact_no' => ['nullable', 'string', 'max:50'],
            'profile_photo' => ['nullable', 'image', 'max:2048'],
        ]);

        $user->fill([
            'id_no' => $validated['id_no'],
            'firstname' => $validated['firstname'],
            'lastname' => $validated['lastname'],
            'middlename' => $validated['middlename'] ?? null,
            'extname' => $validated['extname'] ?? null,
        ])->save();

        $profilePhotoPath = $personalInformation?->profile_photo_path;

        if ($request->hasFile('profile_photo')) {
            if ($profilePhotoPath) {
                Storage::disk('public')->delete($profilePhotoPath);
            }

            $profilePhotoPath = $request->file('profile_photo')->store('profile-photos', 'public');
        }

        $user->personalInformation()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'dob' => $validated['dob'] ?? null,
                'sex' => $validated['sex'] ?? null,
                'civil_status' => $validated['civil_status'] ?? null,
                'email' => $validated['email'] ?? $personalInformation?->email,
                'contact_no' => $validated['contact_no'] ?? null,
                'profile_photo_path' => $profilePhotoPath,
            ]
        );

        return back()->with('status', 'Profile updated successfully.');
    }

    public function accountSettingsEdit(Request $request): Response
    {
        $user = $request->user();
        $personalInformation = $user?->personalInformation;

        return Inertia::render('AccountSettingsPage', $this->commonInertiaProps($user, [
            'accountSettingsUpdateUrl' => route('account-settings.update'),
            'status' => session('status', ''),
            'user' => [
                'email' => $personalInformation?->email,
            ],
            'oldInput' => $request->session()->get('_old_input', []),
            'hasPendingEvaluations' => false,
        ]));
    }

    public function accountSettingsUpdate(Request $request): RedirectResponse
    {
        $user = $request->user();
        $personalInformation = $user->personalInformation;

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('personal_informations', 'email')->ignore($personalInformation?->id),
            ],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $user->personalInformation()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'email' => $validated['email'],
            ]
        );

        if (! empty($validated['password'])) {
            $user->forceFill([
                'password' => Hash::make($validated['password']),
            ])->save();
        }

        return back()->with('status', 'Account settings updated successfully.');
    }
}
