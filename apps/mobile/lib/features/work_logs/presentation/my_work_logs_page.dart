import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/work_logs/data/work_logs_api_client.dart';
import 'package:mobile/features/work_logs/domain/work_log.dart';
import 'package:mobile/features/work_logs/presentation/work_log_widgets.dart';
import 'package:mobile/features/work_logs/state/my_work_logs_controller.dart';
import 'package:mobile/features/work_logs/state/work_log_changes.dart';

/// My work logs (Phase 29B, spec §6.5; More → "My work logs"): my logs
/// grouped by date ("Today", "Yesterday", then the date), newest first,
/// with paging, pull-to-refresh and an **Add** button. Tapping a log opens
/// it for editing. After any confirmed save or delete — here or from a
/// task's detail — the list refreshes quietly (the 29A pattern).
class MyWorkLogsPage extends StatefulWidget {
  const MyWorkLogsPage({
    super.key,
    required this.workLogsApiClient,
    required this.workLogChanges,
  });

  final WorkLogsApiClient workLogsApiClient;
  final WorkLogChanges workLogChanges;

  @override
  State<MyWorkLogsPage> createState() => _MyWorkLogsPageState();
}

class _MyWorkLogsPageState extends State<MyWorkLogsPage> {
  late final MyWorkLogsController _controller = MyWorkLogsController(
    widget.workLogsApiClient,
    changes: widget.workLogChanges,
  );

  @override
  void initState() {
    super.initState();
    _controller.load();
    widget.workLogChanges.addListener(_onChange);
  }

  @override
  void dispose() {
    widget.workLogChanges.removeListener(_onChange);
    _controller.dispose();
    super.dispose();
  }

  void _onChange() => _controller.refreshIfStale();

  Future<void> _refresh() async {
    if (!await _controller.refresh() && mounted) {
      showRefreshFailed(context);
    }
  }

  void _add() => context.push(
    newWorkLogPath,
    extra: WorkLogFormArgs(companyDate: _controller.companyDay?.date),
  );

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _controller,
      builder: (context, _) => Scaffold(
        appBar: AppBar(title: const Text('My work logs')),
        floatingActionButton: _controller.status == MyWorkLogsStatus.loaded
            ? FloatingActionButton.extended(
                key: const Key('work-logs-add'),
                onPressed: _add,
                icon: const Icon(Icons.add),
                label: const Text('Add'),
              )
            : null,
        body: LayoutBuilder(
          builder: (context, constraints) =>
              _body(context, peopleListPadding(constraints)),
        ),
      ),
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    switch (_controller.status) {
      case MyWorkLogsStatus.loading:
        return const Center(child: CircularProgressIndicator());
      case MyWorkLogsStatus.error:
        return PeopleErrorView(
          message:
              _controller.errorMessage ??
              'Something went wrong loading your work logs.',
          onRetry: _controller.load,
        );
      case MyWorkLogsStatus.noProfile:
        return _message(
          const Key('work-logs-no-profile'),
          'No staff profile is linked to this account.',
        );
      case MyWorkLogsStatus.loaded:
        break;
    }

    final days = _controller.days;
    if (days.isEmpty) {
      return _message(const Key('work-logs-empty'), 'No work logged yet.');
    }

    final companyDate = _controller.companyDay?.date;
    final rows = <Widget>[
      for (final day in days) ...[
        _DayHeader(
          key: Key('work-logs-day-${day.date}'),
          day: day,
          companyDate: companyDate,
        ),
        for (final log in day.logs)
          _WorkLogRow(log: log, companyDate: companyDate),
      ],
    ];

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView.builder(
        key: const Key('work-logs-list'),
        physics: const AlwaysScrollableScrollPhysics(),
        // Room at the end so the Add button never covers the last row.
        padding: EdgeInsets.fromLTRB(padding.left, 0, padding.right, 88),
        itemCount: rows.length + (_controller.hasMore ? 1 : 0),
        itemBuilder: (context, index) => index < rows.length
            ? rows[index]
            : _LoadMoreFooter(controller: _controller),
      ),
    );
  }

  Widget _message(Key key, String text) {
    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(24),
        children: [Text(key: key, text, textAlign: TextAlign.center)],
      ),
    );
  }
}

class _DayHeader extends StatelessWidget {
  const _DayHeader({super.key, required this.day, required this.companyDate});

  final WorkLogDay day;
  final String? companyDate;

  @override
  Widget build(BuildContext context) =>
      PeopleSectionHeader(workLogDayLabel(day.date, companyDate));
}

class _WorkLogRow extends StatelessWidget {
  const _WorkLogRow({required this.log, required this.companyDate});

  final WorkLog log;
  final String? companyDate;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final secondary = theme.textTheme.bodyMedium?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final project = log.task != null ? log.project?.name : null;

    return MergeSemantics(
      child: ListTile(
        key: Key('work-log-row-${log.publicId}'),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16),
        title: Text(
          log.targetLabel,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (project != null)
              Text(
                project,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: secondary,
              ),
            Text(
              log.description,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: secondary,
            ),
          ],
        ),
        trailing: Text(
          formatDuration(log.durationMinutes),
          style: theme.textTheme.titleSmall,
        ),
        onTap: () => context.push(
          editWorkLogPath(log.publicId),
          extra: WorkLogFormArgs(existing: log, companyDate: companyDate),
        ),
      ),
    );
  }
}

/// The Phase 28 paging footer: asks for the next page when built; after a
/// failure it becomes a "tap to retry" row.
class _LoadMoreFooter extends StatelessWidget {
  const _LoadMoreFooter({required this.controller});

  final MyWorkLogsController controller;

  @override
  Widget build(BuildContext context) {
    if (controller.loadMoreFailed) {
      return ListTile(
        key: const Key('work-logs-load-more-retry'),
        leading: const Icon(Icons.refresh),
        title: const Text("Couldn't load more. Tap to retry."),
        onTap: controller.loadMore,
      );
    }

    if (!controller.isLoadingMore) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        controller.loadMore();
      });
    }

    return const Padding(
      key: Key('work-logs-loading-more'),
      padding: EdgeInsets.all(16),
      child: Center(child: CircularProgressIndicator()),
    );
  }
}
