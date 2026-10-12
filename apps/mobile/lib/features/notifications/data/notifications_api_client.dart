import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/notifications/domain/app_notification.dart';

/// The notification calls (Phase 32, R-6) through the shared [ApiClient].
/// Notifications belong to the user account: no Staff record is needed.
class NotificationsApiClient {
  NotificationsApiClient(this._apiClient);

  static const pageSize = 20;

  final ApiClient _apiClient;

  /// `GET /me/notifications` — newest first.
  Future<NotificationsPage> fetchPage({int page = 1}) async {
    final body = await _apiClient.getJson(
      Uri(
        path: '/me/notifications',
        queryParameters: {'per_page': '$pageSize', 'page': '$page'},
      ).toString(),
    );

    return _parse(() => NotificationsPage.fromJson(body));
  }

  /// `POST /me/notifications/{id}/read` — idempotent.
  Future<AppNotification> markRead(String publicId) async {
    final body = await _apiClient.postJson(
      '/me/notifications/${Uri.encodeComponent(publicId)}/read',
    );

    return _parse(() {
      final data = body?['data'];
      if (data is! Map<String, dynamic>) {
        throw const FormatException('Missing data object');
      }

      return AppNotification.fromJson(data);
    });
  }

  /// `POST /me/notifications/read-all` — returns how many were updated.
  Future<int> markAllRead() async {
    final body = await _apiClient.postJson('/me/notifications/read-all');

    return _parse(
      () => (body!['data'] as Map<String, dynamic>)['updated_count'] as int,
    );
  }
}

T _parse<T>(T Function() parse) {
  try {
    return parse();
  } on FormatException {
    throw const ApiRequestException('Something went wrong. Please try again.');
  } on TypeError {
    throw const ApiRequestException('Something went wrong. Please try again.');
  }
}
