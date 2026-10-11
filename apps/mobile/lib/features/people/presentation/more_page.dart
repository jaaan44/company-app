import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/app/auth_scope.dart';

/// The `More` tab (Phase 28, R-8): only the real secondary areas — My
/// profile, Staff directory, (Phase 29B) My work logs and (Phase 29C,
/// R-27) Projects and Clients. Later phases add
/// their own rows; there are no "coming soon" entries. Logout stays in the
/// Home app bar.
///
/// Fetches nothing: the profile subtitle is the signed-in account's name,
/// already known from sign-in.
class MorePage extends StatelessWidget {
  const MorePage({super.key});

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
