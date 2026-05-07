import React, { useMemo, useState } from 'react';
import Sidebar from '../components/Sidebar';

export default function DashboardPage({
    appName = 'FIMS',
    dashboardUrl = '/dashboard',
    subjectsUrl = '/subjects',
    evaluationUrl = '/evaluation',
    reportsUrl = '/reports',
    profileUrl = '/my-profile',
    accountSettingsUrl = '/account-settings',
    logoutUrl = '/logout',
    csrfToken = '',
    user = null,
    gradeSummaryCards = [],
    unitHeadEvaluationRating = 'N/A',
    unitHeadEvaluationHelper = 'Unit Head evaluation rating will appear once submitted.',
    hasPendingEvaluations = false,
    canAccessEvaluation = true,
}) {
    const role = String(user?.role ?? '').toLowerCase();
    const isAdmin = role === 'admin' || user?.isAdmin === true;
    const isUnitHead = user?.isUnitHead ?? false;
    const gradeCard = gradeSummaryCards.find(
        (card) =>
            String(card.label || '').toLowerCase().includes('unit head grade') ||
            String(card.label || '').toLowerCase().includes('faculty grade')
    );

    const averageGradeCard =
        gradeSummaryCards.find((card) => String(card.label || '').toLowerCase() === 'average grade') ||
        gradeSummaryCards.find((card) => String(card.label || '').toLowerCase().includes('average'));

    const summaryCards = [
        {
            label: isAdmin ? 'Admin Access' : isUnitHead ? 'Unit Head Grade' : 'Faculty Grade',
            value: gradeCard?.value ?? 'N/A',
            helper: gradeCard?.helper ?? (
                isAdmin
                    ? 'System administration access is enabled.'
                    : isUnitHead
                        ? 'No grade issued yet.'
                        : 'No grade received yet.'
            ),
        },
        {
            label: 'Evaluation',
            value: unitHeadEvaluationRating,
            helper: unitHeadEvaluationHelper,
        },
        {
            label: 'Average Grade',
            value: averageGradeCard?.value ?? 'N/A',
            helper: averageGradeCard?.helper ?? 'Computed average grade for the current term.',
        },
    ];

    return (
        <div className="min-h-screen flex bg-slate-50 text-slate-900">
            <Sidebar
                user={user}
                appName={appName}
                dashboardUrl={dashboardUrl}
                subjectsUrl={subjectsUrl}
                evaluationUrl={evaluationUrl}
                reportsUrl={reportsUrl}
                profileUrl={profileUrl}
                accountSettingsUrl={accountSettingsUrl}
                activePage="dashboard"
                logoutUrl={logoutUrl}
                csrfToken={csrfToken}
                hasPendingEvaluations={hasPendingEvaluations}
                canAccessEvaluation={canAccessEvaluation}
            />

            <main className="flex-1 overflow-y-auto p-6">
                <section className="relative overflow-hidden rounded-3xl border border-slate-200 bg-slate-950 px-6 py-7 text-white shadow-xl shadow-slate-900/10 sm:px-8">
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_rgba(125,211,252,0.24),_transparent_38%),radial-gradient(circle_at_bottom_left,_rgba(148,163,184,0.18),_transparent_35%)]" />
                    <div className="relative">
                        <p className="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-200/90">
                            {isAdmin ? 'Admin dashboard' : 'Faculty dashboard'}
                        </p>
                        <h1 className="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                            {isAdmin
                                ? 'Manage the system and review operational status in one place.'
                                : 'Your grades, evaluations, and progress in one place.'}
                        </h1>
                        <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-300">
                            {isAdmin
                                ? 'This account is recognized as an administrator. Evaluation pages are still Unit Head-only unless you add admin access rules.'
                                : 'Track the grades issued by your unit head, review recent evaluation activity, and quickly see where you stand for the current term.'}
                        </p>
                        <div className="mt-6 grid gap-3 sm:grid-cols-3">
                            {summaryCards.map((card) => (
                                <div
                                    key={card.label}
                                    className={`rounded-2xl px-4 py-4 backdrop-blur-sm ${
                                        card.label === 'Average Grade'
                                            ? 'border border-cyan-300/30 bg-cyan-300/10'
                                            : 'border border-white/10 bg-white/8'
                                    }`}
                                >
                                    <p
                                        className={`text-[11px] uppercase tracking-[0.22em] ${
                                            card.label === 'Average Grade' ? 'text-cyan-100' : 'text-slate-300'
                                        }`}
                                    >
                                        {card.label}
                                    </p>
                                    <p className="mt-2 text-2xl font-semibold text-white">{card.value}</p>
                                    <p
                                        className={`mt-2 text-xs leading-5 ${
                                            card.label === 'Average Grade' ? 'text-cyan-100' : 'text-slate-300'
                                        }`}
                                    >
                                        {card.helper}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>
            </main>
        </div>
    );
}