import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Login({ status, canResetPassword }) {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        id_no: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <>
            <Head title="Login" />

            <div className="relative min-h-screen overflow-hidden bg-slate-950 text-slate-100">
                {/* Background Image */}
                <div
                    className="absolute inset-0 bg-cover bg-center"
                    style={{
                        backgroundImage: "url('/image/lnu_bg.jpg')",
                    }}
                ></div>

                {/* Dark Overlay */}
                <div className="absolute inset-0 bg-slate-950/70"></div>

                {/* Glow Effects */}
                <div className="pointer-events-none absolute -top-40 -left-40 h-96 w-96 rounded-full bg-cyan-400/20 blur-3xl"></div>

                <div className="pointer-events-none absolute -bottom-40 -right-20 h-[28rem] w-[28rem] rounded-full bg-blue-500/20 blur-3xl"></div>

                {/* Login Container */}
                <div className="relative mx-auto flex min-h-screen w-full max-w-md items-center px-4 py-8 md:px-6">
                    <section className="w-full rounded-3xl border border-slate-200 bg-white p-5 shadow-2xl shadow-slate-950/30 backdrop-blur-xl sm:p-6">

                        {/* Header */}
                        <div className="mb-6">
                            <img
                                src="/image/LNULogo.png"
                                alt="LNU Logo"
                                className="mx-auto mb-4 h-20 w-20 object-contain sm:h-24 sm:w-24"
                            />

                            <p className="text-center text-lg font-bold uppercase tracking-[0.10em] text-slate-800">
                                FIMS PORTAL
                            </p>

                            <h2 className="mt-1 text-center text-base font-semibold text-slate-900">
                                Login
                            </h2>
                        </div>

                        {/* Status Message */}
                        {status && (
                            <div className="mb-4 rounded-xl border border-green-200 bg-green-50 p-3 text-sm text-green-700">
                                {status}
                            </div>
                        )}

                        {/* Validation Errors */}
                        {(errors.id_no || errors.password) && (
                            <div className="mb-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                                {errors.id_no || errors.password}
                            </div>
                        )}

                        {/* Form */}
                        <form onSubmit={submit} className="space-y-4">

                            {/* User ID */}
                            <div>
                                <label
                                    htmlFor="id_no"
                                    className="mb-1 block text-sm font-semibold text-slate-700"
                                >
                                    User ID
                                </label>

                                <input
                                    id="id_no"
                                    type="text"
                                    name="id_no"
                                    value={data.id_no}
                                    autoComplete="username"
                                    autoFocus
                                    placeholder="e.g. IT-Faculty"
                                    onChange={(e) =>
                                        setData('id_no', e.target.value)
                                    }
                                    className="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-300/30"
                                />
                            </div>

                            {/* Password */}
                            <div>
                                <label
                                    htmlFor="password"
                                    className="mb-1 block text-sm font-semibold text-slate-700"
                                >
                                    Password
                                </label>

                                <div className="relative">
                                    <input
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        name="password"
                                        value={data.password}
                                        autoComplete="current-password"
                                        placeholder="Enter your password"
                                        onChange={(e) =>
                                            setData('password', e.target.value)
                                        }
                                        className="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 pr-12 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-300/30"
                                    />

                                    {/* Toggle Password */}
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setShowPassword(!showPassword)
                                        }
                                        className="absolute inset-y-0 right-0 inline-flex items-center px-3 text-slate-500 transition hover:text-slate-700"
                                    >
                                        {!showPassword ? (
                                            <svg
                                                viewBox="0 0 24 24"
                                                className="h-5 w-5"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="2"
                                            >
                                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z" />
                                                <circle
                                                    cx="12"
                                                    cy="12"
                                                    r="3"
                                                />
                                            </svg>
                                        ) : (
                                            <svg
                                                viewBox="0 0 24 24"
                                                className="h-5 w-5"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="2"
                                            >
                                                <path d="m3 3 18 18" />
                                                <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" />
                                                <path d="M9.9 5.1A9.8 9.8 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2" />
                                                <path d="M6.6 6.6C4.1 8.1 2.6 10.9 2 12c0 0 3.6 7 10 7a9.7 9.7 0 0 0 3.3-.6" />
                                            </svg>
                                        )}
                                    </button>
                                </div>
                            </div>

                            {/* Remember Me + Forgot Password */}
                            <div className="flex items-center justify-between">
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={data.remember}
                                        onChange={(e) =>
                                            setData(
                                                'remember',
                                                e.target.checked
                                            )
                                        }
                                        className="rounded border-slate-300 text-cyan-500 focus:ring-cyan-400"
                                    />

                                    <span className="text-sm text-slate-600">
                                        Remember me
                                    </span>
                                </label>

                                {canResetPassword && (
                                    <a
                                        href={route('password.request')}
                                        className="text-sm text-slate-600 underline hover:text-slate-900"
                                    >
                                        Forgot password?
                                    </a>
                                )}
                            </div>

                            {/* Submit Button */}
                            <button
                                type="submit"
                                disabled={processing}
                                className="mt-2 inline-flex w-full items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-70"
                            >
                                {processing ? 'Signing In...' : 'Sign In'}
                            </button>
                        </form>

                        {/* Footer */}
                        <div className="mt-9 border-t border-slate-200 pt-3 text-center text-xs text-slate-500">
                            <p className="font-semibold tracking-wide text-slate-700">
                                LNU FIMS
                            </p>

                            <p className="mt-0.5">
                                Developed by ITSO
                            </p>
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}