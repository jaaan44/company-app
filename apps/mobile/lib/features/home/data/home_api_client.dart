import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/home/domain/home_summary.dart';

/// Loads the employee Home (`GET /api/v1/me/home`, Phase 27) through the
/// shared authenticated [ApiClient], which already owns every session rule
/// (401/403 handling) — nothing here repeats it.
class HomeApiClient {
  HomeApiClient(this._apiClient);

  final ApiClient _apiClient;

  /// Throws an [ApiException]; a body that doesn't match the contract is
  /// reported as an [ApiRequestException] rather than a crash.
  Future<HomeSummary> fetchHome() async {
    final body = await _apiClient.getJson('/me/home');

    try {
      final data = body['data'];
      if (data is! Map<String, dynamic>) {
        throw const FormatException('Missing /me/home data object');
      }

      return HomeSummary.fromJson(data);
    } on FormatException {
      throw const ApiRequestException(
        'Something went wrong. Please try again.',
      );
    }
  }
}
