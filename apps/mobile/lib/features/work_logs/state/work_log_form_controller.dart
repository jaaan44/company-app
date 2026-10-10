import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';
import 'package:mobile/features/work_logs/data/work_logs_api_client.dart';
import 'package:mobile/features/work_logs/domain/work_log.dart';
import 'package:mobile/features/work_logs/state/work_log_changes.dart';

enum WorkLogFormStatus { loading, ready, noProfile, error }

/// The form's field keys, for [WorkLogFormController.fieldErrors].
abstract final class WorkLogField {
  static const target = 'target';
  static const date = 'work_date';
  static const duration = 'duration';
  static const description = 'description';
}

/// State for the add/edit work-log form (Phase 29B, spec §6.4–§6.5,
/// R-14…R-18).
///
/// - **Create** offers what the work was for (R-14) unless a task is fixed
///   (opened from a task's detail, R-15); **edit** shows it read-only (the
///   API never changes a log's task or project).
/// - **The company day** comes from the screen that opened the form, or
///   from `/me/work-logs`'s `meta.company_day` (R-12) — never the device
///   clock. It is the default date (create) and the latest allowed date.
/// - **Checked before sending** (R-17): a target, a date between
///   [firstDate] and [lastDate], 1 minute to 24 hours, a non-blank
///   description of at most 2000 characters. The API stays authoritative:
///   its `422` errors are shown on the same fields, and errors about the
///   task, project or staff record at the top ([generalError]).
/// - **Saving and deleting are confirmed, single-flight and never
///   retried** (R-7). On failure everything typed stays.
/// - [isDirty] tells the page whether to ask "Discard changes?" (R-18).
class WorkLogFormController extends ChangeNotifier {
  WorkLogFormController(
    this._client,
    this._tasksClient, {
    this.existing,
    WorkTarget? fixedTarget,
    String? companyDate,
    this.changes,
  }) : _fixedTarget = fixedTarget,
       _companyDate = companyDate {
    final log = existing;
    if (log != null) {
      _target = targetOf(log);
      _date = log.workDate;
      _hours = log.durationMinutes ~/ 60;
      _minutes = log.durationMinutes % 60;
      _description = log.description;
    } else {
      _target = fixedTarget;
      _date = companyDate;
    }
    _snapshot();
  }

  final WorkLogsApiClient _client;
  final TasksApiClient _tasksClient;

  /// The log being edited, or null when adding.
  final WorkLog? existing;
  final WorkLogChanges? changes;
  final WorkTarget? _fixedTarget;

  WorkLogFormStatus _status = WorkLogFormStatus.loading;
  String? _companyDate;
  String? _loadError;

  List<TaskTarget> _taskOptions = const [];
  List<ProjectTarget> _projectOptions = const [];

  WorkTarget? _target;
  String? _date;
  int _hours = 0;
  int _minutes = 0;
  String _description = '';
  late String _initial;

  final Map<String, String> _fieldErrors = {};
  String? _generalError;
  bool _isSaving = false;
  bool _isDeleting = false;
  bool _disposed = false;

  WorkLogFormStatus get status => _status;
  String? get loadError => _loadError;
  bool get isEditing => existing != null;

  /// Whether the person picks what the work was for (create, not opened
  /// from a task).
  bool get canChooseTarget => !isEditing && _fixedTarget == null;

  List<TaskTarget> get taskOptions => _taskOptions;
  List<ProjectTarget> get projectOptions => _projectOptions;

  WorkTarget? get target => _target;
  String? get date => _date;
  int get hours => _hours;
  int get minutes => _minutes;
  int get totalMinutes => _hours * 60 + _minutes;
  String get description => _description;

  /// The company today (`YYYY-MM-DD`): the default and the latest date.
  String? get companyDate => _companyDate;

  /// The latest date the picker allows: the company today.
  String? get lastDate => _companyDate;

  /// The earliest date the picker allows (R-17): 365 days before the
  /// company today, or an edited log's own date if that is earlier.
  String? get firstDate {
    final today = _companyDate;
    if (today == null) {
      return null;
    }
    final yearAgo = _shift(today, -WorkLogLimits.pickerDaysBack);
    final own = existing?.workDate;

    return own != null && own.compareTo(yearAgo) < 0 ? own : yearAgo;
  }

  Map<String, String> get fieldErrors => Map.unmodifiable(_fieldErrors);
  String? get generalError => _generalError;
  bool get isSaving => _isSaving;
  bool get isDeleting => _isDeleting;
  bool get isBusy => _isSaving || _isDeleting;

  /// True when anything differs from what was loaded (or last saved).
  bool get isDirty => _state() != _initial;

  /// Loads what the form needs: the company day if it wasn't given, and —
  /// when the person chooses the target — my open tasks and my projects.
  Future<void> load() async {
    _status = WorkLogFormStatus.loading;
    _loadError = null;
    _notify();

    try {
      if (_companyDate == null) {
        final day = await _client.fetchCompanyDay();
        _companyDate = day.date;
        if (!isEditing && _date == null) {
          _date = day.date;
          _snapshot();
        }
      }

      if (canChooseTarget) {
        final loaded = await _loadTargets();
        if (!loaded) {
          _status = WorkLogFormStatus.noProfile;

          return;
        }
      }

      _status = WorkLogFormStatus.ready;
    } on ApiSessionExpiredException {
      // The session has already ended and the app is returning to Login.
    } on ApiForbiddenException {
      // R-13: `/me/work-logs` refuses only an account without a profile.
      _status = WorkLogFormStatus.noProfile;
    } on ApiException catch (e) {
      _status = WorkLogFormStatus.error;
      _loadError = e is ApiNetworkException
          ? "Couldn't load the form. Check your connection."
          : 'Something went wrong loading the form.';
    } finally {
      _notify();
    }
  }

  void setTarget(WorkTarget? value) {
    if (!canChooseTarget) {
      return;
    }
    _target = value;
    _clearError(WorkLogField.target);
  }

  void setDate(String value) {
    _date = value;
    _clearError(WorkLogField.date);
  }

  void setDuration({required int hours, required int minutes}) {
    _hours = hours;
    _minutes = minutes;
    _clearError(WorkLogField.duration);
  }

  void setDescription(String value) {
    _description = value;
    _clearError(WorkLogField.description);
  }

  /// Checks the input before sending (R-17); returns true when valid.
  bool validate() {
    _fieldErrors.clear();
    _generalError = null;

    if (_target == null) {
      _fieldErrors[WorkLogField.target] = 'Choose what this work was for.';
    }

    final date = _date;
    final last = lastDate;
    final first = firstDate;
    if (date == null || !isApiDate(date)) {
      _fieldErrors[WorkLogField.date] = 'Choose a date.';
    } else if (last != null && date.compareTo(last) > 0) {
      _fieldErrors[WorkLogField.date] =
          'The work date cannot be later than today.';
    } else if (first != null && date.compareTo(first) < 0) {
      _fieldErrors[WorkLogField.date] = 'Choose a date within the last year.';
    }

    final total = totalMinutes;
    if (_hours < 0 || _minutes < 0 || _minutes > 59) {
      _fieldErrors[WorkLogField.duration] = 'Enter hours and minutes.';
    } else if (total < WorkLogLimits.minMinutes) {
      _fieldErrors[WorkLogField.duration] = 'Enter how long you worked.';
    } else if (total > WorkLogLimits.maxMinutes) {
      _fieldErrors[WorkLogField.duration] =
          'A work log can be at most 24 hours.';
    }

    final text = _description.trim();
    if (text.isEmpty) {
      _fieldErrors[WorkLogField.description] = 'Describe the work.';
    } else if (text.length > WorkLogLimits.maxDescriptionLength) {
      _fieldErrors[WorkLogField.description] =
          'Use at most ${WorkLogLimits.maxDescriptionLength} characters.';
    }

    _notify();

    return _fieldErrors.isEmpty;
  }

  /// Saves (creates or updates) once, after [validate]. Returns true when
  /// the server confirmed it; the confirmed log is recorded in [changes].
  Future<bool> save() async {
    if (isBusy || _status != WorkLogFormStatus.ready || !validate()) {
      return false;
    }

    _isSaving = true;
    _notify();

    try {
      final log = existing;
      final saved = log == null
          ? await _client.create(
              target: _target!,
              workDate: _date!,
              durationMinutes: totalMinutes,
              description: _description.trim(),
            )
          : await _client.update(
              log.publicId,
              workDate: _date!,
              durationMinutes: totalMinutes,
              description: _description.trim(),
            );
      changes?.record(WorkLogChange.saved(saved));
      _snapshot();

      return true;
    } on ApiSessionExpiredException {
      return false;
    } on ApiValidationException catch (e) {
      _applyServerErrors(e);

      return false;
    } on ApiException catch (e) {
      _generalError = _failureMessage(e, action: 'save');
      final edited = existing;
      if (edited != null && e is ApiRequestException && e.statusCode == 404) {
        changes?.record(WorkLogChange.deleted(edited.publicId));
      }

      return false;
    } finally {
      _isSaving = false;
      _notify();
    }
  }

  /// Deletes the edited log once (the page asks for confirmation first,
  /// R-18). Returns true when it is gone — including when the server says
  /// it was already gone.
  Future<bool> delete() async {
    final log = existing;
    if (log == null || isBusy) {
      return false;
    }

    _isDeleting = true;
    _generalError = null;
    _notify();

    try {
      await _client.delete(log.publicId);
      changes?.record(WorkLogChange.deleted(log.publicId));

      return true;
    } on ApiSessionExpiredException {
      return false;
    } on ApiException catch (e) {
      if (e is ApiRequestException && e.statusCode == 404) {
        // Already gone: the outcome the person asked for.
        changes?.record(WorkLogChange.deleted(log.publicId));

        return true;
      }
      _generalError = _failureMessage(e, action: 'delete');

      return false;
    } finally {
      _isDeleting = false;
      _notify();
    }
  }

  /// The read-only target of an existing log.
  static WorkTarget targetOf(WorkLog log) {
    final task = log.task;
    if (task != null) {
      return TaskTarget(
        publicId: task.publicId,
        label: task.name,
        projectName: log.project?.name,
      );
    }

    final project = log.project!;

    return ProjectTarget(publicId: project.publicId, label: project.name);
  }

  /// My open assigned tasks and my non-closed member projects (R-14).
  /// Returns false when no Staff record is linked.
  Future<bool> _loadTargets() async {
    final tasks = <TaskTarget>[];
    for (var page = 1; page <= WorkLogsApiClient.maxPickerPages; page++) {
      final result = await _tasksClient.fetchMyTasks(
        state: TaskListState.open,
        page: page,
      );
      final pageTasks = result.tasks;
      if (pageTasks == null) {
        return false;
      }
      tasks.addAll(
        pageTasks.map(
          (t) => TaskTarget(
            publicId: t.publicId,
            label: t.title,
            projectName: t.project?.name,
          ),
        ),
      );
      if (!result.hasMore) {
        break;
      }
    }

    final staffId = await _client.fetchMyStaffPublicId();
    if (staffId == null) {
      return false;
    }
    final projects = await _client.fetchMemberProjects(staffId);

    _taskOptions = List.unmodifiable(tasks);
    _projectOptions = List.unmodifiable(
      projects
          .where((p) => !p.isClosed)
          .map(
            (p) => ProjectTarget(
              publicId: p.publicId,
              label: p.name,
              projectCode: p.projectCode,
            ),
          ),
    );

    return true;
  }

  void _applyServerErrors(ApiValidationException e) {
    const fieldsByKey = {
      'work_date': WorkLogField.date,
      'duration_minutes': WorkLogField.duration,
      'description': WorkLogField.description,
    };

    for (final entry in e.fieldErrors.entries) {
      if (entry.value.isEmpty) {
        continue;
      }
      final field = fieldsByKey[entry.key];
      if (field != null) {
        _fieldErrors[field] = entry.value.first;
      } else {
        // task_id / project_id / staff_id: eligibility, shown at the top.
        _generalError ??= entry.value.first;
      }
    }

    if (_fieldErrors.isEmpty && _generalError == null) {
      _generalError = e.message;
    }
  }

  String _failureMessage(ApiException e, {required String action}) =>
      switch (e) {
        ApiNetworkException() =>
          "Couldn't $action. Check your connection and try again.",
        ApiForbiddenException(:final message) => message,
        ApiRequestException(statusCode: 404) =>
          'This work log is no longer available.',
        _ => "Couldn't $action. Please try again.",
      };

  void _clearError(String field) {
    _fieldErrors.remove(field);
    _generalError = null;
    _notify();
  }

  String _state() =>
      '${_target?.publicId}|$_date|$_hours|$_minutes|$_description';

  void _snapshot() => _initial = _state();

  static String _shift(String date, int days) {
    // Calendar arithmetic in UTC, so a daylight-saving change can't move
    // the result to the neighbouring day.
    final p = DateTime.parse(date);
    final d = DateTime.utc(p.year, p.month, p.day + days);

    return '${d.year.toString().padLeft(4, '0')}-'
        '${d.month.toString().padLeft(2, '0')}-'
        '${d.day.toString().padLeft(2, '0')}';
  }

  void _notify() {
    if (!_disposed) {
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
