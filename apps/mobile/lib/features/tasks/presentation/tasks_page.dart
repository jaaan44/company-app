import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';
import 'package:mobile/features/tasks/domain/task_item.dart';
import 'package:mobile/features/tasks/presentation/task_widgets.dart';
import 'package:mobile/features/tasks/state/my_tasks_controller.dart';
import 'package:mobile/features/tasks/state/task_changes.dart';

/// The Tasks tab (Phase 29A, spec §5.3): the tasks assigned to me, split
/// into **Open** and **Done** (`/me/tasks?state=open|closed`), in server
/// order, with "load more" paging and pull-to-refresh (the Phase 28
/// conventions). Each row opens the task.
///
/// Each segment has its own controller, so switching keeps both lists;
/// Done loads the first time it is shown. After a confirmed status change
/// ([TaskChanges]) each loaded segment applies it at once and quietly
/// refreshes, so the lists are current when shown again.
class TasksPage extends StatefulWidget {
  const TasksPage({
    super.key,
    required this.tasksApiClient,
    required this.taskChanges,
  });

  final TasksApiClient tasksApiClient;
  final TaskChanges taskChanges;

  @override
  State<TasksPage> createState() => _TasksPageState();
}

class _TasksPageState extends State<TasksPage> {
  late final MyTasksController _open = MyTasksController(
    widget.tasksApiClient,
    state: TaskListState.open,
    changes: widget.taskChanges,
  );
  late final MyTasksController _done = MyTasksController(
    widget.tasksApiClient,
    state: TaskListState.closed,
    changes: widget.taskChanges,
  );

  TaskListState _segment = TaskListState.open;
  bool _doneStarted = false;

  MyTasksController get _current =>
      _segment == TaskListState.open ? _open : _done;

  @override
  void initState() {
    super.initState();
    _open.load();
    widget.taskChanges.addListener(_onTaskChange);
  }

  @override
  void dispose() {
    widget.taskChanges.removeListener(_onTaskChange);
    _open.dispose();
    _done.dispose();
    super.dispose();
  }

  void _onTaskChange() {
    _open.refreshIfStale();
    if (_doneStarted) {
      _done.refreshIfStale();
    }
  }

  void _select(TaskListState segment) {
    setState(() => _segment = segment);
    if (segment == TaskListState.closed && !_doneStarted) {
      _doneStarted = true;
      _done.load();
    }
  }

  Future<void> _refresh() async {
    if (!await _current.refresh() && mounted) {
      showRefreshFailed(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Tasks')),
      body: LayoutBuilder(
        builder: (context, constraints) {
          final padding = peopleListPadding(constraints);

          return Column(
            children: [
              Padding(
                padding: EdgeInsets.fromLTRB(padding.left, 8, padding.right, 8),
                child: SizedBox(
                  width: double.infinity,
                  child: SegmentedButton<TaskListState>(
                    key: const Key('tasks-segments'),
                    showSelectedIcon: false,
                    segments: const [
                      ButtonSegment(
                        value: TaskListState.open,
                        label: Text('Open'),
                      ),
                      ButtonSegment(
                        value: TaskListState.closed,
                        label: Text('Done'),
                      ),
                    ],
                    selected: {_segment},
                    onSelectionChanged: (selection) => _select(selection.first),
                  ),
                ),
              ),
              Expanded(
                child: ListenableBuilder(
                  listenable: _current,
                  builder: (context, _) => _body(context, padding),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    final controller = _current;

    switch (controller.status) {
      case MyTasksStatus.loading:
        return const Center(child: CircularProgressIndicator());
      case MyTasksStatus.error:
        return PeopleErrorView(
          message:
              controller.errorMessage ??
              'Something went wrong loading your tasks.',
          onRetry: controller.load,
        );
      case MyTasksStatus.noProfile:
        return _message(
          const Key('tasks-no-profile'),
          'No staff profile is linked to this account.',
        );
      case MyTasksStatus.loaded:
        break;
    }

    final items = controller.items;

    if (items.isEmpty) {
      return _message(
        const Key('tasks-empty'),
        _segment == TaskListState.open
            ? 'No open tasks assigned to you.'
            : 'No completed tasks yet.',
      );
    }

    final companyDate = controller.companyDay?.date;

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView.builder(
        key: Key('tasks-list-${_segment.wireValue}'),
        physics: const AlwaysScrollableScrollPhysics(),
        padding: EdgeInsets.fromLTRB(padding.left, 0, padding.right, 16),
        itemCount: items.length + (controller.hasMore ? 1 : 0),
        itemBuilder: (context, index) => index < items.length
            ? _TaskRow(task: items[index], companyDate: companyDate)
            : _LoadMoreFooter(controller: controller),
      ),
    );
  }

  /// A pull-to-refreshable message (empty and no-profile states).
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

class _TaskRow extends StatelessWidget {
  const _TaskRow({required this.task, required this.companyDate});

  final TaskItem task;
  final String? companyDate;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final project = task.project?.name;

    return ListTile(
      key: Key('task-row-${task.publicId}'),
      contentPadding: const EdgeInsets.symmetric(horizontal: 8),
      title: Text(task.title, maxLines: 2, overflow: TextOverflow.ellipsis),
      subtitle: Padding(
        padding: const EdgeInsets.only(top: 4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (project != null)
              Text(
                project,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            const SizedBox(height: 4),
            Wrap(
              spacing: 8,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                TaskStatusChip(task.status),
                TaskDueText(task, companyDate: companyDate),
              ],
            ),
          ],
        ),
      ),
      trailing: const Icon(Icons.chevron_right),
      onTap: () => context.push(
        taskDetailPath(task.publicId),
        extra: TaskDetailArgs(initial: task, companyDate: companyDate),
      ),
    );
  }
}

/// The last row while more pages remain — the Phase 28 directory footer:
/// being built asks for the next page; after a failure it becomes a "tap
/// to retry" row instead of retrying on its own.
class _LoadMoreFooter extends StatelessWidget {
  const _LoadMoreFooter({required this.controller});

  final MyTasksController controller;

  @override
  Widget build(BuildContext context) {
    if (controller.loadMoreFailed) {
      return ListTile(
        key: const Key('tasks-load-more-retry'),
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
      key: Key('tasks-loading-more'),
      padding: EdgeInsets.all(16),
      child: Center(child: CircularProgressIndicator()),
    );
  }
}
