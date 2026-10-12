import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/schedule/domain/schedule.dart';

/// Shared pieces for the Schedule screens (Phase 30A,
/// docs/phases/V1_PHASE_30_DEFINITION.md §5.3).

/// The entry detail's route.
String scheduleEntryPath(String publicId) =>
    '/schedule/entries/${Uri.encodeComponent(publicId)}';

/// What a route to the entry detail carries: the company timezone's
/// offset, so times read in company time. Both entry points (the Schedule
/// tab and Home) pass it; without it (a restored deep link) times are
/// shown in the device's time and say so.
class ScheduleEntryArgs {
  const ScheduleEntryArgs({required this.utcOffset});

  final Duration utcOffset;
}

/// "Today", "Tomorrow", "Yesterday", otherwise "Wed 14 Oct" — judged
/// against the server's company today, never the device clock.
String scheduleDayLabel(String date, String companyToday) {
  if (date == companyToday) {
    return 'Today';
  }
  if (date == shiftDate(companyToday, 1)) {
    return 'Tomorrow';
  }
  if (date == shiftDate(companyToday, -1)) {
    return 'Yesterday';
  }

  return formatDayMonth(DateTime.parse(date));
}

/// "14 – 20 Oct", or "28 Oct – 3 Nov" across months.
String weekRangeLabel(String first, String last) {
  final a = DateTime.parse(first);
  final b = DateTime.parse(last);

  return a.month == b.month
      ? '${a.day} – ${formatShortDate(b)}'
      : '${formatShortDate(a)} – ${formatShortDate(b)}';
}

/// An item's time on one company day: "All day", "09:00 – 10:30", or for
/// the part of a multi-day item outside that day "From Tue 13 Oct – 10:30"
/// / "09:00 – until Thu 15 Oct".
String scheduleItemTime(
  ScheduleItem item,
  String date,
  ScheduleCompanyDay companyDay,
) {
  if (item.isAllDay) {
    return 'All day';
  }
  final (dayStart, dayEnd) = companyDay.boundsOf(date);
  final start = companyDay.toCompanyTime(item.startsAt);
  final end = companyDay.toCompanyTime(item.endsAt);
  final from = item.startsAt.isBefore(dayStart)
      ? 'From ${formatDayMonth(start)}'
      : formatTime(start);
  final until = item.endsAt.isAfter(dayEnd)
      ? 'until ${formatDayMonth(end)}'
      : formatTime(end);

  return '$from – $until';
}

/// The text label beside an item's icon (never the icon alone).
String scheduleItemLabel(ScheduleItem item) => switch (item.source) {
  ScheduleSource.scheduleEntry => activityTypeLabel(item.activityType),
  ScheduleSource.task => 'Task · ${taskStatusLabel(item.taskStatus)}',
  ScheduleSource.leave => 'Leave',
};
