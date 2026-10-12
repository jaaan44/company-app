import 'package:flutter/material.dart';

import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/leave/domain/leave.dart';
import 'package:mobile/features/my_status/presentation/my_status_widgets.dart'
    show companyTimeLabel;
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

/// Shared pieces of the Leave screens (Phase 31).

const leavePath = '/more/leave';
const leaveNewPath = '/more/leave/new';
const teamLeavePath = '/more/leave/team';

String myLeaveRequestPath(String publicId) =>
    '/more/leave/requests/${Uri.encodeComponent(publicId)}';

String teamLeaveRequestPath(String publicId) =>
    '/more/leave/team/${Uri.encodeComponent(publicId)}';

/// What a list passes to a request's detail: the row (so it opens with
/// content) and the company day (so Cancel is judged without a wait).
class LeaveRequestArgs {
  const LeaveRequestArgs({this.initial, this.companyDay});

  final LeaveRequest? initial;
  final ScheduleCompanyDay? companyDay;
}

/// What the Leave screen passes to Request leave.
class LeaveFormArgs {
  const LeaveFormArgs({this.balances, this.companyDate});

  final List<LeaveBalance>? balances;
  final String? companyDate;
}

/// "Tue 20 Oct 2026" for one day; "Tue 20 Oct – Thu 22 Oct 2026" within a
/// year (a request never spans two, Phase 13).
String leaveDatesLabel(String start, String end) {
  final s = DateTime.parse(start);
  final e = DateTime.parse(end);
  if (start == end) {
    return '${formatDayMonth(s)} ${s.year}';
  }
  if (s.year == e.year) {
    return '${formatDayMonth(s)} – ${formatDayMonth(e)} ${e.year}';
  }

  return '${formatDayMonth(s)} ${s.year} – ${formatDayMonth(e)} ${e.year}';
}

/// "3 calendar days (weekends included)".
String calendarDaysLabel(int days) =>
    '${days == 1 ? '1 calendar day' : '$days calendar days'} '
    '(weekends included)';

/// R-9: "10 days left of 15" (paid) or "Unpaid — no balance".
String balanceHeadline(LeaveBalance b) {
  final remaining = b.remainingDays;
  final allocated = b.allocatedDays;
  if (!b.isPaid || remaining == null || allocated == null) {
    return 'Unpaid — no balance';
  }

  return '${daysLabel(remaining)} left of $allocated';
}

/// "3 used · 2 pending".
String balanceDetail(LeaveBalance b) =>
    '${b.usedDays} used · ${b.pendingDays} pending';

/// A history step: "Approved by Grace Hopper · Today 09:12".
String historyLabel(LeaveHistoryEntry h, ScheduleCompanyDay? day) {
  final who = h.actedBy?.displayName;
  final when = h.createdAt == null || day == null
      ? null
      : companyTimeLabel(h.createdAt!, day);

  return [
    who == null ? h.action.label : '${h.action.label} by $who',
    ?when,
  ].join(' · ');
}

/// R-8: the status with its label always written; Rejected in the error
/// role, Cancelled muted.
class LeaveStatusChip extends StatelessWidget {
  const LeaveStatusChip(this.status, {super.key});

  final LeaveStatus status;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final (background, foreground) = switch (status) {
      LeaveStatus.pending => (
        scheme.secondaryContainer,
        scheme.onSecondaryContainer,
      ),
      LeaveStatus.approved => (
        scheme.tertiaryContainer,
        scheme.onTertiaryContainer,
      ),
      LeaveStatus.rejected => (scheme.errorContainer, scheme.onErrorContainer),
      LeaveStatus.cancelled ||
      LeaveStatus.unknown => (scheme.surfaceContainer, scheme.outline),
    };

    return Semantics(
      label: 'Status: ${status.label}',
      excludeSemantics: true,
      child: DecoratedBox(
        key: Key('leave-status-${status.name}'),
        decoration: BoxDecoration(
          color: background,
          borderRadius: BorderRadius.circular(8),
          border: status == LeaveStatus.cancelled
              ? Border.all(color: scheme.outlineVariant)
              : null,
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
          child: Text(
            status.label,
            style: Theme.of(context).textTheme.labelMedium
                ?.copyWith(color: foreground),
          ),
        ),
      ),
    );
  }
}

/// One request in a list: the type (or, for a team list, the person and
/// type), the dates and days, and the status beneath them.
class LeaveRequestTile extends StatelessWidget {
  const LeaveRequestTile({
    super.key,
    required this.request,
    required this.onTap,
    this.showRequester = false,
  });

  final LeaveRequest request;
  final VoidCallback onTap;
  final bool showRequester;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final requester = request.staff?.displayName;
    final title = showRequester && requester != null
        ? requester
        : request.leaveType.name;

    return ListTile(
      onTap: onTap,
      title: Text(title),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (showRequester) Text(request.leaveType.name),
          Text(
            '${leaveDatesLabel(request.startDate, request.endDate)}'
            ' · ${daysLabel(request.totalDays)}',
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          // Under the text, not beside it: at large text sizes a chip in
          // the trailing slot squeezes the row into a narrow column.
          const SizedBox(height: 4),
          LeaveStatusChip(request.status),
        ],
      ),
      trailing: const Icon(Icons.chevron_right),
    );
  }
}

/// A message banner in the error container colours.
class LeaveErrorBanner extends StatelessWidget {
  const LeaveErrorBanner(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Semantics(
      liveRegion: true,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: theme.colorScheme.errorContainer,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text(
          message,
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onErrorContainer,
          ),
        ),
      ),
    );
  }
}

/// The centred no-profile message (R-4).
class LeaveNoProfile extends StatelessWidget {
  const LeaveNoProfile({super.key});

  @override
  Widget build(BuildContext context) => const Center(
    child: Padding(
      padding: EdgeInsets.all(24),
      child: Text(
        'No staff profile is linked to this account.',
        key: Key('leave-no-profile'),
        textAlign: TextAlign.center,
      ),
    ),
  );
}
