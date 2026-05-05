<?php

namespace App\Http\Controllers;

use App\Models\PersonalInformation;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    private const TOTAL_INSTRUCTORS = 4;

    public function edit(Request $request): View
    {
        $user = $request->user();
        $personalInformation = $user?->personalInformation;
        $canAccessEvaluation = $this->canAccessEvaluation((string) $user->id_no);
        $profilePhotoUrl = $personalInformation?->profile_photo_path
            ? asset('storage/' . $personalInformation->profile_photo_path)
            : null;
        $hasPendingEvaluations = $this->hasPendingEvaluations((int) $user->id);

        $profileProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('profile.edit'),
            'accountSettingsUrl' => route('account.settings.edit'),
            'profileUpdateUrl' => route('profile.update'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'status' => session('status'),
            'errors' => session('errors') ? session('errors')->toArray() : [],
            'oldInput' => [
                'id_no' => old('id_no'),
                'firstname' => old('firstname'),
                'lastname' => old('lastname'),
                'middlename' => old('middlename'),
                'extname' => old('extname'),
                'dob' => old('dob'),
                'sex' => old('sex'),
                'civil_status' => old('civil_status'),
                'email' => old('email'),
                'contact_no' => old('contact_no'),
            ],
            'canAccessEvaluation' => $canAccessEvaluation,
            'hasPendingEvaluations' => $hasPendingEvaluations,
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
                'profile_photo_url' => $profilePhotoUrl,
            ],
        ];

        return view('profile', [
            'profileProps' => $profileProps,
        ]);
    }

    public function accountSettings(Request $request): View
    {
        $user = $request->user();
        $personalInformation = $user?->personalInformation;
        $canAccessEvaluation = $this->canAccessEvaluation((string) $user->id_no);
        $profilePhotoUrl = $personalInformation?->profile_photo_path
            ? asset('storage/' . $personalInformation->profile_photo_path)
            : null;
        $hasPendingEvaluations = $this->hasPendingEvaluations((int) $user->id);

        $accountSettingsProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('profile.edit'),
            'accountSettingsUrl' => route('account.settings.edit'),
            'accountSettingsUpdateUrl' => route('account.settings.update'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'status' => session('status'),
            'errors' => session('errors') ? session('errors')->toArray() : [],
            'oldInput' => [
                'email' => old('email'),
            ],
            'canAccessEvaluation' => $canAccessEvaluation,
            'hasPendingEvaluations' => $hasPendingEvaluations,
            'user' => [
                'id_no' => $user?->id_no,
                'firstname' => $user?->firstname,
                'lastname' => $user?->lastname,
                'email' => $personalInformation?->email,
                'profile_photo_url' => $profilePhotoUrl,
            ],
        ];

        return view('account-settings', [
            'accountSettingsProps' => $accountSettingsProps,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $personalInformation = $user->personalInformation;

        $validatedUser = $request->validate([
            'id_no' => ['required', 'string', 'max:50', 'unique:users,id_no,' . $user->id],
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'extname' => ['nullable', 'string', 'max:50'],
            'dob' => ['nullable', 'date'],
            'sex' => ['nullable', Rule::in(['male', 'female'])],
            'civil_status' => ['nullable', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('personal_informations', 'email')->ignore($personalInformation?->id),
            ],
            'contact_no' => ['nullable', 'string', 'max:30'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $profilePhotoPath = $personalInformation?->profile_photo_path;

        if ($request->hasFile('profile_photo')) {
            $storedPath = $request->file('profile_photo')->store('profile-photos', 'public');

            if ($profilePhotoPath && Storage::disk('public')->exists($profilePhotoPath)) {
                Storage::disk('public')->delete($profilePhotoPath);
            }

            $profilePhotoPath = $storedPath;
        }

        $user->fill([
            'id_no' => $validatedUser['id_no'],
            'firstname' => $validatedUser['firstname'],
            'lastname' => $validatedUser['lastname'],
            'middlename' => $validatedUser['middlename'] ?? null,
            'extname' => $validatedUser['extname'] ?? null,
        ]);
        $user->save();

        PersonalInformation::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'dob' => $validatedUser['dob'] ?? null,
                'sex' => $validatedUser['sex'] ?? null,
                'civil_status' => $validatedUser['civil_status'] ?? null,
                'email' => $validatedUser['email'],
                'contact_no' => $validatedUser['contact_no'] ?? null,
                'profile_photo_path' => $profilePhotoPath,
            ]
        );

        return redirect()
            ->route('profile.edit')
            ->with('status', 'Profile updated successfully.');
    }

    public function updateAccountSettings(Request $request): RedirectResponse
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

        $updatedPassword = !empty($validated['password']);

        DB::transaction(function () use ($user, $personalInformation, $validated, $updatedPassword) {
            PersonalInformation::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['email' => $validated['email']]
            );

            if ($updatedPassword) {
                $user->forceFill([
                    'password' => Hash::make($validated['password']),
                ])->save();
            }
        });

        return redirect()
            ->route('account.settings.edit')
            ->with('status', $updatedPassword
                ? 'Account settings updated successfully. Your password has been changed.'
                : 'Account settings updated successfully.');
    }

    private function hasPendingEvaluations(int $userId): bool
    {
        $evaluatedCount = SupervisorEvaluationSubmission::query()
            ->where('user_id', $userId)
            ->distinct('instructor')
            ->count('instructor');

        return $evaluatedCount < self::TOTAL_INSTRUCTORS;
    }

    private function canAccessEvaluation(string $idNo): bool
    {
        return User::query()
            ->where('id_no', $idNo)
            ->first()?->isUnitHead() === true;
    }
}