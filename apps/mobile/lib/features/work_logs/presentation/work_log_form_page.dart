import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';
import 'package:mobile/features/work_logs/data/work_logs_api_client.dart';
import 'package:mobile/features/work_logs/domain/work_log.dart';
import 'package:mobile/features/work_logs/presentation/work_log_widgets.dart';
import 'package:mobile/features/work_logs/state/work_log_changes.dart';
import 'package:mobile/features/work_logs/state/work_log_form_controller.dart';

/// Add or edit a work log (Phase 29B, spec §6.5; R-14…R-18) — the app's
/// first real form, following the 06 conventions: labelled fields with
/// inline errors, the server's errors on the same fields, the save button
/// disabled while saving, a `SnackBar` on success, an `AlertDialog` before
/// deleting, and "Discard changes?" when leaving with unsaved edits.
///
/// Opened from My work logs (add or edit) or from a task's detail ("Log
/// work", the task fixed). Saving waits for the server (R-7) and is never
/// retried; what was typed stays on failure. See [WorkLogFormController].
class WorkLogFormPage extends StatefulWidget {
  const WorkLogFormPage({
    super.key,
    required this.workLogsApiClient,
    required this.tasksApiClient,
    required this.workLogChanges,
    this.args,
  });

  final WorkLogsApiClient workLogsApiClient;
  final TasksApiClient tasksApiClient;
  final WorkLogChanges workLogChanges;
  final WorkLogFormArgs? args;

  @override
  State<WorkLogFormPage> createState() => _WorkLogFormPageState();
}

class _WorkLogFormPageState extends State<WorkLogFormPage> {
  late final WorkLogFormController _controller = WorkLogFormController(
    widget.workLogsApiClient,
    widget.tasksApiClient,
    existing: widget.args?.existing,
    fixedTarget: widget.args?.fixedTarget,
    companyDate: widget.args?.companyDate,
    changes: widget.workLogChanges,
  );

  late final TextEditingController _hours = TextEditingController(
    text: _durationText(_controller.hours, editing: _controller.isEditing),
  );
  late final TextEditingController _minutes = TextEditingController(
    text: _durationText(_controller.minutes, editing: _controller.isEditing),
  );
  late final TextEditingController _description = TextEditingController(
    text: _controller.description,
  );

  /// Set once the form is leaving on purpose (saved, deleted, or the
  /// person chose to discard), so the discard check doesn't run again.
  bool _leaving = false;

  static String _durationText(int value, {required bool editing}) =>
      editing ? '$value' : (value == 0 ? '' : '$value');

  @override
  void initState() {
    super.initState();
    _controller.load();
  }

  @override
  void dispose() {
    _controller.dispose();
    _hours.dispose();
    _minutes.dispose();
    _description.dispose();
    super.dispose();
  }

  void _durationChanged(String _) {
    _controller.setDuration(
      hours: int.tryParse(_hours.text) ?? 0,
      minutes: int.tryParse(_minutes.text) ?? 0,
    );
  }

  void _leave() {
    setState(() => _leaving = true);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        context.pop();
      }
    });
  }

  Future<void> _save() async {
    FocusScope.of(context).unfocus();
    final editing = _controller.isEditing;
    if (await _controller.save() && mounted) {
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(
          SnackBar(content: Text(editing ? 'Changes saved.' : 'Work logged.')),
        );
      _leave();
    }
  }

  Future<void> _delete() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delete this work log?'),
        content: const Text("This can't be undone."),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          TextButton(
            key: const Key('work-log-delete-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            style: TextButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) {
      return;
    }

    if (await _controller.delete() && mounted) {
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(const SnackBar(content: Text('Work log deleted.')));
      _leave();
    }
  }

  Future<void> _confirmDiscard() async {
    final discard = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Discard changes?'),
        content: const Text("What you've entered won't be saved."),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep editing'),
          ),
          TextButton(
            key: const Key('work-log-discard-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Discard'),
          ),
        ],
      ),
    );
    if (discard == true && mounted) {
      _leave();
    }
  }

  Future<void> _pickDate() async {
    final first = _controller.firstDate;
    final last = _controller.lastDate;
    if (first == null || last == null) {
      return;
    }
    final current = _controller.date ?? last;

    final picked = await showDatePicker(
      context: context,
      initialDate: pickerDateOf(current),
      firstDate: pickerDateOf(first),
      lastDate: pickerDateOf(last),
      helpText: 'Date worked',
    );
    if (picked != null) {
      _controller.setDate(apiDateOf(picked));
    }
  }

  Future<void> _pickTarget() async {
    final chosen = await showModalBottomSheet<WorkTarget>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _TargetPicker(
        tasks: _controller.taskOptions,
        projects: _controller.projectOptions,
        selected: _controller.target,
      ),
    );
    if (chosen != null) {
      _controller.setTarget(chosen);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _controller,
      builder: (context, _) => PopScope(
        canPop: _leaving || _controller.isBusy || !_controller.isDirty,
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop) {
            _confirmDiscard();
          }
        },
        child: Scaffold(
          appBar: AppBar(
            title: Text(_controller.isEditing ? 'Edit work log' : 'Log work'),
          ),
          body: LayoutBuilder(
            builder: (context, constraints) =>
                _body(context, peopleListPadding(constraints)),
          ),
        ),
      ),
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    switch (_controller.status) {
      case WorkLogFormStatus.loading:
        return const Center(child: CircularProgressIndicator());
      case WorkLogFormStatus.error:
        return PeopleErrorView(
          message:
              _controller.loadError ?? 'Something went wrong loading the form.',
          onRetry: _controller.load,
        );
      case WorkLogFormStatus.noProfile:
        return const Center(
          child: Padding(
            padding: EdgeInsets.all(24),
            child: Text(
              'No staff profile is linked to this account.',
              key: Key('work-log-form-no-profile'),
              textAlign: TextAlign.center,
            ),
          ),
        );
      case WorkLogFormStatus.ready:
        break;
    }

    final theme = Theme.of(context);
    final errors = _controller.fieldErrors;
    final general = _controller.generalError;
    final busy = _controller.isBusy;
    final date = _controller.date;

    return ListView(
      key: const Key('work-log-form'),
      padding: EdgeInsets.fromLTRB(
        padding.left + 16,
        16,
        padding.right + 16,
        32,
      ),
      children: [
        if (general != null) ...[
          Semantics(
            liveRegion: true,
            child: Container(
              key: const Key('work-log-general-error'),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: theme.colorScheme.errorContainer,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                general,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onErrorContainer,
                ),
              ),
            ),
          ),
          const SizedBox(height: 16),
        ],
        _FieldTile(
          key: const Key('work-log-target'),
          label: 'What was this for?',
          value: _controller.target?.label,
          detail: switch (_controller.target) {
            TaskTarget(:final projectName) => projectName,
            _ => null,
          },
          placeholder: 'Choose a task or project',
          icon: Icons.assignment_outlined,
          error: errors[WorkLogField.target],
          onTap: _controller.canChooseTarget && !busy ? _pickTarget : null,
        ),
        const SizedBox(height: 12),
        _FieldTile(
          key: const Key('work-log-date'),
          label: 'Date',
          value: date == null ? null : workLogDateLabel(date),
          detail: date == _controller.companyDate ? 'Today' : null,
          placeholder: 'Choose a date',
          icon: Icons.event_outlined,
          error: errors[WorkLogField.date],
          onTap: busy ? null : _pickDate,
        ),
        const SizedBox(height: 16),
        Semantics(
          header: true,
          child: Text('Time spent', style: theme.textTheme.titleSmall),
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            SizedBox(
              width: 140,
              child: TextFormField(
                key: const Key('work-log-hours'),
                controller: _hours,
                enabled: !busy,
                keyboardType: TextInputType.number,
                inputFormatters: [
                  FilteringTextInputFormatter.digitsOnly,
                  LengthLimitingTextInputFormatter(2),
                ],
                decoration: const InputDecoration(
                  labelText: 'Hours',
                  border: OutlineInputBorder(),
                ),
                textInputAction: TextInputAction.next,
                onChanged: _durationChanged,
              ),
            ),
            SizedBox(
              width: 140,
              child: TextFormField(
                key: const Key('work-log-minutes'),
                controller: _minutes,
                enabled: !busy,
                keyboardType: TextInputType.number,
                inputFormatters: [
                  FilteringTextInputFormatter.digitsOnly,
                  LengthLimitingTextInputFormatter(2),
                ],
                decoration: const InputDecoration(
                  labelText: 'Minutes',
                  border: OutlineInputBorder(),
                ),
                textInputAction: TextInputAction.next,
                onChanged: _durationChanged,
              ),
            ),
          ],
        ),
        if (errors[WorkLogField.duration] case final message?) ...[
          const SizedBox(height: 6),
          _ErrorText(message, key: const Key('work-log-duration-error')),
        ],
        const SizedBox(height: 16),
        TextFormField(
          key: const Key('work-log-description'),
          controller: _description,
          enabled: !busy,
          minLines: 3,
          maxLines: 8,
          maxLength: WorkLogLimits.maxDescriptionLength,
          maxLengthEnforcement: MaxLengthEnforcement.none,
          keyboardType: TextInputType.multiline,
          decoration: InputDecoration(
            labelText: 'What did you do?',
            alignLabelWithHint: true,
            border: const OutlineInputBorder(),
            errorText: errors[WorkLogField.description],
            errorMaxLines: 3,
          ),
          onChanged: _controller.setDescription,
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('work-log-save'),
          onPressed: busy ? null : _save,
          child: _controller.isSaving
              ? const SizedBox(
                  height: 20,
                  width: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : Text(_controller.isEditing ? 'Save changes' : 'Save'),
        ),
        if (_controller.isEditing) ...[
          const SizedBox(height: 12),
          OutlinedButton.icon(
            key: const Key('work-log-delete'),
            onPressed: busy ? null : _delete,
            style: OutlinedButton.styleFrom(
              foregroundColor: theme.colorScheme.error,
            ),
            icon: _controller.isDeleting
                ? const SizedBox(
                    height: 16,
                    width: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.delete_outline),
            label: const Text('Delete'),
          ),
        ],
      ],
    );
  }
}

/// A labelled, tappable field showing a chosen value (or a placeholder),
/// with its error beneath. Read-only when [onTap] is null.
class _FieldTile extends StatelessWidget {
  const _FieldTile({
    super.key,
    required this.label,
    required this.value,
    required this.placeholder,
    required this.icon,
    this.detail,
    this.error,
    this.onTap,
  });

  final String label;
  final String? value;
  final String? detail;
  final String placeholder;
  final IconData icon;
  final String? error;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hasError = error != null;
    final borderColor = hasError
        ? theme.colorScheme.error
        : theme.colorScheme.outline;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Semantics(
          button: onTap != null,
          label: label,
          value: [value ?? placeholder, ?detail].join(', '),
          excludeSemantics: true,
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(4),
            child: InputDecorator(
              decoration: InputDecoration(
                labelText: label,
                border: const OutlineInputBorder(),
                enabledBorder: OutlineInputBorder(
                  borderSide: BorderSide(color: borderColor),
                ),
                prefixIcon: Icon(icon),
                suffixIcon: onTap == null
                    ? null
                    : const Icon(Icons.arrow_drop_down),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    value ?? placeholder,
                    style: value == null
                        ? theme.textTheme.bodyLarge?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          )
                        : theme.textTheme.bodyLarge,
                  ),
                  if (detail != null)
                    Text(
                      detail!,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
        if (error case final message?) ...[
          const SizedBox(height: 6),
          _ErrorText(message, key: Key('${(key as ValueKey).value}-error')),
        ],
      ],
    );
  }
}

class _ErrorText extends StatelessWidget {
  const _ErrorText(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Semantics(
      liveRegion: true,
      child: Padding(
        padding: const EdgeInsets.only(left: 12),
        child: Text(
          message,
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.error,
          ),
        ),
      ),
    );
  }
}

/// The "What was this for?" choice (R-14): my open tasks, then my
/// projects. Returns the chosen target.
class _TargetPicker extends StatelessWidget {
  const _TargetPicker({
    required this.tasks,
    required this.projects,
    required this.selected,
  });

  final List<TaskTarget> tasks;
  final List<ProjectTarget> projects;
  final WorkTarget? selected;

  @override
  Widget build(BuildContext context) {
    Widget option(WorkTarget target, {String? subtitle}) {
      final isSelected =
          selected?.publicId == target.publicId &&
          selected.runtimeType == target.runtimeType;

      return ListTile(
        key: Key('work-target-${target.publicId}'),
        title: Text(target.label),
        subtitle: subtitle == null ? null : Text(subtitle),
        trailing: isSelected ? const Icon(Icons.check) : null,
        selected: isSelected,
        onTap: () => Navigator.of(context).pop(target),
      );
    }

    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.7,
      minChildSize: 0.4,
      maxChildSize: 0.95,
      builder: (context, scroll) => ListView(
        key: const Key('work-target-picker'),
        controller: scroll,
        children: [
          const PeopleSectionHeader('My open tasks'),
          if (tasks.isEmpty)
            const ListTile(title: Text('No open tasks assigned to you.')),
          for (final t in tasks) option(t, subtitle: t.projectName),
          const PeopleSectionHeader('My projects'),
          if (projects.isEmpty)
            const ListTile(
              title: Text("You aren't a member of any open project."),
            ),
          for (final p in projects) option(p, subtitle: p.projectCode),
          const SizedBox(height: 16),
        ],
      ),
    );
  }
}
