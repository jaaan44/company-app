import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/schedule/domain/schedule.dart';

/// The Schedule tab's calls (Phase 30A, docs/phases/V1_PHASE_30_DEFINITION.md
/// §5.2), through the shared authenticated [ApiClient], which owns every
/// session rule — nothing here repeats it.
///
/// Every method throws an [ApiException]; a body that doesn't match the
/// contract is reported as an [ApiRequestException] rather than a crash.
class ScheduleApiClient {
  ScheduleApiClient(this._apiClient);

  final ApiClient _apiClient;

  /// `GET /me/schedule?from=&to=` — my items over the company days
  /// [from]…[to] (`YYYY-MM-DD`, at most 31 days).
  Future<MySchedule> fetchMySchedule({
    required String from,
    required String to,
  }) async {
    final path = Uri(
      path: '/me/schedule',
      queryParameters: {'from': from, 'to': to},
    ).toString();
    final body = await _apiClient.getJson(path);

    return _parse(() => MySchedule.fromJson(body));
  }

  /// `GET /schedule-entries/{publicId}` — one entry I can see (`404` when
  /// it's gone or not mine to see).
  Future<ScheduleEntryDetail> fetchEntry(String publicId) async {
    final body = await _apiClient.getJson(
      '/schedule-entries/${Uri.encodeComponent(publicId)}',
    );

    return _parse(() {
      final data = body['data'];
      if (data is! Map<String, dynamic>) {
        throw const FormatException('Missing data object');
      }

      return ScheduleEntryDetail.fromJson(data);
    });
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
