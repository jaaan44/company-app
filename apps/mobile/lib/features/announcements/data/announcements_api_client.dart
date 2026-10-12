import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/announcements/domain/announcement.dart';

/// The announcement calls (Phase 32, R-7) through the shared [ApiClient].
/// A `403` that reaches a caller means no Staff record is linked (R-9).
class AnnouncementsApiClient {
  AnnouncementsApiClient(this._apiClient);

  static const pageSize = 20;

  final ApiClient _apiClient;

  /// `GET /me/announcements` — newest first.
  Future<AnnouncementsPage> fetchPage({int page = 1}) async {
    final body = await _apiClient.getJson(
      Uri(
        path: '/me/announcements',
        queryParameters: {'per_page': '$pageSize', 'page': '$page'},
      ).toString(),
    );

    return _parse(() => AnnouncementsPage.fromJson(body));
  }

  /// `GET /me/announcements/{id}`.
  Future<Announcement> fetch(String publicId) async =>
      _one(await _apiClient.getJson(_path(publicId)));

  /// `POST /me/announcements/{id}/acknowledge` — idempotent.
  Future<Announcement> acknowledge(String publicId) async =>
      _one(await _apiClient.postJson('${_path(publicId)}/acknowledge'));

  String _path(String id) => '/me/announcements/${Uri.encodeComponent(id)}';

  Announcement _one(Map<String, dynamic>? body) => _parse(() {
    final data = body?['data'];
    if (data is! Map<String, dynamic>) {
      throw const FormatException('Missing data object');
    }

    return Announcement.fromJson(data);
  });
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
