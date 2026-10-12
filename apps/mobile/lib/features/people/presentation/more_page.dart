import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/app/auth_scope.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/my_status/data/my_status_api_client.dart';
import 'package:mobile/features/my_status/presentation/my_status_widgets.dart'
    show myStatusPath;

/// The `More` tab (Phase 28, R-8): only the real secondary areas — My
/// profile, (Phase 30B, R-13) My status, (Phase 32, R-6/R-7)
/// Announcements and Notifications, (Phase 31, R-1) Leave, Staff
/// directory, (Phase 29B) My work logs and (Phase 29C, R-27) Projects and
/// Clients. Later phases add
/// their own rows; there are no "coming soon" entries. Logout stays in the
/// Home app bar.
///
/// The profile subtitle is the signed-in account's name, already known
/// from sign-in. The My status subtitle is my current status (R-13), read
/// with one small request and again on returning from My status; until it
/// arrives, or if it can't, it reads "Your status and check-ins".
class MorePage extends StatefulWidget {
  const MorePage({super.key, required this.myStatusApiClient});

  final MyStatusApiClient myStatusApiClient;

  @override
  State<MorePage> createState() => _MorePageState();
}

class _MorePageState extends State<MorePage> {
  String? _currentStatus;
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _loadStatus();
  }

  Future<void> _loadStatus() async {
    final generation = ++_generation;
    String? label;
    try {
      final page = await widget.myStatusApiClient.fetchStatusHistory(
        perPage: 1,
      );
      final items = page.page.items;
      label = items.isEmpty ? 'No status set' : items.first.status.label;
    } on ApiException {
      // No profile, offline or a server error: keep the generic subtitle.
      label = null;
    }
    if (mounted && generation == _generation) {
      setState(() => _currentStatus = label);
    }
  }

  Future<void> _openMyStatus() async {
    await context.push(myStatusPath);
    if (mounted) {
      await _loadStatus();
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthScope.of(context).user;

    return Scaffold(
      appBar: AppBar(title: const Text('More')),
      body: ListView(
        children: [
          ListTile(
            key: const Key('more-profile'),
            leading: const Icon(Icons.account_circle_outlined),
            title: const Text('My profile'),
            subtitle: Text(user?.name ?? 'Your details and account'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.go('/more/profile'),
          ),
          ListTile(
            key: const Key('more-status'),
            leading: const Icon(Icons.place_outlined),
            title: const Text('My status'),
            subtitle: Text(_currentStatus ?? 'Your status and check-ins'),
            trailing: const Icon(Icons.chevron_right),
            onTap: _openMyStatus,
          ),
          ListTile(
            key: const Key('more-announcements'),
            leading: const Icon(Icons.campaign_outlined),
            title: const Text('Announcements'),
            subtitle: const Text('Company news'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.go('/more/announcements'),
          ),
          ListTile(
            key: const Key('more-notifications'),
            leading: const Icon(Icons.notifications_outlined),
            title: const Text('Notifications'),
            subtitle: const Text("What's new for you"),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.go('/more/notifications'),
          ),
          ListTile(
            key: const Key('more-leave'),
            leading: const Icon(Icons.beach_access_outlined),
            title: const Text('Leave'),
            subtitle: const Text('Balances and requests'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.go('/more/leave'),
          ),
          ListTile(
            key: const Key('more-directory'),
            leading: const Icon(Icons.people_outline),
            title: const Text('Staff directory'),
            subtitle: const Text('Find a colleague'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.go('/more/directory'),
          ),
          ListTile(
            key: const Key('more-work-logs'),
            leading: const Icon(Icons.schedule_outlined),
            title: const Text('My work logs'),
            subtitle: const Text('Time you have logged'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.go('/more/work-logs'),
          ),
          ListTile(
            key: const Key('more-projects'),
            leading: const Icon(Icons.folder_outlined),
            title: const Text('Projects'),
            subtitle: const Text("Projects you're a member of"),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.go('/more/projects'),
          ),
          ListTile(
            key: const Key('more-clients'),
            leading: const Icon(Icons.business_outlined),
            title: const Text('Clients'),
            subtitle: const Text('Company clients and contacts'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.go('/more/clients'),
          ),
        ],
      ),
    );
  }
}
