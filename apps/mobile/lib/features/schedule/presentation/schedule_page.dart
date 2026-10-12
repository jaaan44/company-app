import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/schedule/data/schedule_api_client.dart';
import 'package:mobile/features/schedule/domain/schedule.dart';
import 'package:mobile/features/schedule/presentation/schedule_widgets.dart';
import 'package:mobile/features/schedule/state/my_schedule_controller.dart';
import 'package:mobile/features/tasks/presentation/task_widgets.dart'
    show TaskDetailArgs, taskDetailPath;
import 'package:mobile/features/tasks/state/task_changes.dart';

/// The Schedule tab (Phase 30A, docs/phases/V1_PHASE_30_DEFINITION.md
/// §5.3, R-8…R-10): my week — seven days from the company today, each
/// headed "Today", "Tomorrow" or "Wed 14 Oct", with my meetings, tasks due
/// and leave in the server's order, "Nothing scheduled." for an empty day,
/// Previous week / Next week / Today, and pull to refresh. Read-only
/// (R-9): a task opens its detail in the Tasks tab, a meeting its entry
/// detail; leave isn't tappable.
///
/// Loads once (the shell keeps the branch mounted); refreshes on pull, and
/// quietly after a confirmed task status change ([taskChanges]) — as Home
/// does — so a task completed from here is gone when the tab shows again.
class SchedulePage extends StatefulWidget {
  const SchedulePage({
    super.key,
    required this.scheduleApiClient,
    this.taskChanges,
    this.clock,
  });

  final ScheduleApiClient scheduleApiClient;
  final TaskChanges? taskChanges;

  /// The device clock, for tests (only used to guess the first range).
  final DateTime Function()? clock;

  @override
  State<SchedulePage> createState() => _SchedulePageState();
}

class _SchedulePageState extends State<SchedulePage> {
  late final MyScheduleController _controller = MyScheduleController(
    widget.scheduleApiClient,
    clock: widget.clock,
  );

  @override
  void initState() {
    super.initState();
    _controller.load();
    widget.taskChanges?.addListener(_onTaskChange);
  }

  @override
  void dispose() {
    widget.taskChanges?.removeListener(_onTaskChange);
    _controller.dispose();
    super.dispose();
  }

  void _onTaskChange() {
    if (_controller.status == ScheduleStatus.loaded) {
      _controller.refresh();
    } else {
      _controller.retry();
    }
  }

  Future<void> _refresh() async {
    if (!await _controller.refresh() && mounted) {
      showRefreshFailed(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _controller,
      builder: (context, _) => Scaffold(
        appBar: AppBar(
          title: const Text('Schedule'),
          actions: [
            if (_controller.status == ScheduleStatus.loaded ||
                _controller.weekStart != null)
              TextButton(
                key: const Key('schedule-today'),
                onPressed: _controller.isCurrentWeek ? null : _controller.today,
                child: const Text('Today'),
              ),
          ],
        ),
        body: LayoutBuilder(
          builder: (context, constraints) =>
              _body(context, peopleListPadding(constraints)),
        ),
      ),
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    switch (_controller.status) {
      case ScheduleStatus.loading:
        return const Center(child: CircularProgressIndicator());
      case ScheduleStatus.error:
        return PeopleErrorView(
          message:
              _controller.errorMessage ??
              'Something went wrong loading your schedule.',
          onRetry: _controller.retry,
        );
      case ScheduleStatus.noProfile:
        return RefreshIndicator(
          onRefresh: _refresh,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(24),
            children: const [
              Text(
                key: Key('schedule-no-profile'),
                'No staff profile is linked to this account.',
                textAlign: TextAlign.center,
              ),
            ],
          ),
        );
      case ScheduleStatus.loaded:
        break;
    }

    final companyDay = _controller.companyDay!;
    final days = _controller.days;

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView(
        key: const Key('schedule-list'),
        physics: const AlwaysScrollableScrollPhysics(),
        padding: EdgeInsets.fromLTRB(padding.left, 0, padding.right, 16),
        children: [
          _WeekBar(
            label: weekRangeLabel(days.first.date, days.last.date),
            onPrevious: _controller.previousWeek,
            onNext: _controller.nextWeek,
          ),
          for (final day in days) ...[
            _DayHeader(
              key: Key('schedule-day-${day.date}'),
              label: scheduleDayLabel(day.date, companyDay.date),
              isToday: day.date == companyDay.date,
            ),
            if (day.items.isEmpty)
              Padding(
                key: Key('schedule-empty-${day.date}'),
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                child: Text(
                  'Nothing scheduled.',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
                ),
              ),
            for (final item in day.items)
              _ItemRow(
                key: Key('schedule-item-${day.date}-${item.publicId}'),
                item: item,
                date: day.date,
                companyDay: companyDay,
              ),
          ],
        ],
      ),
    );
  }
}

class _WeekBar extends StatelessWidget {
  const _WeekBar({
    required this.label,
    required this.onPrevious,
    required this.onNext,
  });

  final String label;
  final VoidCallback onPrevious;
  final VoidCallback onNext;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          IconButton(
            key: const Key('schedule-previous'),
            icon: const Icon(Icons.chevron_left),
            tooltip: 'Previous week',
            onPressed: onPrevious,
          ),
          Expanded(
            child: Text(
              label,
              key: const Key('schedule-week'),
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium,
            ),
          ),
          IconButton(
            key: const Key('schedule-next'),
            icon: const Icon(Icons.chevron_right),
            tooltip: 'Next week',
            onPressed: onNext,
          ),
        ],
      ),
    );
  }
}

class _DayHeader extends StatelessWidget {
  const _DayHeader({super.key, required this.label, required this.isToday});

  final String label;
  final bool isToday;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
      child: Semantics(
        header: true,
        child: Text(
          label,
          style: theme.textTheme.titleSmall?.copyWith(
            color: isToday
                ? theme.colorScheme.primary
                : theme.colorScheme.onSurface,
            fontWeight: isToday ? FontWeight.bold : null,
          ),
        ),
      ),
    );
  }
}

class _ItemRow extends StatelessWidget {
  const _ItemRow({
    super.key,
    required this.item,
    required this.date,
    required this.companyDay,
  });

  final ScheduleItem item;
  final String date;
  final ScheduleCompanyDay companyDay;

  @override
  Widget build(BuildContext context) {
    final details = [
      scheduleItemTime(item, date, companyDay),
      scheduleItemLabel(item),
      if (item.project != null) item.project!.name,
    ].join(' · ');

    final VoidCallback? onTap = switch (item.source) {
      ScheduleSource.task => () => context.go(
        taskDetailPath(item.publicId),
        extra: TaskDetailArgs(companyDate: companyDay.date),
      ),
      ScheduleSource.scheduleEntry => () => context.push(
        scheduleEntryPath(item.publicId),
        extra: ScheduleEntryArgs(utcOffset: companyDay.utcOffset),
      ),
      ScheduleSource.leave => null,
    };

    return ListTile(
      leading: Icon(switch (item.source) {
        ScheduleSource.scheduleEntry => Icons.event,
        ScheduleSource.task => Icons.task_alt,
        ScheduleSource.leave => Icons.beach_access_outlined,
      }),
      title: Text(item.title),
      subtitle: Text(details),
      trailing: onTap == null ? null : const Icon(Icons.chevron_right),
      onTap: onTap,
    );
  }
}
