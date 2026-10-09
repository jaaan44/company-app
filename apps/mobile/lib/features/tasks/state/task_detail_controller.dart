import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';
import 'package:mobile/features/tasks/domain/task_item.dart';
import 'package:mobile/features/tasks/state/task_changes.dart';

enum TaskDetailStatus { loading, loaded, error }

/// State for one task's detail screen (Phase 29A, spec §5.3): loading it,
/// and changing its status.
///
/// **Loading** follows the Phase 27/28 resource lifecycle: [load] shows the
/// full loading state only when there is nothing to show yet (a list row
/// passes its item as [initial], so the screen opens with content and
/// refreshes quietly); [refresh] keeps content; one request at a time.
///
/// **Saving is confirmed, not optimistic (R-7).** [saveStatus] sends one
/// `PATCH`; the shown status changes only when the server confirms it, and
/// the confirmed task is recorded in [changes] for the lists and Home.
/// - `403` (for example, the task was reassigned): the server's message is
///   shown, the task is reloaded, the status control is locked, and the
///   task is recorded as no longer mine.
/// - `422`: the server's message for `status` (or its summary) is shown.
/// - Network or server failure: a message is shown and the old status
///   stays; the person can try again. Nothing is retried automatically.
///
/// Cancelled is never offered and a cancelled task can't be changed here
/// (R-4). Session expiry is ignored, as in every Phase 27/28 controller.
class TaskDetailController extends ChangeNotifier {
  TaskDetailController(
    this._client,
    this.publicId, {
    TaskItem? initial,
    String? companyDate,
    this.changes,
  }) : _data = initial,
       _companyDate = companyDate, // ignore: prefer_initializing_formals
       _status = initial == null
           ? TaskDetailStatus.loading
           : TaskDetailStatus.loaded;

  final TasksApiClient _client;
  final String publicId;
  final TaskChanges? changes;

  TaskDetailStatus _status;
  TaskItem? _data;
  String? _companyDate;
  String? _errorMessage;
  String? _saveMessage;
  bool _isRefreshing = false;
  bool _isSaving = false;
  bool _statusLocked = false;
  Future<bool>? _inFlight;

  /// Increases with every confirmed save, so a load that started before a
  /// save can't overwrite the saved result with older data.
  int _saveEpoch = 0;
  bool _disposed = false;

  TaskDetailStatus get status => _status;
  TaskItem? get data => _data;

  /// Set when [status] is [TaskDetailStatus.error]: a message safe to show.
  String? get errorMessage => _errorMessage;

  /// The outcome message of the last failed save, safe to show; cleared by
  /// the next save or [clearSaveMessage].
  String? get saveMessage => _saveMessage;
  bool get isRefreshing => _isRefreshing;
  bool get isSaving => _isSaving;

  /// The company day (`YYYY-MM-DD`) the screen's due state is judged
  /// against — from the list or Home that opened it, never the device clock.
  String? get companyDate => _companyDate;

  set companyDate(String? value) {
    if (value != _companyDate) {
      _companyDate = value;
      _notify();
    }
  }

  /// The due state to show, or null when it can't be judged yet.
  TaskDueState? get dueState => _data?.dueState(companyDate: _companyDate);

  /// Whether the status control is shown enabled: a loaded, non-cancelled
  /// task, no save running, and no `403` since it was opened.
  bool get canChangeStatus {
    final task = _data;

    return task != null &&
        task.status != TaskStatus.cancelled &&
        !_isSaving &&
        !_statusLocked;
  }

  /// False when the task is cancelled (shown read-only, R-4).
  bool get showsStatusControl => _data?.status != TaskStatus.cancelled;

  void clearSaveMessage() {
    if (_saveMessage != null) {
      _saveMessage = null;
      _notify();
    }
  }

  Future<void> load() async {
    if (_inFlight != null) {
      await _inFlight;

      return;
    }

    if (_data == null) {
      _status = TaskDetailStatus.loading;
      _errorMessage = null;
      _notify();
    }

    await _run();
  }

  Future<bool> refresh() async {
    final inFlight = _inFlight;
    if (inFlight != null) {
      return inFlight;
    }

    _isRefreshing = true;
    _notify();

    return _run();
  }

  /// Changes the task's status to [next] and waits for the server. Returns
  /// true when the server confirmed it. Does nothing (false) when the
  /// control isn't available, [next] isn't offered (Cancelled, R-4), or
  /// [next] is already the status.
  Future<bool> saveStatus(TaskStatus next) async {
    final task = _data;
    if (!canChangeStatus ||
        task == null ||
        !TaskStatus.selectable.contains(next) ||
        next == task.status) {
      return false;
    }

    _isSaving = true;
    _saveMessage = null;
    _notify();

    try {
      final updated = await _client.updateStatus(publicId, next);
      _saveEpoch++;
      final merged = (_data ?? task).withServerUpdate(updated);
      _data = merged;
      _status = TaskDetailStatus.loaded;
      changes?.record(TaskChange.updated(merged));

      return true;
    } on ApiSessionExpiredException {
      return false;
    } on ApiForbiddenException catch (e) {
      _saveMessage = e.message;
      _statusLocked = true;
      changes?.record(TaskChange.removed(publicId));
      await _reloadAfterRefusal();

      return false;
    } on ApiValidationException catch (e) {
      _saveMessage = e.firstErrorFor('status') ?? e.message;

      return false;
    } on ApiException catch (e) {
      _saveMessage = switch (e) {
        ApiNetworkException() =>
          "Couldn't save the change. Check your connection and try again.",
        ApiRequestException(statusCode: 404) =>
          'This task is no longer available.',
        _ => "Couldn't save the change. Please try again.",
      };

      return false;
    } finally {
      _isSaving = false;
      _notify();
    }
  }

  /// After a `403` on save: show the task as the server now has it. A
  /// failure here keeps the earlier data — the save message already says
  /// what happened.
  Future<void> _reloadAfterRefusal() async {
    try {
      final fresh = await _client.fetchTask(publicId);
      final current = _data;
      _data = current == null ? fresh : current.withServerUpdate(fresh);
    } on ApiException {
      // Keep the earlier data.
    }
  }

  Future<bool> _run() {
    final request = _perform();
    _inFlight = request;

    return request.whenComplete(() => _inFlight = null);
  }

  Future<bool> _perform() async {
    final epoch = _saveEpoch;

    try {
      final fresh = await _client.fetchTask(publicId);
      if (epoch == _saveEpoch) {
        final current = _data;
        _data = current == null ? fresh : current.withServerUpdate(fresh);
      }
      _status = TaskDetailStatus.loaded;
      _errorMessage = null;

      return true;
    } on ApiSessionExpiredException {
      // The session has already ended and the app is returning to Login.
      return true;
    } on ApiException catch (e) {
      if (_data != null) {
        // Keep showing the earlier data.
        return false;
      }

      _status = TaskDetailStatus.error;
      _errorMessage = _messageFor(e);

      return true;
    } finally {
      _isRefreshing = false;
      _notify();
    }
  }

  String _messageFor(ApiException e) => switch (e) {
    ApiNetworkException() => "Couldn't load this task. Check your connection.",
    ApiForbiddenException() => "You don't have access to this task.",
    ApiRequestException(statusCode: 404) => 'This task is no longer available.',
    _ => 'Something went wrong loading this task.',
  };

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
