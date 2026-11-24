<?php

declare(strict_types=1);

/*
 * Field catalogue (Database V1.4 §5.1.6; D3 approved; AC-4 approved).
 *
 * - basic: no field code; readable by anyone who may see the record (API §6.7).
 * - contact: volunteers.email and volunteers.phone, each with its own code (AC-4).
 * - confidential: the three note sets with their API §6.4 view and create/write codes.
 * - Operational profile (skills, languages, interests, availability) is basic-level operational
 *   data, recorded as 'interim' because that classification is an API [R] item (API §7.4).
 * - volunteer_sensitive_details columns are deliberately NOT catalogued: their per-field codes are
 *   pending (R6/D3), and an uncatalogued personal column is treated as sensitive (fail-closed).
 *
 * Part of a released migration: never edit. Change reference data with a new migration.
 *
 * @return list<array{table_name: string, column_name: string, field_set: string, access_code: ?string, write_code: ?string, classification_status: string, description: string}>
 */

return [
    ['table_name' => 'volunteers', 'column_name' => 'reference', 'field_set' => 'basic', 'access_code' => null, 'write_code' => null, 'classification_status' => 'approved', 'description' => 'Volunteer reference number'],
    ['table_name' => 'volunteers', 'column_name' => 'full_name', 'field_set' => 'basic', 'access_code' => null, 'write_code' => null, 'classification_status' => 'approved', 'description' => 'Full name'],
    ['table_name' => 'volunteers', 'column_name' => 'preferred_name', 'field_set' => 'basic', 'access_code' => null, 'write_code' => null, 'classification_status' => 'approved', 'description' => 'Preferred name'],
    ['table_name' => 'volunteers', 'column_name' => 'status', 'field_set' => 'basic', 'access_code' => null, 'write_code' => null, 'classification_status' => 'approved', 'description' => 'Operational status'],
    ['table_name' => 'volunteers', 'column_name' => 'email', 'field_set' => 'contact', 'access_code' => 'fields.contact.email', 'write_code' => 'fields.contact.email', 'classification_status' => 'approved', 'description' => 'Contact email address (AC-4)'],
    ['table_name' => 'volunteers', 'column_name' => 'phone', 'field_set' => 'contact', 'access_code' => 'fields.contact.phone', 'write_code' => 'fields.contact.phone', 'classification_status' => 'approved', 'description' => 'Contact phone number (AC-4)'],
    ['table_name' => 'volunteers', 'column_name' => 'skills', 'field_set' => 'basic', 'access_code' => null, 'write_code' => null, 'classification_status' => 'interim', 'description' => 'Skills (operational profile)'],
    ['table_name' => 'volunteers', 'column_name' => 'languages', 'field_set' => 'basic', 'access_code' => null, 'write_code' => null, 'classification_status' => 'interim', 'description' => 'Languages (operational profile)'],
    ['table_name' => 'volunteers', 'column_name' => 'interests', 'field_set' => 'basic', 'access_code' => null, 'write_code' => null, 'classification_status' => 'interim', 'description' => 'Interests (operational profile)'],
    ['table_name' => 'volunteers', 'column_name' => 'availability_type', 'field_set' => 'basic', 'access_code' => null, 'write_code' => null, 'classification_status' => 'interim', 'description' => 'Availability type (operational profile)'],
    ['table_name' => 'volunteer_availability_days', 'column_name' => 'weekday', 'field_set' => 'basic', 'access_code' => null, 'write_code' => null, 'classification_status' => 'interim', 'description' => 'Available weekdays (operational profile)'],
    ['table_name' => 'volunteer_notes', 'column_name' => 'body', 'field_set' => 'confidential.volunteer_notes', 'access_code' => 'confidential.volunteer_notes.view', 'write_code' => 'confidential.volunteer_notes.create', 'classification_status' => 'approved', 'description' => 'Confidential volunteer note'],
    ['table_name' => 'performance_review_internal_notes', 'column_name' => 'note', 'field_set' => 'confidential.review_notes', 'access_code' => 'confidential.review_notes.view', 'write_code' => 'confidential.review_notes.write', 'classification_status' => 'approved', 'description' => 'Review internal note'],
    ['table_name' => 'task_report_decision_notes', 'column_name' => 'note', 'field_set' => 'confidential.decision_notes', 'access_code' => 'confidential.decision_notes.view', 'write_code' => 'confidential.decision_notes.create', 'classification_status' => 'approved', 'description' => 'Task report decision note'],
    ['table_name' => 'hours_decision_notes', 'column_name' => 'note', 'field_set' => 'confidential.decision_notes', 'access_code' => 'confidential.decision_notes.view', 'write_code' => 'confidential.decision_notes.create', 'classification_status' => 'approved', 'description' => 'Hours decision note'],
];
