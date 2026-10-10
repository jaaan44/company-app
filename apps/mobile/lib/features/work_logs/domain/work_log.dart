/// Typed views of the Phase 12 work-log API as the mobile app uses it —
/// Phase 29B, docs/phases/V1_PHASE_29_DEFINITION.md §6.3–§6.4.
///
/// Parsing is strict: a required field that is missing or of the wrong
/// type throws a [FormatException]; a nullable field stays null — never
/// replaced with a placeholder.
library;

/// The API's limits on a work log (Phase 12), mirrored so the form can
/// refuse impossible input before sending; the API stays authoritative.
abstract final class WorkLogLimits {
  static const minMinutes = 1;
  static const maxMinutes = 1440;
  static const maxDescriptionLength = 2000;

  /// How far back the date picker reaches (R-17).
  static const pickerDaysBack = 365;
}

/// "1 h 30 min", "2 h", "45 min".
String formatDuration(int minutes) {
  final h = minutes ~/ 60;
  final m = minutes % 60;
  if (h == 0) {
    return '$m min';
  }

  return m == 0 ? '$h h' : '$h h $m min';
}

/// A `{public_id, <name>}` reference to a task (`title`) or project
/// (`name`).
class WorkLogRef {
  const WorkLogRef({required this.publicId, required this.name});

  static WorkLogRef? fromNullable(Object? json, {required String nameKey}) {
    if (json == null) {
      return null;
    }

    final map = json as Map<String, dynamic>;

    return WorkLogRef(
      publicId: map['public_id'] as String,
      name: map[nameKey] as String,
    );
  }

  final String publicId;
  final String name;
}

/// One `WorkLogResource`.
class WorkLog {
  const WorkLog({
    required this.publicId,
    required this.workDate,
    required this.durationMinutes,
    required this.description,
    this.task,
    this.project,
    this.createdAt,
    this.updatedAt,
  });

  factory WorkLog.fromJson(Map<String, dynamic> json) {
    try {
      final workDate = json['work_date'] as String;
      if (!isApiDate(workDate)) {
        throw FormatException('Invalid work_date: $workDate');
      }

      final task = WorkLogRef.fromNullable(json['task'], nameKey: 'title');
      final project = WorkLogRef.fromNullable(json['project'], nameKey: 'name');
      if (task == null && project == null) {
        // The API never stores a log with neither (Phase 12).
        throw const FormatException('A work log has neither task nor project');
      }

      return WorkLog(
        publicId: json['public_id'] as String,
        workDate: workDate,
        durationMinutes: json['duration_minutes'] as int,
        description: json['description'] as String,
        task: task,
        project: project,
        createdAt: _instant(json['created_at']),
        updatedAt: _instant(json['updated_at']),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected work log shape: $e');
    }
  }

  final String publicId;

  /// `YYYY-MM-DD`, a company calendar date.
  final String workDate;
  final int durationMinutes;
  final String description;

  /// The task, when the log is for one (its project is then [project]).
  final WorkLogRef? task;
  final WorkLogRef? project;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  /// What the log was for: the task title, otherwise the project name.
  String get targetLabel => task?.name ?? project!.name;
}

/// The company day the server reported (`meta.company_day`, R-12).
class WorkLogCompanyDay {
  const WorkLogCompanyDay({required this.date, required this.timezone});

  factory WorkLogCompanyDay.fromJson(Map<String, dynamic> json) {
    final date = json['date'] as String;
    if (!isApiDate(date)) {
      throw FormatException('Invalid company_day.date: $date');
    }

    return WorkLogCompanyDay(date: date, timezone: json['timezone'] as String);
  }

  /// `YYYY-MM-DD` in the company timezone.
  final String date;
  final String timezone;
}

/// One page of `GET /api/v1/me/work-logs`.
class MyWorkLogsPage {
  const MyWorkLogsPage({
    required this.items,
    required this.companyDay,
    required this.currentPage,
    required this.lastPage,
  });

  /// Parses the whole response body (`data`, `meta`).
  factory MyWorkLogsPage.fromJson(Map<String, dynamic> json) {
    try {
      final meta = json['meta'] as Map<String, dynamic>;

      return MyWorkLogsPage(
        items: (json['data'] as List<dynamic>)
            .map((l) => WorkLog.fromJson(l as Map<String, dynamic>))
            .toList(growable: false),
        companyDay: WorkLogCompanyDay.fromJson(
          meta['company_day'] as Map<String, dynamic>,
        ),
        currentPage: meta['current_page'] as int,
        lastPage: meta['last_page'] as int,
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected /me/work-logs response shape: $e');
    }
  }

  final List<WorkLog> items;
  final WorkLogCompanyDay companyDay;
  final int currentPage;
  final int lastPage;

  bool get hasMore => currentPage < lastPage;
}

/// The logs of one work date, in server order.
class WorkLogDay {
  const WorkLogDay({required this.date, required this.logs});

  final String date;
  final List<WorkLog> logs;
}

/// What a new log is for (R-14): one of my tasks, or one of my projects.
sealed class WorkTarget {
  const WorkTarget({required this.publicId, required this.label});

  final String publicId;

  /// What the picker shows.
  final String label;

  /// The create request's reference field and value.
  MapEntry<String, String> get requestField;
}

final class TaskTarget extends WorkTarget {
  const TaskTarget({
    required super.publicId,
    required super.label,
    this.projectName,
  });

  /// The task's project, shown beneath it (the server derives the log's
  /// project from the task).
  final String? projectName;

  @override
  MapEntry<String, String> get requestField => MapEntry('task_id', publicId);
}

final class ProjectTarget extends WorkTarget {
  const ProjectTarget({
    required super.publicId,
    required super.label,
    this.projectCode,
  });

  final String? projectCode;

  @override
  MapEntry<String, String> get requestField => MapEntry('project_id', publicId);
}

/// One of my projects from `GET /projects?member=…` — only what the picker
/// needs.
class MemberProject {
  const MemberProject({
    required this.publicId,
    required this.name,
    required this.status,
    this.projectCode,
  });

  factory MemberProject.fromJson(Map<String, dynamic> json) {
    try {
      return MemberProject(
        publicId: json['public_id'] as String,
        name: json['name'] as String,
        status: json['status'] as String,
        projectCode: json['project_code'] as String?,
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected project shape: $e');
    }
  }

  final String publicId;
  final String name;

  /// `planned`, `active`, `on_hold`, `completed` or `cancelled`.
  final String status;
  final String? projectCode;

  /// Completed and cancelled projects aren't offered (R-14).
  bool get isClosed => status == 'completed' || status == 'cancelled';
}

final _datePattern = RegExp(r'^\d{4}-\d{2}-\d{2}$');

bool isApiDate(String value) => _datePattern.hasMatch(value);

DateTime? _instant(Object? value) =>
    value == null ? null : DateTime.parse(value as String).toUtc();
