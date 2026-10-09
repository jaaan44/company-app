import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/tasks/domain/task_item.dart';

/// Which `/me/tasks` list: not completed/cancelled, or completed/cancelled.
enum TaskListState {
  open('open'),
  closed('closed');

  const TaskListState(this.wireValue);

  final String wireValue;
}

/// The Tasks area's calls (Phase 29A, docs/phases/V1_PHASE_29_DEFINITION.md
/// §5.1/§5.3), all through the shared authenticated [ApiClient], which
/// already owns every session rule (401/403 handling) — nothing here
/// repeats it.
///
/// Every method throws an [ApiException]; a body that doesn't match the
/// contract is reported as an [ApiRequestException] rather than a crash.
class TasksApiClient {
  TasksApiClient(this._apiClient);

  /// Page size for the lists: the endpoint's default, always sent
  /// explicitly (the server caps it at 50).
  static const pageSize = 25;

  final ApiClient _apiClient;

  /// `GET /me/tasks` — one page of the tasks assigned to me.
  Future<MyTasksPage> fetchMyTasks({
    required TaskListState state,
    int page = 1,
  }) async {
    final path = Uri(
      path: '/me/tasks',
      queryParameters: {
        'state': state.wireValue,
        'per_page': '$pageSize',
        'page': '$page',
      },
    ).toString();

    final body = await _apiClient.getJson(path);

    return _parse(() => MyTasksPage.fromJson(body));
  }

  /// `GET /tasks/{publicId}` — one task (no server flags; see
  /// [TaskItem.dueState]).
  Future<TaskItem> fetchTask(String publicId) async {
    final body = await _apiClient.getJson(_taskPath(publicId));

    return _parse(() => TaskItem.fromJson(_data(body)));
  }

  /// `PATCH /tasks/{publicId}` with `{"status": …}` — the assignee's only
  /// permitted change (Phase 11). Sent once, never retried. Returns the
  /// server's updated task.
  Future<TaskItem> updateStatus(String publicId, TaskStatus status) async {
    final body = await _apiClient.patchJson(_taskPath(publicId), {
      'status': status.wireValue,
    });
    if (body == null) {
      throw const ApiRequestException(
        'Something went wrong. Please try again.',
      );
    }

    return _parse(() => TaskItem.fromJson(_data(body)));
  }

  String _taskPath(String publicId) =>
      '/tasks/${Uri.encodeComponent(publicId)}';

  Map<String, dynamic> _data(Map<String, dynamic> body) {
    final data = body['data'];
    if (data is! Map<String, dynamic>) {
      throw const FormatException('Missing data object');
    }

    return data;
  }

  T _parse<T>(T Function() parse) {
    try {
      return parse();
    } on FormatException {
      throw const ApiRequestException(
        'Something went wrong. Please try again.',
      );
    }
  }
}
