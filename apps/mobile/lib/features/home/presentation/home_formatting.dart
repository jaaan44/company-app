import 'package:mobile/features/home/domain/home_summary.dart';

/// Display helpers for the Home screen. Every date and time is shown in
/// the *company* timezone, using the server-provided `company_day` offset
/// (spec §8), never the device's timezone. `intl` is deliberately not
/// used: it's only a transitive dependency here, and these few English
/// labels don't justify adding it.

const _weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const _months = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
];

String _two(int n) => n.toString().padLeft(2, '0');

/// "Wed 24 Sep" for a calendar date whose fields are already company-local.
String formatDayMonth(DateTime companyDate) =>
    '${_weekdays[companyDate.weekday - 1]} ${companyDate.day} '
    '${_months[companyDate.month - 1]}';

/// "22 Sep 2026".
String formatDate(DateTime companyDate) =>
    '${companyDate.day} ${_months[companyDate.month - 1]} ${companyDate.year}';

/// "09:05".
String formatTime(DateTime companyTime) =>
    '${_two(companyTime.hour)}:${_two(companyTime.minute)}';

/// "All day", "09:00 – 10:30", or — for the part of a multi-day entry that
/// falls outside the company day — "From Tue 23 Sep – 10:30" /
/// "09:00 – until Thu 25 Sep".
String formatTodayTime(TodayItem item, CompanyDay day) {
  if (item.isAllDay) {
    return 'All day';
  }

  final start = day.toCompanyTime(item.startsAt);
  final end = day.toCompanyTime(item.endsAt);
  final startsBefore = item.startsAt.isBefore(day.startsAt);
  final endsAfter = item.endsAt.isAfter(day.endsAt);

  final from = startsBefore
      ? 'From ${formatDayMonth(start)}'
      : formatTime(start);
  final until = endsAfter ? 'until ${formatDayMonth(end)}' : formatTime(end);

  return '$from – $until';
}

/// The text label that accompanies a Today item's icon (status is never
/// conveyed by the icon alone).
String todayItemLabel(TodayItem item) => switch (item.kind) {
  TodayItemKind.scheduleEntry => activityTypeLabel(item.activityType),
  TodayItemKind.task => 'Task due · ${taskStatusLabel(item.taskStatus)}',
};

String activityTypeLabel(String? value) => switch (value) {
  'meeting' => 'Meeting',
  'client_visit' => 'Client visit',
  'service_appointment' => 'Service appointment',
  'company_event' => 'Company event',
  'training' => 'Training',
  'internal_activity' => 'Internal activity',
  'other' => 'Other',
  _ => _humanize(value, fallback: 'Scheduled'),
};

String taskStatusLabel(String? value) => switch (value) {
  'todo' => 'To do',
  'in_progress' => 'In progress',
  'blocked' => 'Blocked',
  'completed' => 'Completed',
  'cancelled' => 'Cancelled',
  _ => _humanize(value, fallback: 'Task'),
};

String _humanize(String? value, {required String fallback}) {
  if (value == null || value.isEmpty) {
    return fallback;
  }

  final words = value.replaceAll('_', ' ');

  return words[0].toUpperCase() + words.substring(1);
}
