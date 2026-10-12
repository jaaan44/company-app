import 'package:flutter/material.dart';

import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/my_status/domain/my_status.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay, shiftDate;

/// Shared pieces for the My status screens (Phase 30B,
/// docs/phases/V1_PHASE_30_DEFINITION.md §6.5).

const myStatusPath = '/more/status';
const myCheckInsPath = '/more/status/check-ins';
const myStatusHistoryPath = '/more/status/history';
const checkInPath = '/more/status/check-in';

/// Always shown with the status's label, never alone (R-16).
IconData statusIcon(OperationalStatus status) => switch (status) {
  OperationalStatus.available => Icons.check_circle_outline,
  OperationalStatus.busy => Icons.do_not_disturb_on_outlined,
  OperationalStatus.inMeeting => Icons.groups_outlined,
  OperationalStatus.inField => Icons.directions_walk,
  OperationalStatus.offDuty => Icons.bedtime_outlined,
  OperationalStatus.unknown => Icons.help_outline,
};

/// An instant in company time (R-21): "Today 09:12", "Yesterday 18:40",
/// "Mon 12 Oct 09:12", or with the year when it isn't this year. "Today"
/// is the server's company day, never the device's.
String companyTimeLabel(DateTime instant, ScheduleCompanyDay day) {
  final local = day.toCompanyTime(instant);
  final date =
      '${local.year.toString().padLeft(4, '0')}-'
      '${local.month.toString().padLeft(2, '0')}-'
      '${local.day.toString().padLeft(2, '0')}';
  final time = formatTime(local);

  if (date == day.date) {
    return 'Today $time';
  }
  if (date == shiftDate(day.date, -1)) {
    return 'Yesterday $time';
  }

  return local.year == DateTime.parse(day.date).year
      ? '${formatDayMonth(local)} $time'
      : '${formatDate(local)} $time';
}

/// "±12 m".
String accuracyLabel(num meters) => '±${meters.round()} m';

/// "14.55471, 121.02445" — five decimals (about a metre).
String coordinatesLabel(double latitude, double longitude) =>
    '${latitude.toStringAsFixed(5)}, ${longitude.toStringAsFixed(5)}';

/// One status change: its icon and label, and when.
class StatusRow extends StatelessWidget {
  const StatusRow({super.key, required this.entry, required this.companyDay});

  final StatusEntry entry;
  final ScheduleCompanyDay companyDay;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: Icon(statusIcon(entry.status)),
      title: Text(entry.status.label),
      subtitle: Text(companyTimeLabel(entry.recordedAt, companyDay)),
    );
  }
}

/// One check-in, read-only (R-21): the place (or "No place name"), when,
/// how accurate, the note and the coordinates. No map.
class CheckInRow extends StatelessWidget {
  const CheckInRow({
    super.key,
    required this.checkIn,
    required this.companyDay,
  });

  final CheckIn checkIn;
  final ScheduleCompanyDay companyDay;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final label = checkIn.locationLabel?.trim() ?? '';
    final note = checkIn.note?.trim() ?? '';
    final when = [
      companyTimeLabel(checkIn.checkedInAt, companyDay),
      if (checkIn.accuracyMeters != null)
        accuracyLabel(checkIn.accuracyMeters!),
    ].join(' · ');

    return ListTile(
      leading: const Icon(Icons.place_outlined),
      title: Text(label.isEmpty ? 'No place name' : label),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(when),
          if (note.isNotEmpty) Text(note),
          Text(
            coordinatesLabel(checkIn.latitude, checkIn.longitude),
            style: muted,
          ),
        ],
      ),
    );
  }
}
