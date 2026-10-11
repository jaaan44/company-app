import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/clients/data/clients_api_client.dart';
import 'package:mobile/features/clients/domain/client.dart';

/// State for More → Clients (Phase 29C, docs/phases/V1_PHASE_29_DEFINITION.md
/// §7.2/§7.4): active clients only (R-26), in name order with search and
/// paging. Company-wide; needs no linked Staff record.
///
/// Owned by its page for the page's lifetime (DEC-025).
class ClientsController extends PagedSearchController<Client> {
  ClientsController(this._client, {super.debounce});

  final ClientsApiClient _client;

  @override
  Future<PagedResult<Client>?> fetchPage({
    required String query,
    required int page,
  }) => _client.fetchClientsPage(query: query, page: page);

  @override
  String idOf(Client item) => item.publicId;

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() => "Couldn't load clients. Check your connection.",
    ApiForbiddenException() => "You don't have access to clients.",
    _ => 'Something went wrong loading clients.',
  };
}
