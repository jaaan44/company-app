import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';

import 'fake_backend.dart';

export 'people_fixtures.dart' show jsonResponse;

/// A `TaskResource` JSON object, as `GET/PATCH /tasks/{id}` return it.
/// With [flags], the two `/me/tasks` booleans are added.
Map<String, dynamic> taskJson({
  String publicId = '01TASK00000000000000000001',
  String title = 'Replace the lobby lights',
  String? description = 'Both floors.',
  String status = 'todo',
  String priority = 'normal',
  String? dueDate = '2026-09-30',
  String? completedAt,
  Map<String, dynamic>? project = const {
    'public_id': '01PROJ00000000000000000001',
    'name': 'Fit-out',
  },
  bool flags = false,
  bool isOverdue = false,
  bool isDueToday = false,
}) => {
  'public_id': publicId,
  'title': title,
  'description': description,
  'status': status,
  'priority': priority,
  'due_date': dueDate,
  'completed_at': completedAt,
  'project': project,
  'assignee': {
    'public_id': '01STAF00000000000000000001',
    'employee_number': 'EMP-001',
    'display_name': 'Ada Lovelace',
  },
  'created_by': {
    'public_id': '01STAF00000000000000000002',
    'employee_number': 'EMP-002',
    'display_name': 'Grace Hopper',
  },
  'created_at': '2026-09-20T01:00:00+00:00',
  'updated_at': '2026-09-21T02:00:00+00:00',
  if (flags) 'is_overdue': isOverdue,
  if (flags) 'is_due_today': isDueToday,
};

/// A `/me/tasks` item (a task with flags).
Map<String, dynamic> myTaskJson(
  String publicId, {
  String title = 'A task',
  String status = 'todo',
  String? dueDate,
  bool isOverdue = false,
  bool isDueToday = false,
}) => taskJson(
  publicId: publicId,
  title: title,
  status: status,
  dueDate: dueDate,
  completedAt: status == 'completed' ? '2026-09-23T03:00:00+00:00' : null,
  flags: true,
  isOverdue: isOverdue,
  isDueToday: isDueToday,
);

/// A whole `GET /me/tasks` body. [tasks] null is the no-profile response.
Map<String, dynamic> myTasksBody(
  List<Map<String, dynamic>>? tasks, {
  int page = 1,
  int lastPage = 1,
  int? total,
  String date = '2026-09-24',
  String timezone = 'Asia/Manila',
}) => {
  'data': {
    'company_day': {'date': date, 'timezone': timezone},
    'tasks': tasks,
  },
  'meta': tasks == null
      ? null
      : {
          'current_page': page,
          'last_page': lastPage,
          'per_page': 25,
          'total': total ?? tasks.length,
        },
};

/// A [TasksApiClient] over the real `ApiClient` and [FakeBackend].
TasksApiClient tasksClientFor(FakeBackend backend, ApiSession session) =>
    TasksApiClient(
      ApiClient(
        session: session,
        httpClient: backend.client,
        baseUrl: testBaseUrl,
      ),
    );
