import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/work_logs/domain/work_log.dart';

/// Shared pieces for the work-log screens (Phase 29B,
/// docs/phases/V1_PHASE_29_DEFINITION.md §6.5).

/// What a route to the work-log form may carry. All optional: without a
/// company day the form asks the server for it (R-12).
class WorkLogFormArgs {
  const WorkLogFormArgs({this.existing, this.fixedTarget, this.companyDate});

  /// The log to edit, or null to add one.
  final WorkLog? existing;

  /// The task the log is for when opened from a task's detail (R-15).
  final WorkTarget? fixedTarget;

  /// `YYYY-MM-DD` in the company timezone.
  final String? companyDate;
}

/// Routes.
const myWorkLogsPath = '/more/work-logs';
const newWorkLogPath = '/more/work-logs/new';

String editWorkLogPath(String publicId) =>
    '/more/work-logs/${Uri.encodeComponent(publicId)}';

String logWorkOnTaskPath(String taskPublicId) =>
    '/tasks/${Uri.encodeComponent(taskPublicId)}/log-work';

/// A date group's header (spec §6.5): "Today", "Yesterday", otherwise
/// "Wed 7 Oct" — with the year when it isn't the company year. Judged
/// against the server's company day, never the device clock.
String workLogDayLabel(String date, String? companyDate) {
  if (companyDate != null) {
    if (date == companyDate) {
      return 'Today';
    }
    if (date == shiftApiDate(companyDate, -1)) {
      return 'Yesterday';
    }
  }

  final d = DateTime.parse(date);
  final label = formatDayMonth(d);
  final companyYear = companyDate == null
      ? null
      : int.parse(companyDate.substring(0, 4));

  return companyYear == null || companyYear == d.year
      ? label
      : '$label ${d.year}';
}

/// "Wed 7 Oct 2026" for a form's date field.
String workLogDateLabel(String date) {
  final d = DateTime.parse(date);

  return '${formatDayMonth(d)} ${d.year}';
}

/// [date] moved by [days] (calendar arithmetic, in UTC).
String shiftApiDate(String date, int days) {
  final p = DateTime.parse(date);

  return apiDateOf(DateTime.utc(p.year, p.month, p.day + days));
}

/// `YYYY-MM-DD` for a calendar date.
String apiDateOf(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-'
    '${d.month.toString().padLeft(2, '0')}-'
    '${d.day.toString().padLeft(2, '0')}';

/// A calendar date as the local midnight `DateTime` the date picker uses.
DateTime pickerDateOf(String date) {
  final p = DateTime.parse(date);

  return DateTime(p.year, p.month, p.day);
}
