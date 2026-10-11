import 'package:flutter/material.dart';

import 'package:mobile/features/clients/data/clients_api_client.dart';
import 'package:mobile/features/clients/domain/client.dart';
import 'package:mobile/features/clients/state/client_detail_controller.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/people/state/resource_controller.dart';
import 'package:mobile/features/projects/presentation/project_widgets.dart';

/// One client (Phase 29C, docs/phases/V1_PHASE_29_DEFINITION.md §7.5,
/// R-26): name and code ("Inactive" when it is), email, phone, website and
/// address, each with Copy (Phase 28 R-3 — no tap-to-call), then its active
/// contacts with "Primary" marked and their email and phone to copy. Notes
/// are not shown (R-24).
class ClientDetailPage extends StatefulWidget {
  const ClientDetailPage({
    super.key,
    required this.clientsApiClient,
    required this.publicId,
  });

  final ClientsApiClient clientsApiClient;
  final String publicId;

  @override
  State<ClientDetailPage> createState() => _ClientDetailPageState();
}

class _ClientDetailPageState extends State<ClientDetailPage> {
  late final ClientDetailController _controller = ClientDetailController(
    widget.clientsApiClient,
    widget.publicId,
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

  Future<void> _refresh() async {
    if (!await _controller.refresh() && mounted) {
      showRefreshFailed(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Client')),
      body: ListenableBuilder(
        listenable: _controller,
        builder: (context, _) {
          final detail = _controller.data;

          if (detail != null) {
            return RefreshIndicator(
              onRefresh: _refresh,
              child: _DetailContent(detail: detail),
            );
          }

          if (_controller.status == ResourceStatus.error) {
            return PeopleErrorView(
              message:
                  _controller.errorMessage ??
                  'Something went wrong loading this client.',
              onRetry: _controller.load,
            );
          }

          return const Center(child: CircularProgressIndicator());
        },
      ),
    );
  }
}

class _DetailContent extends StatelessWidget {
  const _DetailContent({required this.detail});

  final ClientDetail detail;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final client = detail.client;
    final address = client.addressLines;

    return LayoutBuilder(
      builder: (context, constraints) => ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: peopleListPadding(constraints),
        children: [
          Padding(
            key: const Key('client-header'),
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Semantics(
                  header: true,
                  child: Text(
                    client.name,
                    style: theme.textTheme.headlineSmall,
                  ),
                ),
                if (client.clientCode case final code?) ...[
                  const SizedBox(height: 2),
                  Text(
                    code,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
                if (client.isInactive) ...[
                  const SizedBox(height: 8),
                  const StatusLabelChip(
                    key: Key('client-inactive'),
                    label: 'Inactive',
                    tone: ChipTone.muted,
                  ),
                ],
              ],
            ),
          ),
          PeopleInfoRow(
            key: const Key('client-email'),
            icon: Icons.email_outlined,
            label: 'Email',
            value: client.email,
            copyLabel: 'Copy email',
          ),
          PeopleInfoRow(
            key: const Key('client-phone'),
            icon: Icons.phone_outlined,
            label: 'Phone',
            value: client.phone,
            copyLabel: 'Copy phone',
          ),
          PeopleInfoRow(
            key: const Key('client-website'),
            icon: Icons.language_outlined,
            label: 'Website',
            value: client.website,
            copyLabel: 'Copy website',
          ),
          PeopleInfoRow(
            key: const Key('client-address'),
            icon: Icons.place_outlined,
            label: 'Address',
            value: address.isEmpty ? null : address.join('\n'),
            copyLabel: 'Copy address',
          ),
          PeopleSectionHeader('Contacts (${detail.contactsTotal})'),
          if (detail.contacts.isEmpty)
            const SectionNote(
              'No active contacts.',
              key: Key('client-no-contacts'),
            ),
          for (final contact in detail.contacts)
            _ContactBlock(contact: contact),
          ShowingCountNote(
            key: const Key('client-contacts-more'),
            shown: detail.contacts.length,
            total: detail.contactsTotal,
          ),
        ],
      ),
    );
  }
}

class _ContactBlock extends StatelessWidget {
  const _ContactBlock({required this.contact});

  final ClientContact contact;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      key: Key('client-contact-${contact.publicId}'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ListTile(
          leading: const Icon(Icons.person_outline),
          title: Wrap(
            spacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(contact.fullName),
              if (contact.isPrimary)
                const StatusLabelChip(
                  key: Key('client-contact-primary'),
                  label: 'Primary',
                  tone: ChipTone.active,
                  semanticsPrefix: 'Contact',
                ),
            ],
          ),
          subtitle: contact.jobTitle == null ? null : Text(contact.jobTitle!),
        ),
        if (contact.email != null)
          _CopyLine(
            icon: Icons.email_outlined,
            label: 'Email',
            value: contact.email!,
            copyLabel: 'Copy email',
          ),
        if (contact.phone != null)
          _CopyLine(
            icon: Icons.phone_outlined,
            label: 'Phone',
            value: contact.phone!,
            copyLabel: 'Copy phone',
          ),
        Divider(color: theme.colorScheme.outlineVariant, height: 16),
      ],
    );
  }
}

/// A contact's email or phone, indented under the name, with Copy.
class _CopyLine extends StatelessWidget {
  const _CopyLine({
    required this.icon,
    required this.label,
    required this.value,
    required this.copyLabel,
  });

  final IconData icon;
  final String label;
  final String value;
  final String copyLabel;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(left: 40),
    child: PeopleInfoRow(
      icon: icon,
      label: label,
      value: value,
      copyLabel: copyLabel,
    ),
  );
}
