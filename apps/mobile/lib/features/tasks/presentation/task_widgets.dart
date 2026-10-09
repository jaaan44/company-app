import 'package:flutter/material.dart';

import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/tasks/domain/task_item.dart';

/// Shared building blocks for the Tasks screens (Phase 29A,
/// docs/phases/V1_PHASE_29_DEFINITION.md §5.3).

/// What a route to `/tasks/:publicId` may carry: the list row's item (so
/// the detail opens with content) and the server-reported company date
/// its due state is judged against. Both are optional — a detail opened
/// without them loads the task and shows its due date without a flag.
class TaskDetailArgs {
  const TaskDetailArgs({this.initial, this.companyDate});

  final TaskItem? initial;

  /// `YYYY-MM-DD` in the company timezone, from `/me/tasks` or `/me/home`.
  final String? companyDate;
}

/// The route of one task's detail screen.
String taskDetailPath(String publicId) =>
    '/tasks/${Uri.encodeComponent(publicId)}';

/// `YYYY-MM-DD` for a calendar date (Home's `company_day.date`).
String apiDate(DateTime date) =>
    '${date.year.toString().padLeft(4, '0')}-'
    '${date.month.toString().padLeft(2, '0')}-'
    '${date.day.toString().padLeft(2, '0')}';

/// The list row's due label (spec §5.3): "Due today", "Overdue · 3 Sep",
/// "Due 12 Oct", or null without a due date. The year is added when it
/// differs from the company year, or when the company day is unknown.
String? taskDueLabel(TaskItem task, {String? companyDate}) {
  final due = task.dueDate;
  if (due == null) {
    return null;
  }

  final date = DateTime.parse(due);
  final companyYear = companyDate == null
      ? null
      : int.tryParse(companyDate.substring(0, 4));
  final short = companyYear == date.year
      ? formatShortDate(date)
      : formatDate(date);

  return switch (task.dueState(companyDate: companyDate)) {
    TaskDueState.dueToday => 'Due today',
    TaskDueState.overdue => 'Overdue · $short',
    _ => 'Due $short',
  };
}

/// A task's status as a coloured chip that always carries its text label
/// (colour is never the only signal — the accessibility baseline).
class TaskStatusChip extends StatelessWidget {
  const TaskStatusChip(this.status, {super.key});

  final TaskStatus status;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final (background, foreground) = switch (status) {
      TaskStatus.todo => (
        scheme.surfaceContainerHighest,
        scheme.onSurfaceVariant,
      ),
      TaskStatus.inProgress => (
        scheme.primaryContainer,
        scheme.onPrimaryContainer,
      ),
      TaskStatus.blocked => (scheme.errorContainer, scheme.onErrorContainer),
      TaskStatus.completed => (
        scheme.tertiaryContainer,
        scheme.onTertiaryContainer,
      ),
      TaskStatus.cancelled => (scheme.surfaceContainer, scheme.outline),
    };

    return Semantics(
      label: 'Status: ${status.label}',
      excludeSemantics: true,
      child: DecoratedBox(
        key: Key('task-status-${status.wireValue}'),
        decoration: BoxDecoration(
          color: background,
          borderRadius: BorderRadius.circular(8),
          border: status == TaskStatus.cancelled
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

/// The due label in the error colour when overdue.
class TaskDueText extends StatelessWidget {
  const TaskDueText(this.task, {super.key, this.companyDate});

  final TaskItem task;
  final String? companyDate;

  @override
  Widget build(BuildContext context) {
    final label = taskDueLabel(task, companyDate: companyDate);
    if (label == null) {
      return const SizedBox.shrink();
    }

    final theme = Theme.of(context);
    final overdue =
        task.dueState(companyDate: companyDate) == TaskDueState.overdue;

    return Text(
      label,
      style: theme.textTheme.bodyMedium?.copyWith(
        color: overdue
            ? theme.colorScheme.error
            : theme.colorScheme.onSurfaceVariant,
        fontWeight: overdue ? FontWeight.w600 : null,
      ),
    );
  }
}
