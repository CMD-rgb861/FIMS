<?php

namespace App\Http\Controllers;

use App\Models\PersonalInformation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();
        $personalInformation = $user?->personalInformation;

        $profileProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'evaluationUrl' => route('evaluation'),
            'profileUrl' => route('profile.edit'),
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
            ],
        ];

        return view('profile', [
            'profileProps' => $profileProps,
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
        ]);

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
            ]
        );

        return redirect()
            ->route('profile.edit')
            ->with('status', 'Profile updated successfully.');
    }
}