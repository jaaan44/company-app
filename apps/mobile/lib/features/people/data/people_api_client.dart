import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/people/domain/my_profile.dart';
import 'package:mobile/features/people/domain/staff_directory_page.dart';
import 'package:mobile/features/people/domain/staff_member.dart';

/// The People area's calls (Phase 28, docs/phases/V1_PHASE_28_DEFINITION.md
/// §7), all through the shared authenticated [ApiClient], which already owns
/// every session rule (401/403 handling) — nothing here repeats it.
///
/// Every method throws an [ApiException]; a body that doesn't match the
/// contract is reported as an [ApiRequestException] rather than a crash.
class PeopleApiClient {
  PeopleApiClient(this._apiClient);

  /// Page size for the directory: small enough for a phone screen, and
  /// always sent explicitly (the API's own `per_page` is uncapped).
  static const directoryPageSize = 25;

  final ApiClient _apiClient;

  /// `GET /me/profile` — the signed-in person's own profile.
  Future<MyProfile> fetchMyProfile() async {
    final body = await _apiClient.getJson('/me/profile');

    return _parse(() => MyProfile.fromJson(_data(body)));
  }

  /// `GET /staff` — one page of **active** staff only (R-1), optionally
  /// narrowed by a name search. [query] is trimmed; an empty one is not
  /// sent.
  Future<StaffDirectoryPage> fetchDirectoryPage({
    String? query,
    int page = 1,
  }) async {
    final q = query?.trim() ?? '';
    final path = Uri(
      path: '/staff',
      queryParameters: {
        'status': 'active',
        'per_page': '$directoryPageSize',
        'page': '$page',
        if (q.isNotEmpty) 'q': q,
      },
    ).toString();

    final body = await _apiClient.getJson(path);

    return _parse(() => StaffDirectoryPage.fromJson(body));
  }

  /// `GET /staff/{publicId}` — one colleague's directory entry.
  Future<StaffMember> fetchStaff(String publicId) async {
    final body = await _apiClient.getJson(
      '/staff/${Uri.encodeComponent(publicId)}',
    );

    return _parse(() => StaffMember.fromJson(_data(body)));
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
    }
  }
}
