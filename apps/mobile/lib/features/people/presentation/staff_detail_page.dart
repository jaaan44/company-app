import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/people/data/people_api_client.dart';
import 'package:mobile/features/people/domain/staff_member.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/people/state/resource_controller.dart';
import 'package:mobile/features/people/state/staff_detail_controller.dart';

/// One colleague's directory entry (Phase 28, spec §8, R-5): name, position,
/// department, team, manager and company contact details. Email and phone
/// can be copied (R-3); the manager opens the manager's own entry (R-7).
/// Employee number, employment dates and operational status are not shown.
class StaffDetailPage extends StatefulWidget {
  const StaffDetailPage({
    super.key,
    required this.peopleApiClient,
    required this.publicId,
  });

  final PeopleApiClient peopleApiClient;
  final String publicId;

  @override
  State<StaffDetailPage> createState() => _StaffDetailPageState();
}

class _StaffDetailPageState extends State<StaffDetailPage> {
  late final StaffDetailController _controller = StaffDetailController(
    widget.peopleApiClient,
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
      appBar: AppBar(title: const Text('Staff')),
      body: ListenableBuilder(
        listenable: _controller,
        builder: (context, _) {
          final member = _controller.data;

          if (member != null) {
            return RefreshIndicator(
              onRefresh: _refresh,
              child: _DetailContent(member: member),
            );
          }

          if (_controller.status == ResourceStatus.error) {
            return PeopleErrorView(
              message:
                  _controller.errorMessage ??
                  'Something went wrong loading this person.',
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
  const _DetailContent({required this.member});

  final StaffMember member;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final manager = member.manager;

    return LayoutBuilder(
      builder: (context, constraints) => ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: peopleListPadding(constraints),
        children: [
          Padding(
            key: const Key('detail-header'),
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Semantics(
                  header: true,
                  child: Text(
                    member.displayName,
                    style: theme.textTheme.headlineSmall,
                  ),
                ),
                if (member.hasDistinctFullName) ...[
                  const SizedBox(height: 2),
                  Text(
                    member.fullName,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ],
            ),
          ),
          PeopleInfoRow(
            icon: Icons.work_outline,
            label: 'Position',
            value: member.position?.name,
          ),
          PeopleInfoRow(
            icon: Icons.apartment_outlined,
            label: 'Department',
            value: member.department?.name,
          ),
          PeopleInfoRow(
            icon: Icons.groups_outlined,
            label: 'Team',
            value: member.team?.name,
          ),
          PeopleInfoRow(
            key: const Key('detail-manager'),
            icon: Icons.supervisor_account_outlined,
            label: 'Manager',
            value: manager?.name,
            onTap: manager == null
                ? null
                : () => context.push(
                    '/more/directory/${Uri.encodeComponent(manager.publicId)}',
                  ),
          ),
          PeopleInfoRow(
            key: const Key('detail-email'),
            icon: Icons.email_outlined,
            label: 'Company email',
            value: member.companyEmail,
            copyLabel: 'Copy email',
          ),
          PeopleInfoRow(
            key: const Key('detail-phone'),
            icon: Icons.phone_outlined,
            label: 'Company phone',
            value: member.companyPhone,
            copyLabel: 'Copy phone',
          ),
        ],
      ),
    );
  }
}
