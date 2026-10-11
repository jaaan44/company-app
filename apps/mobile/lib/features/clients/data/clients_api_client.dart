import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/features/clients/domain/client.dart';

/// The Clients area's calls (Phase 29C, docs/phases/V1_PHASE_29_DEFINITION.md
/// §7.4), all through the shared authenticated [ApiClient], which owns every
/// session rule — nothing here repeats it. Clients are company-wide
/// (`clients.view`) and need no linked Staff record.
///
/// Every method throws an [ApiException]; a body that doesn't match the
/// contract is reported as an [ApiRequestException] rather than a crash.
class ClientsApiClient {
  ClientsApiClient(this._apiClient);

  static const listPageSize = 25;

  /// A client's contacts load one page of this size (R-26).
  static const contactsPageSize = 50;

  final ApiClient _apiClient;

  /// `GET /clients` — one page of **active** clients only (R-26), in name
  /// order, optionally narrowed by [query] (name or code; trimmed, an empty
  /// one is not sent).
  Future<PagedResult<Client>> fetchClientsPage({
    String? query,
    int page = 1,
  }) async {
    final q = query?.trim() ?? '';
    final path = Uri(
      path: '/clients',
      queryParameters: {
        'status': 'active',
        'per_page': '$listPageSize',
        'page': '$page',
        if (q.isNotEmpty) 'q': q,
      },
    ).toString();
    final body = await _apiClient.getJson(path);

    return _parse(() => PagedResult.fromJson(body, Client.fromJson));
  }

  /// `GET /clients/{publicId}` — any client, active or not (a project's
  /// client may be inactive).
  Future<Client> fetchClient(String publicId) async {
    final body = await _apiClient.getJson(
      '/clients/${Uri.encodeComponent(publicId)}',
    );

    return _parse(() {
      final data = body['data'];
      if (data is! Map<String, dynamic>) {
        throw const FormatException('Missing data object');
      }

      return Client.fromJson(data);
    });
  }

  /// `GET /contacts?client=<publicId>&status=active` — the first
  /// [contactsPageSize] active contacts, by name, with the total.
  Future<PagedResult<ClientContact>> fetchContacts(
    String clientPublicId,
  ) async {
    final path = Uri(
      path: '/contacts',
      queryParameters: {
        'client': clientPublicId,
        'status': 'active',
        'per_page': '$contactsPageSize',
        'page': '1',
      },
    ).toString();
    final body = await _apiClient.getJson(path);

    return _parse(() => PagedResult.fromJson(body, ClientContact.fromJson));
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
