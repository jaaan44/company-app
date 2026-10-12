import 'package:flutter/material.dart';

import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/people/state/resource_controller.dart';
import 'package:mobile/features/schedule/data/schedule_api_client.dart';
import 'package:mobile/features/schedule/domain/schedule.dart';
import 'package:mobile/features/schedule/presentation/schedule_widgets.dart';
import 'package:mobile/features/schedule/state/schedule_entry_controller.dart';

/// One schedule entry, read-only (Phase 30A, R-10): title and type, when
/// (in company time), project, organizer, people and description. No
/// editing on mobile (R-9).
class ScheduleEntryPage extends StatefulWidget {
  const ScheduleEntryPage({
    super.key,
    required this.scheduleApiClient,
    required this.publicId,
    this.args,
  });

  final ScheduleApiClient scheduleApiClient;
  final String publicId;
  final ScheduleEntryArgs? args;

  @override
  State<ScheduleEntryPage> createState() => _ScheduleEntryPageState();
}

class _ScheduleEntryPageState extends State<ScheduleEntryPage> {
  late final ScheduleEntryController _controller = ScheduleEntryController(
    widget.scheduleApiClient,
    widget.publicId,
  );

  @override
  void initState() {
    super.initState();
    _controller.load();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _refresh() async {
    if (!await _controller.refresh() && mounted) {
      showRefreshFailed(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Schedule')),
      body: ListenableBuilder(
        listenable: _controller,
        builder: (context, _) {
          final entry = _controller.data;
          if (entry != null) {
            return RefreshIndicator(
              onRefresh: _refresh,
              child: _Content(entry: entry, utcOffset: widget.args?.utcOffset),
            );
          }
          if (_controller.status == ResourceStatus.error) {
            return PeopleErrorView(
              message:
                  _controller.errorMessage ??
                  'Something went wrong loading this item.',
              onRetry: _controller.load,
            );
          }

          return const Center(child: CircularProgressIndicator());
        },
      ),
    );
  }
}

class _Content extends StatelessWidget {
  const _Content({required this.entry, required this.utcOffset});

  final ScheduleEntryDetail entry;

  /// Null only for a restored deep link: times are then device time.
  final Duration? utcOffset;

  DateTime _local(DateTime instant) =>
      utcOffset == null ? instant.toLocal() : instant.toUtc().add(utcOffset!);

  String _when() {
    final start = _local(entry.startsAt);
    final end = _local(entry.endsAt);
    final sameDay =
        start.year == end.year &&
        start.month == end.month &&
        start.day == end.day;
    final text = entry.isAllDay
        ? (sameDay
              ? '${formatDayMonth(start)} · All day'
              : '${formatDayMonth(start)} – ${formatDayMonth(end)} · All day')
        : sameDay
        ? '${formatDayMonth(start)} · ${formatTime(start)} – ${formatTime(end)}'
        : '${formatDayMonth(start)} ${formatTime(start)} – '
              '${formatDayMonth(end)} ${formatTime(end)}';

    return utcOffset == null ? '$text (device time)' : text;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final description = entry.description?.trim() ?? '';

    return LayoutBuilder(
      builder: (context, constraints) => ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: peopleListPadding(constraints),
        children: [
          Padding(
            key: const Key('schedule-entry-header'),
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Semantics(
                  header: true,
                  child: Text(
                    entry.title,
                    style: theme.textTheme.headlineSmall,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  activityTypeLabel(entry.activityType),
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
          PeopleInfoRow(
            key: const Key('schedule-entry-when'),
            icon: Icons.schedule,
            label: 'When',
            value: _when(),
          ),
          PeopleInfoRow(
            icon: Icons.folder_outlined,
            label: 'Project',
            value: entry.project?.name,
          ),
          PeopleInfoRow(
            icon: Icons.person_outline,
            label: 'Organizer',
            value: entry.creator?.displayName,
          ),
          PeopleSectionHeader('People (${entry.participants.length})'),
          if (entry.participants.isEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
              child: Text(
                'No other people.',
                key: const Key('schedule-entry-no-people'),
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
          for (final person in entry.participants)
            ListTile(
              key: Key('schedule-entry-person-${person.publicId}'),
              leading: const Icon(Icons.person_outline),
              title: Text(person.displayName),
            ),
          if (description.isNotEmpty) ...[
            const PeopleSectionHeader('Description'),
            Padding(
              key: const Key('schedule-entry-description'),
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
              child: Text(description),
            ),
          ],
        ],
      ),
    );
  }
}
