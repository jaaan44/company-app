import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/features/clients/data/clients_api_client.dart';
import 'package:mobile/features/clients/domain/client.dart';
import 'package:mobile/features/people/state/resource_controller.dart';

/// Everything a client's detail shows (R-26): the client and its active
/// contacts (one page of 50, with the total).
class ClientDetail {
  const ClientDetail({
    required this.client,
    required this.contacts,
    required this.contactsTotal,
  });

  final Client client;
  final List<ClientContact> contacts;
  final int contactsTotal;
}

/// State for one client's detail (Phase 29C, spec §7.2/§7.4). Any client
/// opens, active or not — a project's client may be inactive.
///
/// Owned by its page for the page's lifetime (DEC-025).
class ClientDetailController extends ResourceController<ClientDetail> {
  ClientDetailController(this._client, this.publicId);

  final ClientsApiClient _client;
  final String publicId;

  @override
  Future<ClientDetail> fetch() async {
    // Together, and both awaited: one failing never leaves the other's
    // error unhandled.
    final results = await Future.wait<Object>([
      _client.fetchClient(publicId),
      _client.fetchContacts(publicId),
    ]);
    final client = results[0] as Client;
    final contacts = results[1] as PagedResult<ClientContact>;

    return ClientDetail(
      client: client,
      contacts: contacts.items,
      contactsTotal: contacts.total,
    );
  }

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load this client. Check your connection.",
    ApiForbiddenException() => "You don't have access to clients.",
    ApiRequestException(statusCode: 404) => 'This client no longer exists.',
    _ => 'Something went wrong loading this client.',
  };
}
