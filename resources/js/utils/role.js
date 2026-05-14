export function normalizeRole(role) {
    return String(role ?? '').toLowerCase();
}

export function isAdminRole(role) {
    return normalizeRole(role) === 'admin';
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

    if (normalizedRole === 'unit_head') {
        return 'Unit Head';
    }

    if (normalizedRole === 'dean') {
        return 'Dean';
    }

    return 'Faculty';
}