import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/leave/domain/leave.dart';

/// The leave calls (Phase 31, docs/phases/V1_PHASE_31_DEFINITION.md),
/// through the shared authenticated [ApiClient], which owns every session
/// rule — nothing here repeats it.
///
/// A `403` from a `/me/leave-*` call that reaches a caller (after
/// `ApiClient`'s `/auth/me` re-check kept the session) means no Staff
/// record is linked (R-4); the controllers turn it into their no-profile
/// state.
///
/// Every method throws an [ApiException]; a body that doesn't match the
/// contract is reported as an [ApiRequestException] rather than a crash.
/// Writes are sent once and never retried.
class LeaveApiClient {
  LeaveApiClient(this._apiClient);

  /// Page size for the request lists, always sent (the API's own
  /// `per_page` is uncapped).
  static const pageSize = 20;

  final ApiClient _apiClient;

  /// `GET /me/leave-requests` — mine, newest first, with the company day.
  Future<MyLeaveRequestsPage> fetchMyRequests({
    int page = 1,
    int perPage = pageSize,
  }) async {
    final body = await _apiClient.getJson(
      _query('/me/leave-requests', {'per_page': '$perPage', 'page': '$page'}),
    );

    return _parse(() => MyLeaveRequestsPage.fromJson(body));
  }

  /// `GET /me/leave-balances` — the company year's, one per active type.
  Future<List<LeaveBalance>> fetchBalances() async {
    final body = await _apiClient.getJson('/me/leave-balances');

    return _parse(
      () => List<LeaveBalance>.unmodifiable(
        (body['data'] as List<dynamic>).map(
          (b) => LeaveBalance.fromJson(b as Map<String, dynamic>),
        ),
      ),
    );
  }

  /// `GET /me/leave-requests/{id}`.
  Future<LeaveRequest> fetchMyRequest(String publicId) async =>
      _request(await _apiClient.getJson(_myPath(publicId)));

  /// `POST /me/leave-requests`.
  Future<LeaveRequest> submit(LeaveDraft draft) async => _request(
    await _apiClient.postJson('/me/leave-requests', body: draft.toJson()),
  );

  /// `POST /me/leave-requests/{id}/cancel` with an optional reason.
  Future<LeaveRequest> cancel(String publicId, {String? reason}) async =>
      _request(
        await _apiClient.postJson(
          '${_myPath(publicId)}/cancel',
          body: {'reason': _text(reason)},
        ),
      );

  /// `GET /me/team-leave-requests` — my direct reports' requests (R-3).
  Future<TeamLeavePage> fetchTeamRequests({
    TeamLeaveFilter filter = TeamLeaveFilter.pending,
    int page = 1,
    int perPage = pageSize,
  }) async {
    final body = await _apiClient.getJson(
      _query('/me/team-leave-requests', {
        'status': filter.wireValue,
        'per_page': '$perPage',
        'page': '$page',
      }),
    );

    return _parse(() => TeamLeavePage.fromJson(body));
  }

  /// `GET /leave-requests/{id}` — one of my direct reports' requests.
  Future<LeaveRequest> fetchTeamRequest(String publicId) async =>
      _request(await _apiClient.getJson(_teamPath(publicId)));

  /// `POST /leave-requests/{id}/approve` with an optional note.
  Future<LeaveRequest> approve(String publicId, {String? note}) async =>
      _request(
        await _apiClient.postJson(
          '${_teamPath(publicId)}/approve',
          body: {'note': _text(note)},
        ),
      );

  /// `POST /leave-requests/{id}/reject` with a reason (3–1000).
  Future<LeaveRequest> reject(
    String publicId, {
    required String reason,
  }) async => _request(
    await _apiClient.postJson(
      '${_teamPath(publicId)}/reject',
      body: {'reason': reason.trim()},
    ),
  );

  String _myPath(String id) => '/me/leave-requests/${Uri.encodeComponent(id)}';

  String _teamPath(String id) => '/leave-requests/${Uri.encodeComponent(id)}';

  String _query(String path, Map<String, String> query) =>
      Uri(path: path, queryParameters: query).toString();

  static String? _text(String? value) {
    final trimmed = value?.trim() ?? '';

    return trimmed.isEmpty ? null : trimmed;
  }

  LeaveRequest _request(Map<String, dynamic>? body) => _parse(() {
    final data = body?['data'];
    if (data is! Map<String, dynamic>) {
      throw const FormatException('Missing data object');
    }

    return LeaveRequest.fromJson(data);
  });

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
