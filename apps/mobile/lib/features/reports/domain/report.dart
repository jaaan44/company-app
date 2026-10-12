/// Typed views of the Phase 18/19 service and incident report APIs as the
/// mobile Reports area uses them — Phase 33,
/// docs/phases/V1_PHASE_33_DEFINITION.md.
///
/// Strict for the structure; nullable fields stay null; unknown enum
/// values are kept as `unknown` so a new server value never breaks a
/// screen.
library;

import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay, apiDate, instant;

/// The API's limits (Phases 18/19), mirrored so the forms refuse
/// impossible input before sending; the API stays authoritative.
abstract final class ReportLimits {
  static const maxLongText = 10000;
  static const maxText = 5000;
  static const maxShortText = 255;

  /// `config('attachments.max_size_kb')` on the server: 10 MB.
  static const maxAttachmentBytes = 10240 * 1024;
}

/// A `{public_id, name|title}` reference.
class ReportRef {
  const ReportRef({required this.publicId, required this.name});

  static ReportRef? fromNullable(Object? json, {String nameKey = 'name'}) {
    if (json == null) {
      return null;
    }
    final map = json as Map<String, dynamic>;

    return ReportRef(
      publicId: map['public_id'] as String,
      name: map[nameKey] as String,
    );
  }

  final String publicId;
  final String name;
}

/// A `{public_id, employee_number, display_name}` person.
class ReportPerson {
  const ReportPerson({required this.publicId, required this.displayName});

  static ReportPerson? fromNullable(Object? json) {
    if (json == null) {
      return null;
    }
    final map = json as Map<String, dynamic>;

    return ReportPerson(
      publicId: map['public_id'] as String,
      displayName: map['display_name'] as String,
    );
  }

  final String publicId;
  final String displayName;
}

/// One `AttachmentResource`.
class ReportAttachment {
  const ReportAttachment({
    required this.publicId,
    required this.filename,
    required this.mimeType,
    required this.sizeBytes,
    this.uploadedBy,
    this.createdAt,
  });

  factory ReportAttachment.fromJson(Map<String, dynamic> json) =>
      ReportAttachment(
        publicId: json['public_id'] as String,
        filename: json['original_filename'] as String,
        mimeType: json['mime_type'] as String,
        sizeBytes: json['size_bytes'] as int,
        uploadedBy: ReportPerson.fromNullable(json['uploaded_by']),
        createdAt: json['created_at'] == null
            ? null
            : instant(json['created_at']),
      );

  final String publicId;
  final String filename;
  final String mimeType;
  final int sizeBytes;
  final ReportPerson? uploadedBy;
  final DateTime? createdAt;

  /// Shown in the app (R-7); anything else is "Open it on the web".
  bool get isImage => mimeType == 'image/jpeg' || mimeType == 'image/png';
}

/// "820 KB", "2.4 MB".
String fileSizeLabel(int bytes) {
  if (bytes < 1024 * 1024) {
    return '${(bytes / 1024).ceil()} KB';
  }

  return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
}

/// One history row.
class ReportHistoryEntry {
  const ReportHistoryEntry({
    required this.action,
    this.actedBy,
    this.note,
    this.createdAt,
  });

  factory ReportHistoryEntry.fromJson(Map<String, dynamic> json) =>
      ReportHistoryEntry(
        action: json['action'] as String,
        actedBy: ReportPerson.fromNullable(json['acted_by']),
        note: json['note'] as String?,
        createdAt: json['created_at'] == null
            ? null
            : instant(json['created_at']),
      );

  /// The wire value, e.g. `submitted`, `investigation_started`.
  final String action;
  final ReportPerson? actedBy;
  final String? note;
  final DateTime? createdAt;

  /// "Submitted", "Investigation started", "Returned to draft".
  String get label {
    final words = action.replaceAll('_', ' ');

    return words.isEmpty
        ? 'Updated'
        : '${words[0].toUpperCase()}${words.substring(1)}';
  }
}

List<T> _list<T>(Object? json, T Function(Map<String, dynamic>) item) =>
    List.unmodifiable(
      ((json as List<dynamic>?) ?? const []).map(
        (e) => item(e as Map<String, dynamic>),
      ),
    );

String? _text(Object? value) {
  final s = value as String?;

  return s == null || s.trim().isEmpty ? null : s;
}

// --- Service reports -----------------------------------------------------

enum ServiceReportStatus {
  draft('draft', 'Draft'),
  submitted('submitted', 'Submitted'),
  reviewed('reviewed', 'Reviewed'),
  rejected('rejected', 'Rejected'),
  unknown('', 'Unknown');

  const ServiceReportStatus(this.wireValue, this.label);

  final String wireValue;
  final String label;

  static ServiceReportStatus fromWire(Object? value) {
    if (value is! String) {
      throw FormatException('Not a status: $value');
    }

    return values.firstWhere(
      (s) => s != unknown && s.wireValue == value,
      orElse: () => unknown,
    );
  }
}

/// One `ServiceReportResource`.
class ServiceReport {
  const ServiceReport({
    required this.publicId,
    required this.client,
    required this.creator,
    required this.serviceDate,
    required this.workPerformed,
    required this.status,
    required this.history,
    required this.attachments,
    this.project,
    this.task,
    this.findings,
    this.recommendations,
    this.followUpActions,
    this.siteRepresentative,
  });

  factory ServiceReport.fromJson(Map<String, dynamic> json) {
    try {
      return ServiceReport(
        publicId: json['public_id'] as String,
        client: ReportRef.fromNullable(json['client'])!,
        project: ReportRef.fromNullable(json['project']),
        task: ReportRef.fromNullable(json['task'], nameKey: 'title'),
        creator: ReportPerson.fromNullable(json['creator'])!,
        serviceDate: apiDate(json['service_date']),
        workPerformed: json['work_performed'] as String,
        findings: _text(json['findings']),
        recommendations: _text(json['recommendations']),
        followUpActions: _text(json['follow_up_actions']),
        siteRepresentative: _text(json['site_representative_name']),
        status: ServiceReportStatus.fromWire(json['status']),
        history: _list(json['history'], ReportHistoryEntry.fromJson),
        attachments: _list(json['attachments'], ReportAttachment.fromJson),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected service report shape: $e');
    }
  }

  final String publicId;
  final ReportRef client;
  final ReportRef? project;
  final ReportRef? task;
  final ReportPerson creator;

  /// `YYYY-MM-DD`.
  final String serviceDate;
  final String workPerformed;
  final String? findings;
  final String? recommendations;
  final String? followUpActions;
  final String? siteRepresentative;
  final ServiceReportStatus status;
  final List<ReportHistoryEntry> history;
  final List<ReportAttachment> attachments;

  bool isMine(String? myStaffId) => creator.publicId == myStaffId;

  /// R-4: edit, attach, submit, delete — my draft only.
  bool canEditBy(String? myStaffId) =>
      status == ServiceReportStatus.draft && isMine(myStaffId);

  /// R-4: Return to draft — my rejected report.
  bool canReturnBy(String? myStaffId) =>
      status == ServiceReportStatus.rejected && isMine(myStaffId);
}

/// What `POST`/`PATCH /service-reports` is sent (R-3). Blank optional
/// text is sent as null.
class ServiceReportDraft {
  const ServiceReportDraft({
    required this.clientId,
    required this.serviceDate,
    required this.workPerformed,
    this.projectId,
    this.findings = '',
    this.recommendations = '',
    this.followUpActions = '',
    this.siteRepresentative = '',
  });

  final String clientId;
  final String? projectId;
  final String serviceDate;
  final String workPerformed;
  final String findings;
  final String recommendations;
  final String followUpActions;
  final String siteRepresentative;

  Map<String, dynamic> toJson() {
    String? text(String v) => v.trim().isEmpty ? null : v.trim();

    return {
      'client_id': clientId,
      'project_id': projectId,
      'service_date': serviceDate,
      'work_performed': workPerformed.trim(),
      'findings': text(findings),
      'recommendations': text(recommendations),
      'follow_up_actions': text(followUpActions),
      'site_representative_name': text(siteRepresentative),
    };
  }
}

// --- Incident reports ----------------------------------------------------

enum IncidentStatus {
  reported('reported', 'Reported'),
  underInvestigation('under_investigation', 'Under investigation'),
  resolved('resolved', 'Resolved'),
  closed('closed', 'Closed'),
  unknown('', 'Unknown');

  const IncidentStatus(this.wireValue, this.label);

  final String wireValue;
  final String label;

  static IncidentStatus fromWire(Object? value) {
    if (value is! String) {
      throw FormatException('Not a status: $value');
    }

    return values.firstWhere(
      (s) => s != unknown && s.wireValue == value,
      orElse: () => unknown,
    );
  }
}

enum IncidentType {
  workplaceSafety('workplace_safety', 'Workplace safety'),
  clientSite('client_site', 'Client site'),
  operational('operational', 'Operational'),
  propertyEquipmentDamage(
    'property_equipment_damage',
    'Property or equipment damage',
  ),
  itSecurity('it_security', 'IT security'),
  other('other', 'Other'),
  unknown('', 'Unknown type');

  const IncidentType(this.wireValue, this.label);

  final String wireValue;
  final String label;

  /// What the form offers.
  static const choices = [
    workplaceSafety,
    clientSite,
    operational,
    propertyEquipmentDamage,
    itSecurity,
    other,
  ];

  static IncidentType fromWire(Object? value) {
    if (value is! String) {
      throw FormatException('Not an incident type: $value');
    }

    return choices.firstWhere(
      (t) => t.wireValue == value,
      orElse: () => unknown,
    );
  }
}

enum IncidentSeverity {
  low('low', 'Low'),
  medium('medium', 'Medium'),
  high('high', 'High'),
  critical('critical', 'Critical'),
  unknown('', 'Unknown');

  const IncidentSeverity(this.wireValue, this.label);

  final String wireValue;
  final String label;

  static const choices = [low, medium, high, critical];

  static IncidentSeverity fromWire(Object? value) {
    if (value is! String) {
      throw FormatException('Not a severity: $value');
    }

    return choices.firstWhere(
      (s) => s.wireValue == value,
      orElse: () => unknown,
    );
  }
}

/// One `IncidentReportResource`.
class IncidentReport {
  const IncidentReport({
    required this.publicId,
    required this.type,
    required this.severity,
    required this.status,
    required this.occurredAt,
    required this.description,
    required this.reporter,
    required this.history,
    required this.attachments,
    this.location,
    this.assignedTo,
    this.immediateActionTaken,
    this.peopleInvolved,
    this.witnessNotes,
    this.rootCause,
    this.correctiveAction,
    this.preventiveAction,
    this.followUpActions,
    this.resolution,
  });

  factory IncidentReport.fromJson(Map<String, dynamic> json) {
    try {
      return IncidentReport(
        publicId: json['public_id'] as String,
        type: IncidentType.fromWire(json['incident_type']),
        severity: IncidentSeverity.fromWire(json['severity']),
        status: IncidentStatus.fromWire(json['status']),
        occurredAt: instant(json['occurred_at']),
        location: _text(json['location']),
        description: json['description'] as String,
        reporter: ReportPerson.fromNullable(json['reporter'])!,
        assignedTo: ReportPerson.fromNullable(json['assigned_to']),
        immediateActionTaken: _text(json['immediate_action_taken']),
        peopleInvolved: _text(json['people_involved']),
        witnessNotes: _text(json['witness_notes']),
        rootCause: _text(json['root_cause']),
        correctiveAction: _text(json['corrective_action']),
        preventiveAction: _text(json['preventive_action']),
        followUpActions: _text(json['follow_up_actions']),
        resolution: _text(json['resolution']),
        history: _list(json['history'], ReportHistoryEntry.fromJson),
        attachments: _list(json['attachments'], ReportAttachment.fromJson),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected incident report shape: $e');
    }
  }

  final String publicId;
  final IncidentType type;
  final IncidentSeverity severity;
  final IncidentStatus status;

  /// UTC.
  final DateTime occurredAt;
  final String? location;
  final String description;
  final ReportPerson reporter;
  final ReportPerson? assignedTo;
  final String? immediateActionTaken;
  final String? peopleInvolved;
  final String? witnessNotes;
  final String? rootCause;
  final String? correctiveAction;
  final String? preventiveAction;
  final String? followUpActions;
  final String? resolution;
  final List<ReportHistoryEntry> history;
  final List<ReportAttachment> attachments;

  bool isMine(String? myStaffId) => reporter.publicId == myStaffId;

  /// R-6: edit and attach — mine, still reported.
  bool canEditBy(String? myStaffId) =>
      status == IncidentStatus.reported && isMine(myStaffId);
}

/// What `POST`/`PATCH /incident-reports` is sent (R-5).
class IncidentDraft {
  const IncidentDraft({
    required this.type,
    required this.severity,
    required this.occurredAt,
    required this.description,
    this.location = '',
    this.immediateActionTaken = '',
    this.peopleInvolved = '',
    this.witnessNotes = '',
  });

  final IncidentType type;
  final IncidentSeverity severity;

  /// UTC.
  final DateTime occurredAt;
  final String description;
  final String location;
  final String immediateActionTaken;
  final String peopleInvolved;
  final String witnessNotes;

  Map<String, dynamic> toJson() {
    String? text(String v) => v.trim().isEmpty ? null : v.trim();

    return {
      'incident_type': type.wireValue,
      'severity': severity.wireValue,
      'occurred_at': occurredAt.toUtc().toIso8601String(),
      'location': text(location),
      'description': description.trim(),
      'immediate_action_taken': text(immediateActionTaken),
      'people_involved': text(peopleInvolved),
      'witness_notes': text(witnessNotes),
    };
  }
}

/// One page of a report list with the company day (R-2c).
class ReportListPage<T> {
  const ReportListPage({required this.companyDay, required this.page});

  factory ReportListPage.fromJson(
    Map<String, dynamic> json,
    T Function(Map<String, dynamic> json) item,
  ) {
    try {
      return ReportListPage(
        companyDay: ScheduleCompanyDay.fromJson(
          (json['meta'] as Map<String, dynamic>)['company_day']
              as Map<String, dynamic>,
        ),
        page: PagedResult.fromJson(json, item),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected reports page shape: $e');
    }
  }

  final ScheduleCompanyDay companyDay;
  final PagedResult<T> page;
}
