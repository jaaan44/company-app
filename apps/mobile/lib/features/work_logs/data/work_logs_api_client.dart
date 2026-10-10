import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/work_logs/domain/work_log.dart';

/// The work-log calls (Phase 29B, docs/phases/V1_PHASE_29_DEFINITION.md
/// §6.3–§6.4), all through the shared authenticated [ApiClient], which
/// already owns every session rule (401/403 handling) — nothing here
/// repeats it.
///
/// Every method throws an [ApiException]; a body that doesn't match the
/// contract is reported as an [ApiRequestException] rather than a crash.
class WorkLogsApiClient {
  WorkLogsApiClient(this._apiClient);

  /// Page size for my work logs, always sent explicitly (the API's own
  /// `per_page` is uncapped).
  static const pageSize = 25;

  /// The picker's page size for my projects, and how many pages of tasks
  /// or projects it reads at most — far beyond one person's count.
  static const projectPageSize = 50;
  static const maxPickerPages = 10;

  final ApiClient _apiClient;

  /// `GET /me/work-logs` — one page of my logs, newest first, with the
  /// company day (R-12). A `403` here (after `ApiClient`'s `/auth/me`
  /// re-check kept the session) means no Staff record is linked (R-13).
  Future<MyWorkLogsPage> fetchMyWorkLogs({
    int page = 1,
    int perPage = pageSize,
  }) async {
    final path = Uri(
      path: '/me/work-logs',
      queryParameters: {'per_page': '$perPage', 'page': '$page'},
    ).toString();

    final body = await _apiClient.getJson(path);

    return _parse(() => MyWorkLogsPage.fromJson(body));
  }

  /// The company day, read from the smallest possible `/me/work-logs` page
  /// — for a form opened without one (never the device clock).
  Future<WorkLogCompanyDay> fetchCompanyDay() async =>
      (await fetchMyWorkLogs(perPage: 1)).companyDay;

  /// `POST /me/work-logs` for [target]. Sent once, never retried.
  Future<WorkLog> create({
    required WorkTarget target,
    required String workDate,
    required int durationMinutes,
    required String description,
  }) async {
    final field = target.requestField;
    final body = await _apiClient.postJson(
      '/me/work-logs',
      body: {
        field.key: field.value,
        'work_date': workDate,
        'duration_minutes': durationMinutes,
        'description': description,
      },
    );

    return _parseLog(body);
  }

  /// `PATCH /me/work-logs/{publicId}` — only the three editable fields
  /// (the API prohibits changing the task, project or staff).
  Future<WorkLog> update(
    String publicId, {
    required String workDate,
    required int durationMinutes,
    required String description,
  }) async {
    final body = await _apiClient.patchJson(_logPath(publicId), {
      'work_date': workDate,
      'duration_minutes': durationMinutes,
      'description': description,
    });

    return _parseLog(body);
  }

  /// `DELETE /me/work-logs/{publicId}` (`204`).
  Future<void> delete(String publicId) => _apiClient.delete(_logPath(publicId));

  /// My own Staff record's public id from `GET /me/profile`, or null when
  /// none is linked.
  Future<String?> fetchMyStaffPublicId() async {
    final body = await _apiClient.getJson('/me/profile');

    return _parse(() {
      final data = _data(body);
      final staff = data['staff'];
      if (staff == null) {
        return null;
      }

      return (staff as Map<String, dynamic>)['public_id'] as String;
    });
  }

  /// `GET /projects?member=<staffPublicId>` — the projects I'm a member of,
  /// all pages (R-14). Managers and Administrators would otherwise see
  /// every project, most of which they can't log against.
  Future<List<MemberProject>> fetchMemberProjects(String staffPublicId) async {
    final projects = <MemberProject>[];

    for (var page = 1; page <= maxPickerPages; page++) {
      final path = Uri(
        path: '/projects',
        queryParameters: {
          'member': staffPublicId,
          'per_page': '$projectPageSize',
          'page': '$page',
        },
      ).toString();
      final body = await _apiClient.getJson(path);

      final lastPage = _parse(() {
        projects.addAll(
          (body['data'] as List<dynamic>).map(
            (p) => MemberProject.fromJson(p as Map<String, dynamic>),
          ),
        );

        return (body['meta'] as Map<String, dynamic>)['last_page'] as int;
      });

      if (page >= lastPage) {
        break;
      }
    }

    return projects;
  }

  String _logPath(String publicId) =>
      '/me/work-logs/${Uri.encodeComponent(publicId)}';

  WorkLog _parseLog(Map<String, dynamic>? body) {
    if (body == null) {
      throw const ApiRequestException(
        'Something went wrong. Please try again.',
      );
    }

    return _parse(() => WorkLog.fromJson(_data(body)));
  }

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
    } on TypeError {
      throw const ApiRequestException(
        'Something went wrong. Please try again.',
      );
    }
  }
}
