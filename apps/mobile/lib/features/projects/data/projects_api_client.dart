import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/features/projects/domain/project.dart';

/// The Projects area's calls (Phase 29C, docs/phases/V1_PHASE_29_DEFINITION.md
/// §7.4), all through the shared authenticated [ApiClient], which owns every
/// session rule (401/403 handling) — nothing here repeats it.
///
/// Every method throws an [ApiException]; a body that doesn't match the
/// contract is reported as an [ApiRequestException] rather than a crash.
class ProjectsApiClient {
  ProjectsApiClient(this._apiClient);

  /// Page size for the projects list (always sent; the API's `per_page` is
  /// uncapped).
  static const listPageSize = 25;

  /// Members and milestones load one page of this size (R-22).
  static const detailPageSize = 50;

  final ApiClient _apiClient;

  /// `GET /me/profile` — the signed-in person's Staff public id, or null
  /// when no Staff record is linked.
  Future<String?> fetchMyStaffPublicId() async {
    final body = await _apiClient.getJson('/me/profile');

    return _parse(() {
      final staff = _data(body)['staff'];

      return staff == null
          ? null
          : (staff as Map<String, dynamic>)['public_id'] as String;
    });
  }

  /// `GET /projects?member=<staffPublicId>` — one page of the projects I'm
  /// a member of (R-20: every role, never "all projects"), in name order,
  /// optionally narrowed by [query] (name or code; trimmed, an empty one is
  /// not sent).
  Future<PagedResult<Project>> fetchMyProjectsPage(
    String staffPublicId, {
    String? query,
    int page = 1,
  }) async {
    final q = query?.trim() ?? '';
    final path = Uri(
      path: '/projects',
      queryParameters: {
        'member': staffPublicId,
        'per_page': '$listPageSize',
        'page': '$page',
        if (q.isNotEmpty) 'q': q,
      },
    ).toString();
    final body = await _apiClient.getJson(path);

    return _parse(() => PagedResult.fromJson(body, Project.fromJson));
  }

  /// `GET /projects/{publicId}`.
  Future<Project> fetchProject(String publicId) async {
    final body = await _apiClient.getJson(_projectPath(publicId));

    return _parse(() => Project.fromJson(_data(body)));
  }

  /// `GET /projects/{publicId}/members` — the first [detailPageSize]
  /// members, in server order, with the total.
  Future<PagedResult<ProjectMember>> fetchMembers(String publicId) async {
    final body = await _apiClient.getJson(
      '${_projectPath(publicId)}/members?per_page=$detailPageSize&page=1',
    );

    return _parse(() => PagedResult.fromJson(body, ProjectMember.fromJson));
  }

  /// `GET /projects/{publicId}/milestones` — the first [detailPageSize]
  /// milestones, by due date, with the total.
  Future<PagedResult<ProjectMilestone>> fetchMilestones(String publicId) async {
    final body = await _apiClient.getJson(
      '${_projectPath(publicId)}/milestones?per_page=$detailPageSize&page=1',
    );

    return _parse(() => PagedResult.fromJson(body, ProjectMilestone.fromJson));
  }

  String _projectPath(String publicId) =>
      '/projects/${Uri.encodeComponent(publicId)}';

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
