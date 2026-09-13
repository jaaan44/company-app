<?php

namespace App\Enums;

/**
 * The kind of activity a manually created Schedule Entry represents
 * (Phase 17 — Scheduler). A closed, fixed set — these are all *types of
 * the generic Schedule Entry*, never separate tables/modules (no
 * dedicated Meeting/Visit/Appointment/Training/Company Event entity
 * exists or is planned; see docs/phases/V1_PHASE_17_DEFINITION.md). Not
 * to be confused with `ScheduleSourceType`, which distinguishes a
 * manually created entry from an aggregated Task/Leave/Milestone item in
 * the unified `GET /api/v1/schedule` projection.
 */
enum ScheduleEntryActivityType: string
{
    case Meeting = 'meeting';
    case ClientVisit = 'client_visit';
    case ServiceAppointment = 'service_appointment';
    case CompanyEvent = 'company_event';
    case Training = 'training';
    case InternalActivity = 'internal_activity';
    case Other = 'other';
}
