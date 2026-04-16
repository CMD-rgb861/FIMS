<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Login | {{ config('app.name', 'FIMS') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('image/LNULogo.png') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            /* Hide native password reveal controls so only the custom eye button appears. */
            #password::-ms-reveal,
            #password::-ms-clear {
                display: none;
            }
        </style>
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100">
        <div class="relative min-h-screen overflow-hidden">
            <div
                class="absolute inset-0 bg-cover bg-center"
                style="background-image: url('{{ asset('image/lnu_bg.jpg') }}');"
            ></div>
            <div class="absolute inset-0 bg-slate-950/70"></div>
            <div class="pointer-events-none absolute -top-40 -left-40 h-96 w-96 rounded-full bg-cyan-400/20 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-40 -right-20 h-[28rem] w-[28rem] rounded-full bg-blue-500/20 blur-3xl"></div>

            <div class="relative mx-auto flex min-h-screen w-full max-w-md items-center px-4 py-8 md:px-6">
                <section class="w-full rounded-3xl border border-slate-200 bg-white p-5 shadow-2xl shadow-slate-950/30 backdrop-blur-xl sm:p-6">
                    <div class="mb-6">
                        <img
                            src="{{ asset('image/LNULogo.png') }}"
                            alt="LNU Logo"
                            class="mx-auto mb-4 h-20 w-20 object-contain sm:h-24 sm:w-24"
                        >
                        <p class="text-center text-lg font-bold uppercase tracking-[0.28em] text-slate-800">FIMS PORTAL</p>
                        <h2 class="mt-2 text-center text-xl font-normal text-slate-900">Login</h2>
                    </div>

                    @if ($errors->any())
                        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
                        @csrf

                        <div>
                            <label for="id_no" class="mb-1 block text-sm font-semibold text-slate-700">ID Number</label>
                            <input
                                id="id_no"
                                name="id_no"
                                type="text"
                                value="{{ old('id_no') }}"
                                required
                                autofocus
                                class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-300/30"
                                placeholder="e.g. 2026-0001"
                            >
                        </div>

                        <div>
                            <label for="password" class="mb-1 block text-sm font-semibold text-slate-700">Password</label>
                            <div class="relative">
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    required
                                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 pr-12 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-300/30"
                                    placeholder="Enter your password"
                                >
                                <button
                                    type="button"
                                    id="togglePassword"
                                    class="absolute inset-y-0 right-0 inline-flex items-center px-3 text-slate-500 transition hover:text-slate-700"
                                    aria-label="Show password"
                                    aria-pressed="false"
                                >
                                    <svg id="eyeOpen" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                    <svg id="eyeClosed" viewBox="0 0 24 24" class="hidden h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="m3 3 18 18" />
                                        <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" />
                                        <path d="M9.9 5.1A9.8 9.8 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2" />
                                        <path d="M6.6 6.6C4.1 8.1 2.6 10.9 2 12c0 0 3.6 7 10 7a9.7 9.7 0 0 0 3.3-.6" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="mt-2 inline-flex w-full items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300"
                        >
                            Sign In
                        </button>
                    </form>

                    <div class="mt-5 border-t border-slate-200 pt-3 text-center text-xs text-slate-500">
                        <p class="font-semibold tracking-wide text-slate-700">LNU FIMS</p>
                        <p class="mt-0.5">Developed by ITSO Intern</p>
                    </div>
                </section>
            </div>
        </div>

        <script>
            const passwordInput = document.getElementById('password');
            const togglePasswordButton = document.getElementById('togglePassword');
            const eyeOpen = document.getElementById('eyeOpen');
            const eyeClosed = document.getElementById('eyeClosed');

            if (passwordInput && togglePasswordButton && eyeOpen && eyeClosed) {
                togglePasswordButton.addEventListener('click', function () {
                    const showingPassword = passwordInput.type === 'text';

                    passwordInput.type = showingPassword ? 'password' : 'text';
                    eyeOpen.classList.toggle('hidden', !showingPassword);
                    eyeClosed.classList.toggle('hidden', showingPassword);
                    togglePasswordButton.setAttribute('aria-label', showingPassword ? 'Show password' : 'Hide password');
                    togglePasswordButton.setAttribute('aria-pressed', String(!showingPassword));
                });
            }
        </script>
    </body>
</html>
