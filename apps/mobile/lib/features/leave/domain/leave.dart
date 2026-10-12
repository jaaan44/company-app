/// Typed views of the Phase 13 leave API as the mobile Leave area uses it —
/// Phase 31, docs/phases/V1_PHASE_31_DEFINITION.md.
///
/// Parsing is strict for the structure (a missing or mistyped required
/// field, a bad date or instant throws a [FormatException]); a status or
/// history action the app doesn't know is kept as `unknown`, so a new
/// server value never breaks the screen.
library;

import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay, apiDate, instant;

/// The API's limits on a leave request (Phase 13), mirrored so the form
/// refuses impossible input before sending; the API stays authoritative.
abstract final class LeaveLimits {
  static const minReasonLength = 3;
  static const maxReasonLength = 1000;
  static const maxNoteLength = 1000;
}

/// A leave request's state (R-8).
enum LeaveStatus {
  pending('pending', 'Pending'),
  approved('approved', 'Approved'),
  rejected('rejected', 'Rejected'),
  cancelled('cancelled', 'Cancelled'),
  unknown('', 'Unknown');

  const LeaveStatus(this.wireValue, this.label);

  final String wireValue;
  final String label;

  static LeaveStatus fromWire(Object? value) {
    if (value is! String) {
      throw FormatException('Not a leave status: $value');
    }

    return values.firstWhere(
      (s) => s != unknown && s.wireValue == value,
      orElse: () => unknown,
    );
  }
}

/// One step of a request's history.
enum LeaveAction {
  submitted('submitted', 'Requested'),
  approved('approved', 'Approved'),
  rejected('rejected', 'Rejected'),
  cancelled('cancelled', 'Cancelled'),
  unknown('', 'Updated');

  const LeaveAction(this.wireValue, this.label);

  final String wireValue;
  final String label;

  static LeaveAction fromWire(Object? value) {
    if (value is! String) {
      throw FormatException('Not a leave action: $value');
    }

    return values.firstWhere(
      (a) => a != unknown && a.wireValue == value,
      orElse: () => unknown,
    );
  }
}

/// A `{public_id, employee_number, display_name}` staff reference.
class LeavePerson {
  const LeavePerson({required this.publicId, required this.displayName});

  static LeavePerson? fromNullable(Object? json) {
    if (json == null) {
      return null;
    }
    final map = json as Map<String, dynamic>;

    return LeavePerson(
      publicId: map['public_id'] as String,
      displayName: map['display_name'] as String,
    );
  }

  final String publicId;
  final String displayName;
}

/// A leave type as a request or balance names it.
class LeaveTypeRef {
  const LeaveTypeRef({
    required this.publicId,
    required this.name,
    required this.isPaid,
    this.code,
  });

  factory LeaveTypeRef.fromJson(
    Map<String, dynamic> json, {
    required bool isPaid,
  }) => LeaveTypeRef(
    publicId: json['public_id'] as String,
    name: json['name'] as String,
    code: json['code'] as String?,
    isPaid: isPaid,
  );

  final String publicId;
  final String name;
  final String? code;
  final bool isPaid;
}

/// One history row (`LeaveRequestActionResource`).
class LeaveHistoryEntry {
  const LeaveHistoryEntry({
    required this.action,
    this.actedBy,
    this.note,
    this.createdAt,
  });

  factory LeaveHistoryEntry.fromJson(Map<String, dynamic> json) =>
      LeaveHistoryEntry(
        action: LeaveAction.fromWire(json['action']),
        actedBy: LeavePerson.fromNullable(json['acted_by']),
        note: json['note'] as String?,
        createdAt: json['created_at'] == null
            ? null
            : instant(json['created_at']),
      );

  final LeaveAction action;

  /// Null when the acting account has no Staff record.
  final LeavePerson? actedBy;
  final String? note;

  /// UTC.
  final DateTime? createdAt;
}

/// One `LeaveRequestResource`.
class LeaveRequest {
  const LeaveRequest({
    required this.publicId,
    required this.leaveType,
    required this.startDate,
    required this.endDate,
    required this.totalDays,
    required this.reason,
    required this.status,
    required this.history,
    this.staff,
    this.createdAt,
  });

  factory LeaveRequest.fromJson(Map<String, dynamic> json) {
    try {
      final type = json['leave_type'] as Map<String, dynamic>;
      final start = apiDate(json['start_date']);
      final end = apiDate(json['end_date']);
      if (end.compareTo(start) < 0) {
        throw FormatException('end_date before start_date: $start..$end');
      }

      return LeaveRequest(
        publicId: json['public_id'] as String,
        staff: LeavePerson.fromNullable(json['staff']),
        leaveType: LeaveTypeRef.fromJson(type, isPaid: type['is_paid'] as bool),
        startDate: start,
        endDate: end,
        totalDays: json['total_days'] as int,
        reason: json['reason'] as String,
        status: LeaveStatus.fromWire(json['status']),
        history: List.unmodifiable(
          ((json['history'] as List<dynamic>?) ?? const []).map(
            (h) => LeaveHistoryEntry.fromJson(h as Map<String, dynamic>),
          ),
        ),
        createdAt: json['created_at'] == null
            ? null
            : instant(json['created_at']),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected leave request shape: $e');
    }
  }

  final String publicId;

  /// The requester (always present in this API's responses).
  final LeavePerson? staff;
  final LeaveTypeRef leaveType;

  /// `YYYY-MM-DD`, company calendar dates, inclusive.
  final String startDate;
  final String endDate;

  /// Inclusive calendar days, weekends included (Phase 13).
  final int totalDays;
  final String reason;
  final LeaveStatus status;

  /// Oldest first, as the API returns it.
  final List<LeaveHistoryEntry> history;
  final DateTime? createdAt;

  /// R-6: pending can always be cancelled; approved only while it hasn't
  /// started — judged by the company day [companyToday] (R-2b), never the
  /// device clock.
  bool canSelfCancel(String companyToday) =>
      status == LeaveStatus.pending ||
      (status == LeaveStatus.approved && startDate.compareTo(companyToday) > 0);
}

/// One row of `GET /me/leave-balances` (R-9).
class LeaveBalance {
  const LeaveBalance({
    required this.leaveType,
    required this.year,
    required this.usedDays,
    required this.pendingDays,
    this.allocatedDays,
    this.remainingDays,
    this.notes,
  });

  factory LeaveBalance.fromJson(Map<String, dynamic> json) {
    try {
      final isPaid = json['is_paid'] as bool;
      final allocated = json['allocated_days'] as int?;
      final remaining = json['remaining_days'] as int?;
      if (isPaid && (allocated == null || remaining == null)) {
        throw const FormatException('A paid balance without days');
      }

      return LeaveBalance(
        leaveType: LeaveTypeRef.fromJson(
          json['leave_type'] as Map<String, dynamic>,
          isPaid: isPaid,
        ),
        year: json['year'] as int,
        allocatedDays: isPaid ? allocated : null,
        usedDays: json['used_days'] as int,
        pendingDays: json['pending_days'] as int,
        remainingDays: isPaid ? remaining : null,
        notes: json['notes'] as String?,
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected leave balance shape: $e');
    }
  }

  final LeaveTypeRef leaveType;
  final int year;

  /// Null for an unpaid type (no balance).
  final int? allocatedDays;
  final int usedDays;
  final int pendingDays;

  /// Allocated − used − pending; null for an unpaid type. Can be negative
  /// if an allocation was lowered after leave was approved.
  final int? remainingDays;
  final String? notes;

  bool get isPaid => leaveType.isPaid;
}

/// One page of `GET /me/leave-requests` with the company day (R-2d).
class MyLeaveRequestsPage {
  const MyLeaveRequestsPage({required this.companyDay, required this.page});

  factory MyLeaveRequestsPage.fromJson(Map<String, dynamic> json) {
    try {
      final meta = json['meta'] as Map<String, dynamic>;

      return MyLeaveRequestsPage(
        companyDay: ScheduleCompanyDay.fromJson(
          meta['company_day'] as Map<String, dynamic>,
        ),
        page: PagedResult.fromJson(json, LeaveRequest.fromJson),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected leave requests page shape: $e');
    }
  }

  final ScheduleCompanyDay companyDay;
  final PagedResult<LeaveRequest> page;
}

/// Which team requests to list (R-3).
enum TeamLeaveFilter {
  pending('pending', 'Pending'),
  approved('approved', 'Approved'),
  rejected('rejected', 'Rejected'),
  all('all', 'All');

  const TeamLeaveFilter(this.wireValue, this.label);

  final String wireValue;
  final String label;
}

/// One page of `GET /me/team-leave-requests` (R-3).
class TeamLeavePage {
  const TeamLeavePage({
    required this.canReview,
    required this.directReportsCount,
    required this.pendingCount,
    required this.companyDay,
    required this.page,
  });

  factory TeamLeavePage.fromJson(Map<String, dynamic> json) {
    try {
      final meta = json['meta'] as Map<String, dynamic>;

      return TeamLeavePage(
        canReview: meta['can_review'] as bool,
        directReportsCount: meta['direct_reports_count'] as int,
        pendingCount: meta['pending_count'] as int,
        companyDay: ScheduleCompanyDay.fromJson(
          meta['company_day'] as Map<String, dynamic>,
        ),
        page: PagedResult.fromJson(json, LeaveRequest.fromJson),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected team leave page shape: $e');
    }
  }

  /// The account may decide leave (`leave-requests.view`).
  final bool canReview;
  final int directReportsCount;

  /// Pending requests of my direct reports, whatever the filter.
  final int pendingCount;
  final ScheduleCompanyDay companyDay;
  final PagedResult<LeaveRequest> page;

  /// R-1: Team requests is offered to reviewers who manage someone.
  bool get hasTeam => canReview && directReportsCount > 0;
}

/// What `POST /me/leave-requests` is sent (R-5).
class LeaveDraft {
  const LeaveDraft({
    required this.leaveTypeId,
    required this.startDate,
    required this.endDate,
    required this.reason,
  });

  final String leaveTypeId;
  final String startDate;
  final String endDate;
  final String reason;

  Map<String, dynamic> toJson() => {
    'leave_type_id': leaveTypeId,
    'start_date': startDate,
    'end_date': endDate,
    'reason': reason.trim(),
  };
}

/// Inclusive calendar days from [start] to [end] (`YYYY-MM-DD`), weekends
/// included — the API's own count (Phase 13). Zero when [end] is before
/// [start].
int calendarDaysInclusive(String start, String end) {
  final s = DateTime.parse(start);
  final e = DateTime.parse(end);
  final a = DateTime.utc(s.year, s.month, s.day);
  final b = DateTime.utc(e.year, e.month, e.day);
  if (b.isBefore(a)) {
    return 0;
  }

  return b.difference(a).inDays + 1;
}

/// "1 day", "3 days", "0 days".
String daysLabel(int n) => n == 1 ? '1 day' : '$n days';
