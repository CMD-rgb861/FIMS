export function normalizeRole(role) {
    return String(role ?? '').toLowerCase();
}

export function isAdminRole(role) {
    return normalizeRole(role) === 'admin';
}

export function isDeanRole(role) {
    return normalizeRole(role) === 'dean';
}

export function isAssociateDeanRole(role) {
    return normalizeRole(role) === 'associate_dean';
}

export function isUnitHeadRole(role) {
    return normalizeRole(role) === 'unit_head';
}

export function isFacultyRole(role) {
    return normalizeRole(role) === 'faculty';
}

export function getRoleLabel(role) {
    const normalizedRole = normalizeRole(role);

    if (normalizedRole === 'admin') {
        return 'Admin';
    }

    if (normalizedRole === 'dean') {
        return 'Dean';
    }

    if (normalizedRole === 'associate_dean') {
        return 'Associate Dean';
    }

    if (normalizedRole === 'unit_head') {
        return 'Unit Head';
    }

    return 'Faculty';
}