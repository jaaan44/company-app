/// Typed views of `GET /api/v1/me/schedule` (Phase 30A, R-2…R-7) and
/// `GET /api/v1/schedule-entries/{id}` (Phase 17) — Phase 30A,
/// docs/phases/V1_PHASE_30_DEFINITION.md §5.2.
///
/// Parsing is strict for the structure (a missing or mistyped required
/// field, an unknown source, a bad date or instant throws a
/// [FormatException]); activity types and task statuses stay strings and
/// are labelled leniently, as Home does, so a new value never breaks the
/// screen.
library;

import 'package:mobile/features/home/domain/home_summary.dart'
    show parseUtcOffset;

/// Where a schedule item comes from (the three sources of R-2).
enum ScheduleSource {
  scheduleEntry('schedule_entry'),
  task('task'),
  leave('leave');

  const ScheduleSource(this.wireValue);

  final String wireValue;

  static ScheduleSource fromWire(Object? value) =>
      ScheduleSource.values.firstWhere(
        (s) => s.wireValue == value,
        orElse: () => throw FormatException('Unknown schedule source: $value'),
      );
}

/// A `{public_id, name}` reference (an item's project).
class ScheduleRef {
  const ScheduleRef({required this.publicId, required this.name});

  static ScheduleRef? fromNullable(Object? json, {String nameKey = 'name'}) {
    if (json == null) {
      return null;
    }
    final map = json as Map<String, dynamic>;

    return ScheduleRef(
      publicId: map['public_id'] as String,
      name: map[nameKey] as String,
    );
  }

  final String publicId;
  final String name;
}

/// The company day the server computed "today" for, and how to show UTC
/// instants as company wall-clock time.
class ScheduleCompanyDay {
  const ScheduleCompanyDay({
    required this.date,
    required this.timezone,
    required this.utcOffset,
  });

  factory ScheduleCompanyDay.fromJson(Map<String, dynamic> json) =>
      ScheduleCompanyDay(
        date: apiDate(json['date']),
        timezone: json['timezone'] as String,
        utcOffset: parseUtcOffset(json['utc_offset'] as String),
      );

  /// `YYYY-MM-DD`.
  final String date;
  final String timezone;

  /// The company timezone's offset at the start of today. Used for every
  /// day of the window (a DST switch inside the window is a documented
  /// limitation, as on Home; the company timezone has none).
  final Duration utcOffset;

  /// [instant] as company wall-clock time (a UTC-flagged `DateTime` whose
  /// fields read as company-local values).
  DateTime toCompanyTime(DateTime instant) => instant.toUtc().add(utcOffset);

  /// The UTC instants bounding the company day [date].
  (DateTime start, DateTime end) boundsOf(String date) {
    final d = DateTime.parse(date);
    final start = DateTime.utc(d.year, d.month, d.day).subtract(utcOffset);

    return (start, start.add(const Duration(days: 1)));
  }
}

class ScheduleItem {
  const ScheduleItem({
    required this.source,
    required this.publicId,
    required this.title,
    required this.startsAt,
    required this.endsAt,
    required this.isAllDay,
    this.description,
    this.activityType,
    this.status,
    this.taskStatus,
    this.project,
  });

  factory ScheduleItem.fromJson(Map<String, dynamic> json) {
    try {
      return ScheduleItem(
        source: ScheduleSource.fromWire(json['source_type']),
        publicId: json['public_id'] as String,
        title: json['title'] as String,
        description: json['description'] as String?,
        activityType: json['activity_type'] as String?,
        status: json['status'] as String?,
        taskStatus: json['task_status'] as String?,
        startsAt: instant(json['starts_at']),
        endsAt: instant(json['ends_at']),
        isAllDay: json['is_all_day'] as bool,
        project: ScheduleRef.fromNullable(json['project']),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected schedule item shape: $e');
    }
  }

  final ScheduleSource source;
  final String publicId;
  final String title;
  final String? description;

  /// Schedule entries only (`meeting`, `client_visit`, …).
  final String? activityType;

  /// The source's own status (a task's, or `approved` for leave).
  final String? status;

  /// Tasks only.
  final String? taskStatus;

  /// UTC instants.
  final DateTime startsAt;
  final DateTime endsAt;
  final bool isAllDay;
  final ScheduleRef? project;

  /// Whether this item covers any part of the company day bounded by
  /// [start] (inclusive) and [end] (exclusive). An item ending exactly at
  /// [start] (a meeting until midnight) belongs to the day before only; a
  /// zero-length item at [start] belongs to this day.
  bool overlaps(DateTime start, DateTime end) =>
      startsAt.isBefore(end) &&
      (endsAt.isAfter(start) || !startsAt.isBefore(start));
}

/// One `GET /me/schedule` response.
class MySchedule {
  const MySchedule({
    required this.companyDay,
    required this.from,
    required this.to,
    required this.items,
  });

  /// Parses the whole response body.
  factory MySchedule.fromJson(Map<String, dynamic> json) {
    try {
      final data = json['data'] as Map<String, dynamic>;
      final range = data['range'] as Map<String, dynamic>;
      final items = data['items'];

      return MySchedule(
        companyDay: ScheduleCompanyDay.fromJson(
          data['company_day'] as Map<String, dynamic>,
        ),
        from: apiDate(range['from']),
        to: apiDate(range['to']),
        items: items == null
            ? null
            : (items as List<dynamic>)
                  .map((i) => ScheduleItem.fromJson(i as Map<String, dynamic>))
                  .toList(growable: false),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected /me/schedule shape: $e');
    }
  }

  final ScheduleCompanyDay companyDay;
  final String from;
  final String to;

  /// Null when no Staff record is linked (R-7).
  final List<ScheduleItem>? items;

  bool get hasProfile => items != null;

  /// Whether this response covers every day from [first] to [last].
  bool covers(String first, String last) =>
      from.compareTo(first) <= 0 && to.compareTo(last) >= 0;
}

/// One day of the agenda: its company date and the items that cover it,
/// in the server's order (R-6). A multi-day item appears on every day it
/// covers.
class ScheduleDay {
  const ScheduleDay({required this.date, required this.items});

  final String date;
  final List<ScheduleItem> items;
}

/// The days [first]…[last] (inclusive) with their items, from [items] in
/// server order, judged in company time.
List<ScheduleDay> groupByDay(
  List<ScheduleItem> items,
  ScheduleCompanyDay companyDay,
  String first,
  String last,
) {
  final days = <ScheduleDay>[];
  for (var date = first; date.compareTo(last) <= 0; date = shiftDate(date, 1)) {
    final (start, end) = companyDay.boundsOf(date);
    days.add(
      ScheduleDay(
        date: date,
        items: items
            .where((i) => i.overlaps(start, end))
            .toList(growable: false),
      ),
    );
  }

  return days;
}

/// A person on an entry (creator or participant).
class SchedulePerson {
  const SchedulePerson({required this.publicId, required this.displayName});

  factory SchedulePerson.fromJson(Map<String, dynamic> json) => SchedulePerson(
    publicId: json['public_id'] as String,
    displayName: json['display_name'] as String,
  );

  final String publicId;
  final String displayName;
}

/// `GET /schedule-entries/{id}` (Phase 17 `ScheduleEntryResource`).
class ScheduleEntryDetail {
  const ScheduleEntryDetail({
    required this.publicId,
    required this.title,
    required this.activityType,
    required this.startsAt,
    required this.endsAt,
    required this.isAllDay,
    required this.participants,
    this.description,
    this.project,
    this.creator,
  });

  factory ScheduleEntryDetail.fromJson(Map<String, dynamic> json) {
    try {
      final creator = json['creator'];

      return ScheduleEntryDetail(
        publicId: json['public_id'] as String,
        title: json['title'] as String,
        description: json['description'] as String?,
        activityType: json['activity_type'] as String,
        startsAt: instant(json['starts_at']),
        endsAt: instant(json['ends_at']),
        isAllDay: json['is_all_day'] as bool,
        project: ScheduleRef.fromNullable(json['project']),
        creator: creator == null
            ? null
            : SchedulePerson.fromJson(creator as Map<String, dynamic>),
        participants: ((json['participants'] as List<dynamic>?) ?? const [])
            .map((p) => SchedulePerson.fromJson(p as Map<String, dynamic>))
            .toList(growable: false),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected schedule entry shape: $e');
    }
  }

  final String publicId;
  final String title;
  final String? description;
  final String activityType;
  final DateTime startsAt;
  final DateTime endsAt;
  final bool isAllDay;
  final ScheduleRef? project;
  final SchedulePerson? creator;
  final List<SchedulePerson> participants;
}

final _datePattern = RegExp(r'^\d{4}-\d{2}-\d{2}$');

/// A strict `YYYY-MM-DD`.
String apiDate(Object? value) {
  if (value is! String || !_datePattern.hasMatch(value)) {
    throw FormatException('Not a YYYY-MM-DD date: $value');
  }

  return value;
}

/// A strict ISO-8601 instant, as UTC.
DateTime instant(Object? value) {
  if (value is! String) {
    throw FormatException('Not an instant: $value');
  }

  return DateTime.parse(value).toUtc();
}

/// [date] moved by [days] (calendar arithmetic in UTC).
String shiftDate(String date, int days) {
  final d = DateTime.parse(date);
  final shifted = DateTime.utc(d.year, d.month, d.day + days);

  return '${shifted.year.toString().padLeft(4, '0')}-'
      '${shifted.month.toString().padLeft(2, '0')}-'
      '${shifted.day.toString().padLeft(2, '0')}';
}
