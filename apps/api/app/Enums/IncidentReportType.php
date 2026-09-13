<?php

namespace App\Enums;

/**
 * The closed incident-category taxonomy for an Incident Report (Phase 19).
 * One generic Incident Report entity carries this enum rather than a
 * dedicated table/model per category (docs/phases/V1_PHASE_19_DEFINITION.md's
 * Incident Type) — the same "all values of one generic entity" discipline
 * `ScheduleEntryActivityType` (Phase 17) already established for this
 * codebase.
 */
enum IncidentReportType: string
{
    case WorkplaceSafety = 'workplace_safety';
    case ClientSite = 'client_site';
    case Operational = 'operational';
    case PropertyEquipmentDamage = 'property_equipment_damage';
    case ItSecurity = 'it_security';
    case Other = 'other';
}
