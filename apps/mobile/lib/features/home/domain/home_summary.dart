/// Typed view of `GET /api/v1/me/home` (Phase 27 — Employee Home,
/// docs/phases/V1_PHASE_27_DEFINITION.md §6.2). Mirrors the contract
/// exactly: the Staff-dependent sections ([staff], [today], [tasks],
/// [unreadMessages], [announcements]) are null when the account has no
/// linked employee profile — never replaced with zeros or placeholders.
///
/// Parsing is strict: a response that doesn't match the contract throws a
/// [FormatException] (see [HomeSummary.fromJson]), which the data layer
/// turns into an ordinary request failure.
class HomeSummary {
  const HomeSummary({
    required this.user,
    required this.staff,
    required this.companyDay,
    required this.today,
    required this.tasks,
    required this.unreadMessages,
    required this.unreadNotifications,
    required this.announcements,
  });

  /// Parses the object under the response's `data` key.
  factory HomeSummary.fromJson(Map<String, dynamic> json) {
    try {
      final staff = json['staff'];
      final today = json['today'];
      final tasks = json['tasks'];
      final messages = json['messages'];
      final announcements = json['announcements'];

      return HomeSummary(
        user: HomeUser.fromJson(_map(json['user'])),
        staff: staff == null ? null : HomeStaff.fromJson(_map(staff)),
        companyDay: CompanyDay.fromJson(_map(json['company_day'])),
        today: today == null ? null : HomeToday.fromJson(_map(today)),
        tasks: tasks == null ? null : TaskCounts.fromJson(_map(tasks)),
        unreadMessages: messages == null
            ? null
            : _map(messages)['unread_count'] as int,
        unreadNotifications: _map(json['notifications'])['unread_count'] as int,
        announcements: announcements == null
            ? null
            : (_map(announcements)['latest'] as List<dynamic>)
                  .map((a) => AnnouncementPreview.fromJson(_map(a)))
                  .toList(growable: false),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected /me/home response shape: $e');
    }
  }

  final HomeUser user;

  /// Null when the account has no linked employee (Staff) profile.
  final HomeStaff? staff;
  final CompanyDay companyDay;
  final HomeToday? today;
  final TaskCounts? tasks;
  final int? unreadMessages;
  final int unreadNotifications;
  final List<AnnouncementPreview>? announcements;

  bool get hasEmployeeProfile => staff != null;
}

class HomeUser {
  const HomeUser({required this.publicId, required this.name, this.role});

  factory HomeUser.fromJson(Map<String, dynamic> json) => HomeUser(
    publicId: json['public_id'] as String,
    name: json['name'] as String,
    role: json['role'] as String?,
  );

  final String publicId;
  final String name;
  final String? role;
}

class HomeStaff {
  const HomeStaff({
    required this.publicId,
    required this.displayName,
    required this.firstName,
    this.preferredName,
    this.positionTitle,
    this.departmentName,
    this.teamName,
  });

  factory HomeStaff.fromJson(Map<String, dynamic> json) {
    final position = json['position'];
    final department = json['department'];
    final team = json['team'];

    return HomeStaff(
      publicId: json['public_id'] as String,
      displayName: json['display_name'] as String,
      firstName: json['first_name'] as String,
      preferredName: json['preferred_name'] as String?,
      positionTitle: position == null
          ? null
          : _map(position)['title'] as String,
      departmentName: department == null
          ? null
          : _map(department)['name'] as String,
      teamName: team == null ? null : _map(team)['name'] as String,
    );
  }

  final String publicId;
  final String displayName;
  final String firstName;
  final String? preferredName;
  final String? positionTitle;
  final String? departmentName;
  final String? teamName;

  /// The name Home greets the employee by: `preferred_name ?? first_name`
  /// (spec §8).
  String get greetingName {
    final preferred = preferredName;

    return preferred != null && preferred.isNotEmpty ? preferred : firstName;
  }
}

/// The authoritative company-timezone day the server computed "today" for.
class CompanyDay {
  const CompanyDay({
    required this.date,
    required this.timezone,
    required this.startsAt,
    required this.endsAt,
    required this.utcOffset,
  });

  factory CompanyDay.fromJson(Map<String, dynamic> json) {
    final date = json['date'] as String;

    return CompanyDay(
      date: _parseDate(date),
      timezone: json['timezone'] as String,
      startsAt: _parseInstant(json['starts_at']),
      endsAt: _parseInstant(json['ends_at']),
      utcOffset: parseUtcOffset(json['utc_offset'] as String),
    );
  }

  /// The company calendar date (a UTC-midnight `DateTime` used only as a
  /// calendar date — never as an instant).
  final DateTime date;
  final String timezone;

  /// UTC instants bounding the company day.
  final DateTime startsAt;
  final DateTime endsAt;

  /// The company timezone's offset at the start of the day (spec §8: used
  /// to display instants in company time; a DST switch within the day is a
  /// documented limitation).
  final Duration utcOffset;

  /// [instant] expressed as company wall-clock time (a UTC-flagged
  /// `DateTime` whose fields read as company-local values).
  DateTime toCompanyTime(DateTime instant) => instant.toUtc().add(utcOffset);
}

enum TodayItemKind { scheduleEntry, task }

class TodayItem {
  const TodayItem({
    required this.kind,
    required this.publicId,
    required this.title,
    required this.startsAt,
    required this.endsAt,
    required this.isAllDay,
    this.activityType,
    this.taskStatus,
  });

  factory TodayItem.fromJson(Map<String, dynamic> json) {
    final kind = switch (json['source_type']) {
      'schedule_entry' => TodayItemKind.scheduleEntry,
      'task' => TodayItemKind.task,
      final other => throw FormatException('Unknown Today source_type: $other'),
    };

    return TodayItem(
      kind: kind,
      publicId: json['public_id'] as String,
      title: json['title'] as String,
      activityType: json['activity_type'] as String?,
      taskStatus: json['task_status'] as String?,
      startsAt: _parseInstant(json['starts_at']),
      endsAt: _parseInstant(json['ends_at']),
      isAllDay: json['is_all_day'] as bool,
    );
  }

  final TodayItemKind kind;
  final String publicId;
  final String title;

  /// `ScheduleEntryActivityType` value, for schedule entries only.
  final String? activityType;

  /// `TaskStatus` value, for tasks only.
  final String? taskStatus;
  final DateTime startsAt;
  final DateTime endsAt;
  final bool isAllDay;
}

class HomeToday {
  const HomeToday({required this.totalCount, required this.items});

  factory HomeToday.fromJson(Map<String, dynamic> json) => HomeToday(
    totalCount: json['total_count'] as int,
    items: (json['items'] as List<dynamic>)
        .map((i) => TodayItem.fromJson(_map(i)))
        .toList(growable: false),
  );

  /// Every qualifying item; [items] holds at most five of them, already in
  /// the server's order.
  final int totalCount;
  final List<TodayItem> items;

  int get moreCount =>
      totalCount > items.length ? totalCount - items.length : 0;
}

class TaskCounts {
  const TaskCounts({
    required this.open,
    required this.overdue,
    required this.dueToday,
  });

  factory TaskCounts.fromJson(Map<String, dynamic> json) => TaskCounts(
    open: json['open_count'] as int,
    overdue: json['overdue_count'] as int,
    dueToday: json['due_today_count'] as int,
  );

  final int open;
  final int overdue;
  final int dueToday;
}

class AnnouncementPreview {
  const AnnouncementPreview({
    required this.publicId,
    required this.title,
    this.publishedAt,
  });

  factory AnnouncementPreview.fromJson(Map<String, dynamic> json) {
    final published = json['published_at'];

    return AnnouncementPreview(
      publicId: json['public_id'] as String,
      title: json['title'] as String,
      publishedAt: published == null ? null : _parseInstant(published),
    );
  }

  final String publicId;
  final String title;
  final DateTime? publishedAt;
}

/// Parses an ISO 8601 offset such as `+09:00`, `-05:30` or `+00:00`.
Duration parseUtcOffset(String value) {
  final match = RegExp(r'^([+-])(\d{2}):(\d{2})$').firstMatch(value);
  if (match == null) {
    throw FormatException('Invalid utc_offset: $value');
  }

  final minutes = int.parse(match[2]!) * 60 + int.parse(match[3]!);

  return Duration(minutes: match[1] == '-' ? -minutes : minutes);
}

Map<String, dynamic> _map(Object? value) => value as Map<String, dynamic>;

DateTime _parseInstant(Object? value) =>
    DateTime.parse(value as String).toUtc();

DateTime _parseDate(String value) {
  final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(value);
  if (match == null) {
    throw FormatException('Invalid company_day.date: $value');
  }

  return DateTime.utc(
    int.parse(match[1]!),
    int.parse(match[2]!),
    int.parse(match[3]!),
  );
}
