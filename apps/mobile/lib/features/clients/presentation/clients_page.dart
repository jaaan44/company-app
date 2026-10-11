import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/core/presentation/paged_search_list.dart';
import 'package:mobile/features/clients/data/clients_api_client.dart';
import 'package:mobile/features/clients/domain/client.dart';
import 'package:mobile/features/clients/state/clients_controller.dart';
import 'package:mobile/features/projects/presentation/project_widgets.dart';

/// More → Clients (Phase 29C, docs/phases/V1_PHASE_29_DEFINITION.md §7.5,
/// R-26): active clients, company-wide, in name order with a search by name
/// or code, paging and pull-to-refresh. Each row opens the client.
class ClientsPage extends StatefulWidget {
  const ClientsPage({super.key, required this.clientsApiClient});

  final ClientsApiClient clientsApiClient;

  @override
  State<ClientsPage> createState() => _ClientsPageState();
}

class _ClientsPageState extends State<ClientsPage> {
  late final ClientsController _controller = ClientsController(
    widget.clientsApiClient,
  );

  @override
  void initState() {
    super.initState();
    _controller.load();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Clients')),
      body: PagedSearchList<Client>(
        controller: _controller,
        keyPrefix: 'clients',
        searchHint: 'Search by name or code',
        emptyMessage: 'No active clients yet.',
        searchEmptyMessage: (q) => 'No client matches "$q".',
        errorFallback: 'Something went wrong loading clients.',
        rowBuilder: (context, client) => ListTile(
          key: Key('clients-row-${client.publicId}'),
          title: Text(client.name),
          subtitle: client.clientCode == null ? null : Text(client.clientCode!),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => context.push(clientPath(client.publicId)),
        ),
      ),
    );
  }
}
