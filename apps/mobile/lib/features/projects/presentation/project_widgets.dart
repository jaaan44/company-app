import 'package:flutter/material.dart';

import 'package:mobile/features/projects/domain/project.dart';

/// Shared pieces for the Projects and Clients screens (Phase 29C,
/// docs/phases/V1_PHASE_29_DEFINITION.md §7.5).

/// Routes.
const projectsPath = '/more/projects';
const clientsPath = '/more/clients';

String projectPath(String publicId) =>
    '$projectsPath/${Uri.encodeComponent(publicId)}';

String clientPath(String publicId) =>
    '$clientsPath/${Uri.encodeComponent(publicId)}';

String staffPath(String publicId) =>
    '/more/directory/${Uri.encodeComponent(publicId)}';

/// A labelled status chip, styled like the Tasks status chip: colour is
/// never the only signal — the text is always shown and announced.
class StatusLabelChip extends StatelessWidget {
  const StatusLabelChip({
    super.key,
    required this.label,
    required this.tone,
    this.semanticsPrefix = 'Status',
  });

  final String label;
  final ChipTone tone;
  final String semanticsPrefix;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final (background, foreground) = switch (tone) {
      ChipTone.neutral => (
        scheme.surfaceContainerHighest,
        scheme.onSurfaceVariant,
      ),
      ChipTone.active => (scheme.primaryContainer, scheme.onPrimaryContainer),
      ChipTone.paused => (
        scheme.secondaryContainer,
        scheme.onSecondaryContainer,
      ),
      ChipTone.done => (scheme.tertiaryContainer, scheme.onTertiaryContainer),
      ChipTone.muted => (scheme.surfaceContainer, scheme.outline),
    };

    return Semantics(
      label: '$semanticsPrefix: $label',
      excludeSemantics: true,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: background,
          borderRadius: BorderRadius.circular(8),
          border: tone == ChipTone.muted
              ? Border.all(color: scheme.outlineVariant)
              : null,
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
          child: Text(
            label,
            style: Theme.of(context).textTheme.labelMedium
                ?.copyWith(color: foreground),
          ),
        ),
      ),
    );
  }
}

enum ChipTone { neutral, active, paused, done, muted }

class ProjectStatusChip extends StatelessWidget {
  const ProjectStatusChip(this.status, {super.key});

  final ProjectStatus status;

  @override
  Widget build(BuildContext context) => StatusLabelChip(
    key: Key('project-status-${status.wireValue}'),
    label: status.label,
    tone: switch (status) {
      ProjectStatus.planned => ChipTone.neutral,
      ProjectStatus.active => ChipTone.active,
      ProjectStatus.onHold => ChipTone.paused,
      ProjectStatus.completed => ChipTone.done,
      ProjectStatus.cancelled => ChipTone.muted,
    },
  );
}

class MilestoneStatusChip extends StatelessWidget {
  const MilestoneStatusChip(this.status, {super.key});

  final MilestoneStatus status;

  @override
  Widget build(BuildContext context) => StatusLabelChip(
    key: Key('milestone-status-${status.wireValue}'),
    label: status.label,
    tone: switch (status) {
      MilestoneStatus.pending => ChipTone.neutral,
      MilestoneStatus.completed => ChipTone.done,
      MilestoneStatus.cancelled => ChipTone.muted,
    },
  );
}

/// "Showing 50 of 53" under a list that loads only its first page (R-22,
/// R-26); nothing when everything is shown.
class ShowingCountNote extends StatelessWidget {
  const ShowingCountNote({super.key, required this.shown, required this.total});

  final int shown;
  final int total;

  @override
  Widget build(BuildContext context) {
    if (total <= shown) {
      return const SizedBox.shrink();
    }
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
      child: Text(
        'Showing $shown of $total',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }
}

/// A muted one-line note inside a detail section ("No members yet.").
class SectionNote extends StatelessWidget {
  const SectionNote(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
      child: Text(
        text,
        style: theme.textTheme.bodyMedium?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }
}
