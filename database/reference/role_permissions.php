<?php

declare(strict_types=1);

/*
 * Default role bundles (Database V1.4 §5.1.5), seeded from API V1.3.1 §6.5.
 *
 * Only entries marked ✅ [A] in API §6.5 are seeded, plus the AC-4 contact field codes for
 * Full Admin (AC-4 approved). Everything else is left out, so it stays pending:
 *
 *  - [R] entries in API §6.5 (the API's approval scope keeps [R] items as recommendations):
 *    Full Admin dashboard.monitoring; Full Admin and Monitoring Admin review_criteria.view;
 *    Full Admin reviews.view_submitted (R4/R5).
 *  - Interim restrictions [IFC]: Full Admin fields.sensitive.* and confidential.* (R6, R17);
 *    Monitoring Admin fields.contact.* (R13), reports.<type>.view (R16), browsing (R14),
 *    certificates (R15); approved-hours correction/void (W3, R9); review authoring (R4);
 *    periods.reopen (W6); exports.format.csv (A12); operational audit (R10).
 *  - Pending ownership: settings.operational.* (S1), certificate templates/settings (S2),
 *    review_criteria management (S3), retention.* (S4).
 *  - Governance codes come only from System Owner membership, never from a role [A].
 *
 * Adding an approved entry later is reference data only (a new migration), not a schema change.
 * Part of a released migration: never edit.
 *
 * @return array<string, list<string>> role code => permission codes
 */

$notifications = ['self.notifications.view', 'self.notifications.mark_read'];

return [
    'volunteer' => [
        'self.dashboard.view', 'self.profile.view', 'self.profile.edit', 'self.projects.view',
        'self.tasks.view', 'self.tasks.progress',
        'self.reports.view', 'self.reports.create', 'self.reports.edit', 'self.reports.submit',
        'self.reports.attachments.add', 'self.reports.attachments.remove',
        'self.hours.view', 'self.hours.submit', 'self.hours.edit', 'self.hours.withdraw',
        'self.reviews.view', 'self.reviews.acknowledge',
        'self.periods.view', 'self.certificates.view', 'self.certificates.download',
        ...$notifications,
    ],
    'full_admin' => [
        ...$notifications,
        'volunteers.browse', 'volunteers.view', 'volunteers.create', 'volunteers.edit', 'volunteers.accept', 'volunteers.status.change',
        'fields.contact.email', 'fields.contact.phone',
        'projects.browse', 'projects.view', 'projects.create', 'projects.edit', 'projects.status.change',
        'project_activities.create', 'project_activities.edit', 'project_activities.deactivate',
        'project_staff.add', 'project_staff.remove',
        'assignments.browse', 'assignments.create', 'assignments.edit', 'assignments.end',
        'tasks.browse', 'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.assign', 'tasks.status.change',
        'task_reports.view', 'task_reports.decide',
        'hours.view', 'hours.decide',
        'periods.browse', 'periods.view', 'periods.eligibility.view', 'periods.open', 'periods.edit', 'periods.complete',
        'certificates.view', 'certificates.download', 'certificates.issue', 'certificates.revoke', 'certificates.replace',
        'dashboard.operations',
        'reports.volunteers.view', 'reports.participation.view', 'reports.hours.view', 'reports.task_reports.view',
        'reports.reviews.view', 'reports.progress.view', 'reports.periods.view', 'reports.certificates.view', 'reports.kpis.view',
        'exports.create', 'exports.format.xlsx', 'exports.format.pdf',
    ],
    'monitoring_admin' => [
        ...$notifications,
        'task_reports.view', 'task_reports.decide',
        'hours.view', 'hours.decide',
        'reviews.view_submitted', 'reviews.approve', 'reviews.publish',
        'dashboard.monitoring',
        'exports.create', 'exports.format.xlsx', 'exports.format.pdf',
    ],
    'technical_manager' => [
        ...$notifications,
        'accounts.view', 'accounts.create', 'accounts.edit', 'accounts.provision',
        'accounts.suspend', 'accounts.reactivate', 'accounts.deactivate', 'accounts.reset.initiate',
        'invitations.send', 'invitations.cancel',
        'roles.view', 'roles.assign_ordinary', 'roles.remove_ordinary',
        'grants.view', 'grants.create', 'grants.revoke',
        'sessions.view', 'sessions.revoke',
        'settings.technical.view', 'settings.technical.edit', 'system.health',
        'dashboard.technical', 'audit.security.view', 'audit.verify',
    ],
];
