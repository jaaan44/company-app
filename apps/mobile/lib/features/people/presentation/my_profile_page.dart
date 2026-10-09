import 'package:flutter/material.dart';

import 'package:mobile/features/people/data/people_api_client.dart';
import 'package:mobile/features/people/domain/my_profile.dart';
import 'package:mobile/features/people/domain/staff_member.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/people/state/my_profile_controller.dart';
import 'package:mobile/features/people/state/resource_controller.dart';

/// My profile (Phase 28, spec §8): the signed-in person's own company
/// record and account, from `GET /me/profile`. **Read-only** — there is no
/// edit control; the page says who to ask instead. An account without a
/// linked staff record shows a clear message and the Account section only.
class MyProfilePage extends StatefulWidget {
  const MyProfilePage({super.key, required this.peopleApiClient});

  final PeopleApiClient peopleApiClient;

  @override
  State<MyProfilePage> createState() => _MyProfilePageState();
}

class _MyProfilePageState extends State<MyProfilePage> {
  late final MyProfileController _controller = MyProfileController(
    widget.peopleApiClient,
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
      appBar: AppBar(title: const Text('My profile')),
      body: ListenableBuilder(
        listenable: _controller,
        builder: (context, _) {
          final profile = _controller.data;

          if (profile != null) {
            return RefreshIndicator(
              onRefresh: _refresh,
              child: _ProfileContent(profile: profile),
            );
          }

          if (_controller.status == ResourceStatus.error) {
            return PeopleErrorView(
              message:
                  _controller.errorMessage ??
                  'Something went wrong loading your profile.',
              onRetry: _controller.load,
            );
          }

          return const Center(child: CircularProgressIndicator());
        },
      ),
    );
  }
}

class _ProfileContent extends StatelessWidget {
  const _ProfileContent({required this.profile});

  final MyProfile profile;

  @override
  Widget build(BuildContext context) {
    final staff = profile.staff;
    final user = profile.user;
    final secondary = Theme.of(context).textTheme.bodyMedium
        ?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant);

    return LayoutBuilder(
      builder: (context, constraints) => ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: peopleListPadding(constraints),
        children: [
          if (staff != null)
            _ProfileHeader(staff: staff)
          else
            Padding(
              key: const Key('profile-no-staff'),
              padding: const EdgeInsets.all(16),
              child: Text(
                'No staff profile is linked to this account.',
                style: Theme.of(context).textTheme.bodyLarge,
              ),
            ),
          if (staff != null) ...[
            const PeopleSectionHeader('Work', key: Key('profile-work')),
            PeopleInfoRow(
              icon: Icons.supervisor_account_outlined,
              label: 'Manager',
              value: staff.manager?.name,
            ),
            PeopleInfoRow(
              icon: Icons.email_outlined,
              label: 'Company email',
              value: staff.companyEmail,
            ),
            PeopleInfoRow(
              icon: Icons.phone_outlined,
              label: 'Company phone',
              value: staff.companyPhone,
            ),
            const PeopleSectionHeader(
              'Employment',
              key: Key('profile-employment'),
            ),
            PeopleInfoRow(
              icon: Icons.badge_outlined,
              label: 'Employee number',
              value: staff.employeeNumber,
            ),
            PeopleInfoRow(
              icon: Icons.event_outlined,
              label: 'Hire date',
              value: staff.hireDate == null
                  ? null
                  : formatApiDate(staff.hireDate),
            ),
          ],
          const PeopleSectionHeader('Account', key: Key('profile-account')),
          PeopleInfoRow(
            icon: Icons.alternate_email,
            label: 'Login email',
            value: user.email,
          ),
          PeopleInfoRow(
            icon: Icons.verified_user_outlined,
            label: 'Role',
            value: user.roleLabel,
          ),
          Padding(
            key: const Key('profile-read-only-note'),
            padding: const EdgeInsets.all(16),
            child: Text(
              'To change these details, contact an administrator.',
              style: secondary,
            ),
          ),
        ],
      ),
    );
  }
}

class _ProfileHeader extends StatelessWidget {
  const _ProfileHeader({required this.staff});

  final StaffMember staff;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final secondary = theme.textTheme.bodyMedium?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final roleLine = staff.roleLine;
    final team = staff.team?.name;

    return Padding(
      key: const Key('profile-header'),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Semantics(
            header: true,
            child: Text(
              staff.displayName,
              style: theme.textTheme.headlineSmall,
            ),
          ),
          if (staff.hasDistinctFullName) ...[
            const SizedBox(height: 2),
            Text(staff.fullName, style: secondary),
          ],
          if (roleLine != null) ...[
            const SizedBox(height: 4),
            Text(roleLine, style: secondary),
          ],
          if (team != null && team.isNotEmpty) ...[
            const SizedBox(height: 2),
            Text(team, style: secondary),
          ],
        ],
      ),
    );
  }
}
