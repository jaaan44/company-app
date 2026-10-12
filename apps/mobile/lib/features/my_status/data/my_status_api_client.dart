import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/my_status/domain/my_status.dart';

/// The My status calls (Phase 30B, docs/phases/V1_PHASE_30_DEFINITION.md
/// §6.4), through the shared authenticated [ApiClient], which owns every
/// session rule — nothing here repeats it.
///
/// A `403` that reaches a caller (after `ApiClient`'s `/auth/me` re-check
/// kept the session) means no Staff record is linked (R-15); the
/// controllers turn it into their no-profile state.
///
/// Every method throws an [ApiException]; a body that doesn't match the
/// contract is reported as an [ApiRequestException] rather than a crash.
class MyStatusApiClient {
  MyStatusApiClient(this._apiClient);

  /// Page size for the "All" lists, always sent (the API's own `per_page`
  /// is uncapped).
  static const pageSize = 20;

  final ApiClient _apiClient;

  /// `GET /me/status` — my status changes, newest first.
  Future<MyHistoryPage<StatusEntry>> fetchStatusHistory({
    int page = 1,
    int perPage = pageSize,
  }) async {
    final body = await _apiClient.getJson(_paged('/me/status', page, perPage));

    return _parse(() => MyHistoryPage.fromJson(body, StatusEntry.fromJson));
  }

  /// `POST /me/status` — sent once, never retried; the result is the
  /// server's record (R-16: never optimistic).
  Future<StatusEntry> setStatus(OperationalStatus status) async {
    if (status == OperationalStatus.unknown) {
      throw ArgumentError.value(status, 'status', 'Not a status to set');
    }
    final body = await _apiClient.postJson(
      '/me/status',
      body: {'status': status.wireValue},
    );

    return _parse(() => StatusEntry.fromJson(_data(body)));
  }

  /// `GET /me/check-ins` — my check-ins, newest first.
  Future<MyHistoryPage<CheckIn>> fetchCheckIns({
    int page = 1,
    int perPage = pageSize,
  }) async {
    final body = await _apiClient.getJson(
      _paged('/me/check-ins', page, perPage),
    );

    return _parse(() => MyHistoryPage.fromJson(body, CheckIn.fromJson));
  }

  /// `POST /me/check-ins` — sent once, never retried or queued (R-19).
  Future<CheckIn> checkIn(CheckInDraft draft) async {
    final body = await _apiClient.postJson(
      '/me/check-ins',
      body: draft.toJson(),
    );

    return _parse(() => CheckIn.fromJson(_data(body)));
  }

  String _paged(String path, int page, int perPage) => Uri(
    path: path,
    queryParameters: {'per_page': '$perPage', 'page': '$page'},
  ).toString();

  Map<String, dynamic> _data(Map<String, dynamic>? body) {
    final data = body?['data'];
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
