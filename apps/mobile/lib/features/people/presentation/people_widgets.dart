import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:mobile/features/home/presentation/home_formatting.dart';

/// Shared building blocks for the People screens (Phase 28,
/// docs/phases/V1_PHASE_28_DEFINITION.md §8).

/// Readable content width on tablets — the same cap as Home.
const peopleMaxContentWidth = 640.0;

/// What a missing value reads as — never a blank.
const notSet = 'Not set';

/// "1 Mar 2024" for an API `YYYY-MM-DD` date; [notSet] when absent or
/// unparseable.
String formatApiDate(String? value) {
  final date = value == null ? null : DateTime.tryParse(value);

  return date == null ? notSet : formatDate(date);
}

/// Horizontal padding that centres content at [peopleMaxContentWidth] on
/// wide screens and keeps a 16px gutter on phones.
EdgeInsets peopleListPadding(BoxConstraints constraints) {
  final gutter = constraints.maxWidth > peopleMaxContentWidth + 32
      ? (constraints.maxWidth - peopleMaxContentWidth) / 2
      : 16.0;

  return EdgeInsets.symmetric(horizontal: gutter, vertical: 8);
}

/// A section title ("Work", "Employment", "Account").
class PeopleSectionHeader extends StatelessWidget {
  const PeopleSectionHeader(this.title, {super.key});

  final String title;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
      child: Semantics(
        header: true,
        child: Text(
          title,
          style: theme.textTheme.titleSmall?.copyWith(
            color: theme.colorScheme.primary,
          ),
        ),
      ),
    );
  }
}

/// One labelled value: the value on top, its label beneath. Missing values
/// read [notSet]. Not interactive unless [onTap] or [copyLabel] is given.
class PeopleInfoRow extends StatelessWidget {
  const PeopleInfoRow({
    super.key,
    required this.icon,
    required this.label,
    required this.value,
    this.onTap,
    this.copyLabel,
  });

  final IconData icon;
  final String label;
  final String? value;

  /// Makes the row navigate (a chevron is shown).
  final VoidCallback? onTap;

  /// When set and [value] is present, shows a copy button with this
  /// tooltip (R-3: copy, no tap-to-call/email).
  final String? copyLabel;

  @override
  Widget build(BuildContext context) {
    final present = value != null && value!.isNotEmpty;
    final theme = Theme.of(context);

    Widget? trailing;
    if (onTap != null) {
      trailing = const Icon(Icons.chevron_right);
    } else if (present && copyLabel != null) {
      trailing = IconButton(
        icon: const Icon(Icons.copy_outlined),
        tooltip: copyLabel,
        onPressed: () => _copy(context, value!),
      );
    }

    return ListTile(
      leading: Icon(icon),
      title: Text(
        present ? value! : notSet,
        style: present
            ? null
            : TextStyle(color: theme.colorScheme.onSurfaceVariant),
      ),
      subtitle: Text(label),
      trailing: trailing,
      onTap: onTap,
    );
  }

  Future<void> _copy(BuildContext context, String text) async {
    await Clipboard.setData(ClipboardData(text: text));
    if (context.mounted) {
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(const SnackBar(content: Text('Copied')));
    }
  }
}

/// Full-screen error with "Try again" — the Phase 27 Home error pattern.
class PeopleErrorView extends StatelessWidget {
  const PeopleErrorView({
    super.key,
    required this.message,
    required this.onRetry,
  });

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Semantics(
              liveRegion: true,
              child: Text(
                message,
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
            const SizedBox(height: 16),
            FilledButton(onPressed: onRetry, child: const Text('Try again')),
          ],
        ),
      ),
    );
  }
}

/// Pull-to-refresh failure notice, shown while earlier content stays.
void showRefreshFailed(BuildContext context) {
  ScaffoldMessenger.of(context).showSnackBar(
    const SnackBar(
      content: Text("Couldn't refresh. Showing earlier information."),
    ),
  );
}
