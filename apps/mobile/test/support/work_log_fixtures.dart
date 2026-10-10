import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/work_logs/data/work_logs_api_client.dart';

import 'fake_backend.dart';

export 'people_fixtures.dart' show jsonResponse;

/// A `WorkLogResource` JSON object. Pass [taskTitle] for a task log (its
/// project is then [projectName], possibly null for an independent task);
/// omit it for a project log.
Map<String, dynamic> workLogJson(
  String publicId, {
  String workDate = '2026-10-10',
  int minutes = 90,
  String description = 'Plant room check.',
  String? taskTitle,
  String? projectName = 'UAT Plant Room',
}) => {
  'public_id': publicId,
  'staff': {
    'public_id': '01STAF00000000000000000001',
    'employee_number': 'EMP-001',
    'display_name': 'Ada Lovelace',
  },
  'task': taskTitle == null
      ? null
      : {'public_id': 'TASK-$publicId', 'title': taskTitle},
  'project': projectName == null
      ? null
      : {'public_id': '01PROJ00000000000000000001', 'name': projectName},
  'work_date': workDate,
  'duration_minutes': minutes,
  'description': description,
  'created_by': null,
  'created_at': '2026-10-10T01:00:00+00:00',
  'updated_at': '2026-10-10T01:00:00+00:00',
};

/// A whole `GET /me/work-logs` body.
Map<String, dynamic> workLogsBody(
  List<Map<String, dynamic>> logs, {
  int page = 1,
  int lastPage = 1,
  String date = '2026-10-10',
}) => {
  'data': logs,
  'links': {'first': null, 'last': null, 'prev': null, 'next': null},
  'meta': {
    'current_page': page,
    'last_page': lastPage,
    'per_page': 25,
    'total': logs.length,
    'company_day': {'date': date, 'timezone': 'Asia/Manila'},
  },
};

/// A `ProjectResource` JSON object, as `GET /projects` lists it.
Map<String, dynamic> projectJson(
  String publicId, {
  String name = 'A project',
  String status = 'active',
  String? code,
}) => {
  'public_id': publicId,
  'project_code': code,
  'name': name,
  'description': null,
  'status': status,
  'start_date': null,
  'target_end_date': null,
  'completed_date': null,
  'client': null,
  'members_count': 1,
  'my_role': 'member',
  'notes': null,
  'created_at': '2026-09-01T00:00:00+00:00',
  'updated_at': '2026-09-01T00:00:00+00:00',
};

/// A whole `GET /projects` body.
Map<String, dynamic> projectsBody(
  List<Map<String, dynamic>> projects, {
  int page = 1,
  int lastPage = 1,
}) => {
  'data': projects,
  'meta': {'current_page': page, 'last_page': lastPage, 'per_page': 50},
};

/// A [WorkLogsApiClient] over the real `ApiClient` and [FakeBackend].
WorkLogsApiClient workLogsClientFor(FakeBackend backend, ApiSession session) =>
    WorkLogsApiClient(
      ApiClient(
        session: session,
        httpClient: backend.client,
        baseUrl: testBaseUrl,
      ),
    );
