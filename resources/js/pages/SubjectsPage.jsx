import React from 'react';
import Sidebar from '../components/Sidebar';

export default function SubjectsPage({
    appName = 'FIMS',
    dashboardUrl = '/dashboard',
    subjectsUrl = '/subjects',
    evaluationUrl = '/evaluation',
    profileUrl = '/my-profile',
    accountSettingsUrl = '/account-settings',
    logoutUrl = '/logout',
    csrfToken = '',
    user = null,
    subjects = [],
    hasPendingEvaluations = false,
}) {
    return (
        <div className="min-h-screen flex bg-slate-50 text-slate-900">
            <Sidebar
                user={user}
                appName={appName}
                dashboardUrl={dashboardUrl}
                subjectsUrl={subjectsUrl}
                evaluationUrl={evaluationUrl}
                profileUrl={profileUrl}
                accountSettingsUrl={accountSettingsUrl}
                activePage="subjects"
                logoutUrl={logoutUrl}
                csrfToken={csrfToken}
                hasPendingEvaluations={hasPendingEvaluations}
            />

            <main className="flex-1 p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold tracking-tight">Subjects</h1>
                    <p className="mt-1 text-sm text-slate-500">All assigned subjects for this term.</p>
                </div>

                <section className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-5 py-3 text-left font-semibold text-slate-600">Code</th>
                                <th className="px-5 py-3 text-left font-semibold text-slate-600">Subject Title</th>
                                <th className="px-5 py-3 text-left font-semibold text-slate-600">Instructor</th>
                                <th className="px-5 py-3 text-left font-semibold text-slate-600">Term</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200 bg-white">
                            {subjects.length > 0 ? (
                                subjects.map((subject, index) => (
                                    <tr key={`${subject.code}-${subject.instructor}-${index}`}>
                                        <td className="px-5 py-3 text-slate-900 font-medium">{subject.code}</td>
                                        <td className="px-5 py-3 text-slate-700">{subject.title}</td>
                                        <td className="px-5 py-3 text-slate-700">{subject.instructor}</td>
                                        <td className="px-5 py-3 text-slate-700">{subject.term}</td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={4} className="px-5 py-8 text-center text-slate-500">
                                        No subjects available.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </section>
            </main>
        </div>
    );
}
