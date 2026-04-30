import React from 'react';
import { createRoot } from 'react-dom/client';
import DashboardPage from './pages/DashboardPage';
import EvaluationPage from './pages/EvaluationPage';
import ProfilePage from './pages/ProfilePage';
import AccountSettingsPage from './pages/AccountSettingsPage';
import SubjectsPage from './pages/SubjectsPage';
import ReportsPage from './pages/ReportsPage';
import FacultyReportPage from './pages/FacultyReportPage';

const dashboardRoot = document.getElementById('dashboard-root');
const evaluationRoot = document.getElementById('evaluation-root');
const profileRoot = document.getElementById('profile-root');
const accountSettingsRoot = document.getElementById('account-settings-root');
const subjectsRoot = document.getElementById('subjects-root');
const reportsRoot = document.getElementById('reports-root');
const facultyReportRoot = document.getElementById('faculty-report-root');

function parseProps(scriptId) {
	const propsScript = document.getElementById(scriptId);
	let props = {};

	try {
		props = propsScript ? JSON.parse(propsScript.textContent || '{}') : {};
	} catch (error) {
		console.error(`Failed to parse ${scriptId}:`, error);
		props = {};
	}

	return props;
}

if (dashboardRoot) {
	const props = parseProps('dashboard-props');

	createRoot(dashboardRoot).render(
		React.createElement(
			React.StrictMode,
			null,
			React.createElement(DashboardPage, props)
		)
	);
}

if (evaluationRoot) {
	const props = parseProps('evaluation-props');

	createRoot(evaluationRoot).render(
		React.createElement(
			React.StrictMode,
			null,
			React.createElement(EvaluationPage, props)
		)
	);
}

if (profileRoot) {
	const props = parseProps('profile-props');

	createRoot(profileRoot).render(
		React.createElement(
			React.StrictMode,
			null,
			React.createElement(ProfilePage, props)
		)
	);
}

if (accountSettingsRoot) {
	const props = parseProps('account-settings-props');

	createRoot(accountSettingsRoot).render(
		React.createElement(
			React.StrictMode,
			null,
			React.createElement(AccountSettingsPage, props)
		)
	);
}

if (subjectsRoot) {
	const props = parseProps('subjects-props');

	createRoot(subjectsRoot).render(
		React.createElement(
			React.StrictMode,
			null,
			React.createElement(SubjectsPage, props)
		)
	);
}

if (reportsRoot) {
	const props = parseProps('reports-props');

	createRoot(reportsRoot).render(
		React.createElement(
			React.StrictMode,
			null,
			React.createElement(ReportsPage, props)
		)
	);
}

if (facultyReportRoot) {
	const props = parseProps('faculty-report-props');

	createRoot(facultyReportRoot).render(
		React.createElement(
			React.StrictMode,
			null,
			React.createElement(FacultyReportPage, props)
		)
	);
}
