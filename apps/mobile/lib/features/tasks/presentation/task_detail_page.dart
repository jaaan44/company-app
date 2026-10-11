import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/projects/presentation/project_widgets.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';
import 'package:mobile/features/tasks/domain/task_item.dart';
import 'package:mobile/features/tasks/presentation/task_widgets.dart';
import 'package:mobile/features/tasks/state/task_changes.dart';
import 'package:mobile/features/tasks/state/task_detail_controller.dart';
import 'package:mobile/features/work_logs/domain/work_log.dart';
import 'package:mobile/features/work_logs/presentation/work_log_widgets.dart';

/// One task (Phase 29A, spec §5.3): its details and — unless it was
/// cancelled — a status control offering To do, In progress, Blocked and
/// Completed (R-4: never Cancelled; R-5: status is the only edit).
///
/// Saving is confirmed, not optimistic (R-7): the selected chip moves only
/// when the server accepts the change; while saving the control is
/// disabled with a progress bar; a failure leaves the old status and shows
/// why underneath. See [TaskDetailController] for the 403/422 rules.
///
/// Phase 29B adds "Log work" (R-15), opening the work-log form with this
/// task fixed, inside the Tasks branch.
class TaskDetailPage extends StatefulWidget {
  const TaskDetailPage({
    super.key,
    required this.tasksApiClient,
    required this.taskChanges,
    required this.publicId,
    this.args,
  });

  final TasksApiClient tasksApiClient;
  final TaskChanges taskChanges;
  final String publicId;
  final TaskDetailArgs? args;

  @override
  State<TaskDetailPage> createState() => _TaskDetailPageState();
}

class _TaskDetailPageState extends State<TaskDetailPage> {
  late final TaskDetailController _controller = TaskDetailController(
    widget.tasksApiClient,
    widget.publicId,
    initial: widget.args?.initial,
    companyDate: widget.args?.companyDate,
    changes: widget.taskChanges,
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

  Future<void> _save(TaskStatus status) async {
    final saved = await _controller.saveStatus(status);
    if (saved && mounted) {
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(
          SnackBar(content: Text('Status changed to ${status.label}.')),
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Task')),
      body: ListenableBuilder(
        listenable: _controller,
        builder: (context, _) {
          final task = _controller.data;

          if (task != null) {
            return RefreshIndicator(
              onRefresh: _refresh,
              child: LayoutBuilder(
                builder: (context, constraints) =>
                    _content(context, task, peopleListPadding(constraints)),
              ),
            );
          }

          if (_controller.status == TaskDetailStatus.error) {
            return PeopleErrorView(
              message:
                  _controller.errorMessage ??
                  'Something went wrong loading this task.',
              onRetry: _controller.load,
            );
          }

          return const Center(child: CircularProgressIndicator());
        },
      ),
    );
  }

  Widget _content(BuildContext context, TaskItem task, EdgeInsets padding) {
    final theme = Theme.of(context);
    final companyDate = _controller.companyDate;

    return ListView(
      key: const Key('task-detail'),
      physics: const AlwaysScrollableScrollPhysics(),
      padding: EdgeInsets.fromLTRB(padding.left, 16, padding.right, 24),
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Semantics(
                header: true,
                child: Text(
                  task.title,
                  key: const Key('task-title'),
                  style: theme.textTheme.headlineSmall,
                ),
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 12,
                runSpacing: 4,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  TaskStatusChip(task.status),
                  Text(
                    'Priority: ${task.priority.label}',
                    style: theme.textTheme.bodyMedium,
                  ),
                ],
              ),
            ],
          ),
        ),
        const PeopleSectionHeader('Status'),
        _StatusControl(controller: _controller, onSelected: _save),
        // Log work against this task (Phase 29B, R-15) — not for a
        // cancelled task.
        if (task.status != TaskStatus.cancelled)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: OutlinedButton.icon(
                key: const Key('task-log-work'),
                icon: const Icon(Icons.more_time),
                label: const Text('Log work'),
                onPressed: () => context.push(
                  logWorkOnTaskPath(task.publicId),
                  extra: WorkLogFormArgs(
                    fixedTarget: TaskTarget(
                      publicId: task.publicId,
                      label: task.title,
                      projectName: task.project?.name,
                    ),
                    companyDate: companyDate,
                  ),
                ),
              ),
            ),
          ),
        const PeopleSectionHeader('Details'),
        PeopleInfoRow(
          key: const Key('task-due'),
          icon: Icons.event_outlined,
          label: 'Due date',
          value: _dueValue(task, companyDate),
        ),
        PeopleInfoRow(
          key: const Key('task-project'),
          icon: Icons.folder_outlined,
          label: 'Project',
          value: task.project?.name ?? 'Independent task',
          // Phase 29C (R-25): the project opens in the More tab, where its
          // client and members link on; this tab keeps the task open.
          onTap: switch (task.project) {
            final project? => () => context.go(projectPath(project.publicId)),
            null => null,
          },
        ),
        PeopleInfoRow(
          icon: Icons.person_outline,
          label: 'Created by',
          value: task.createdBy?.name,
        ),
        if (task.completedAt case final completed?)
          PeopleInfoRow(
            key: const Key('task-completed-at'),
            icon: Icons.check_circle_outline,
            label: 'Completed',
            // Device local time: `/me/tasks` and `/tasks/{id}` give the
            // company timezone's name but no offset (a recorded limitation).
            value: formatDate(completed.toLocal()),
          ),
        const PeopleSectionHeader('Description'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Text(
            key: const Key('task-description'),
            (task.description?.trim().isNotEmpty ?? false)
                ? task.description!
                : 'No description.',
            style: (task.description?.trim().isNotEmpty ?? false)
                ? theme.textTheme.bodyLarge
                : theme.textTheme.bodyLarge?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
          ),
        ),
      ],
    );
  }

  /// "30 Sep 2026", with " · Overdue" or " · Due today" when the company
  /// day is known; null (shown as "Not set") without a due date.
  String? _dueValue(TaskItem task, String? companyDate) {
    final due = task.dueDate;
    if (due == null) {
      return null;
    }

    final date = formatDate(DateTime.parse(due));

    return switch (task.dueState(companyDate: companyDate)) {
      TaskDueState.overdue => '$date · Overdue',
      TaskDueState.dueToday => '$date · Due today',
      _ => date,
    };
  }
}

class _StatusControl extends StatelessWidget {
  const _StatusControl({required this.controller, required this.onSelected});

  final TaskDetailController controller;
  final ValueChanged<TaskStatus> onSelected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final task = controller.data!;
    final message = controller.saveMessage;

    if (!controller.showsStatusControl) {
      return Padding(
        key: const Key('task-status-read-only'),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Text(
          "This task was cancelled. Its status can't be changed here.",
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
      );
    }

    final enabled = controller.canChangeStatus;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            key: const Key('task-status-control'),
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final status in TaskStatus.selectable)
                ChoiceChip(
                  key: Key('task-set-${status.wireValue}'),
                  label: Text(status.label),
                  selected: task.status == status,
                  onSelected: enabled && task.status != status
                      ? (_) => onSelected(status)
                      : null,
                ),
            ],
          ),
          if (controller.isSaving) ...[
            const SizedBox(height: 12),
            const LinearProgressIndicator(key: Key('task-saving')),
          ],
          if (message != null) ...[
            const SizedBox(height: 12),
            Semantics(
              liveRegion: true,
              child: Text(
                message,
                key: const Key('task-save-message'),
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
