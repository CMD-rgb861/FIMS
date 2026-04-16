import React from 'react';
import { createRoot } from 'react-dom/client';
import DashboardPage from './pages/DashboardPage';
import EvaluationPage from './pages/EvaluationPage';
import ProfilePage from './pages/ProfilePage';

const dashboardRoot = document.getElementById('dashboard-root');
const evaluationRoot = document.getElementById('evaluation-root');
const profileRoot = document.getElementById('profile-root');

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
