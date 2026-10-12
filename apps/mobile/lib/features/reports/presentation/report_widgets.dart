import 'dart:typed_data';

import 'package:flutter/material.dart';

import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/reports/domain/report.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

/// Shared pieces of the Reports screens (Phase 33).

const reportsPath = '/more/reports';
const newServiceReportPath = '/more/reports/service/new';
const newIncidentPath = '/more/reports/incidents/new';

String serviceReportPath(String id) =>
    '/more/reports/service/${Uri.encodeComponent(id)}';

String incidentPath(String id) =>
    '/more/reports/incidents/${Uri.encodeComponent(id)}';

/// What a list passes to a detail or a form.
class ReportArgs<T> {
  const ReportArgs({this.initial, this.myStaffId, this.companyDay});

  final T? initial;
  final String? myStaffId;
  final ScheduleCompanyDay? companyDay;
}

/// "Wed 14 Oct 2026" for an API date.
String reportDateLabel(String date) {
  final d = DateTime.parse(date);

  return '${formatDayMonth(d)} ${d.year}';
}

/// "Wed 14 Oct 2026 09:30" in company time, or device time (labelled)
/// without a company day.
String reportTimeLabel(DateTime instant, ScheduleCompanyDay? day) {
  if (day == null) {
    final local = instant.toLocal();

    return '${formatDayMonth(local)} ${local.year} ${formatTime(local)} '
        '(device time)';
  }
  final c = day.toCompanyTime(instant);

  return '${formatDayMonth(c)} ${c.year} ${formatTime(c)}';
}

/// A status or severity chip: the label always written and announced.
class ReportChip extends StatelessWidget {
  const ReportChip({
    super.key,
    required this.label,
    required this.tone,
    this.semanticsPrefix = 'Status',
  });

  final String label;
  final ReportTone tone;
  final String semanticsPrefix;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final (bg, fg) = switch (tone) {
      ReportTone.neutral => (
        scheme.surfaceContainerHighest,
        scheme.onSurfaceVariant,
      ),
      ReportTone.progress => (
        scheme.secondaryContainer,
        scheme.onSecondaryContainer,
      ),
      ReportTone.good => (scheme.tertiaryContainer, scheme.onTertiaryContainer),
      ReportTone.bad => (scheme.errorContainer, scheme.onErrorContainer),
    };

    return Semantics(
      label: '$semanticsPrefix: $label',
      excludeSemantics: true,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: bg,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
          child: Text(
            label,
            style: Theme.of(context).textTheme.labelMedium?.copyWith(color: fg),
          ),
        ),
      ),
    );
  }
}

enum ReportTone { neutral, progress, good, bad }

ReportTone serviceTone(ServiceReportStatus s) => switch (s) {
  ServiceReportStatus.draft => ReportTone.neutral,
  ServiceReportStatus.submitted => ReportTone.progress,
  ServiceReportStatus.reviewed => ReportTone.good,
  ServiceReportStatus.rejected => ReportTone.bad,
  ServiceReportStatus.unknown => ReportTone.neutral,
};

ReportTone incidentTone(IncidentStatus s) => switch (s) {
  IncidentStatus.reported => ReportTone.progress,
  IncidentStatus.underInvestigation => ReportTone.progress,
  IncidentStatus.resolved || IncidentStatus.closed => ReportTone.good,
  IncidentStatus.unknown => ReportTone.neutral,
};

ReportTone severityTone(IncidentSeverity s) => switch (s) {
  IncidentSeverity.high || IncidentSeverity.critical => ReportTone.bad,
  _ => ReportTone.neutral,
};

/// A titled block of text (selectable), skipped when empty.
class ReportTextSection extends StatelessWidget {
  const ReportTextSection({super.key, required this.title, required this.text});

  final String title;
  final String? text;

  @override
  Widget build(BuildContext context) {
    final t = text;
    if (t == null || t.trim().isEmpty) {
      return const SizedBox.shrink();
    }
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Semantics(
            header: true,
            child: Text(
              title,
              style: theme.textTheme.titleSmall?.copyWith(
                color: theme.colorScheme.primary,
              ),
            ),
          ),
          const SizedBox(height: 4),
          SelectableText(t, style: theme.textTheme.bodyLarge),
        ],
      ),
    );
  }
}

/// A photo opened full screen from memory (R-7), zoomable.
class PhotoViewerPage extends StatelessWidget {
  const PhotoViewerPage({super.key, required this.title, required this.bytes});

  final String title;
  final Uint8List bytes;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      backgroundColor: Colors.black,
      body: Center(
        child: InteractiveViewer(
          maxScale: 5,
          child: Image.memory(
            bytes,
            key: const Key('photo-viewer-image'),
            semanticLabel: title,
            errorBuilder: (context, error, stack) => const Text(
              "This photo can't be shown.",
              style: TextStyle(color: Colors.white),
            ),
          ),
        ),
      ),
    );
  }
}

/// The error banner used on forms and details.
class ReportErrorBanner extends StatelessWidget {
  const ReportErrorBanner(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Semantics(
      liveRegion: true,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: theme.colorScheme.errorContainer,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text(
          message,
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onErrorContainer,
          ),
        ),
      ),
    );
  }
}

/// The centred no-profile message (R-10).
class ReportsNoProfile extends StatelessWidget {
  const ReportsNoProfile({super.key});

  @override
  Widget build(BuildContext context) => const Center(
    child: Padding(
      padding: EdgeInsets.all(24),
      child: Text(
        'No staff profile is linked to this account.',
        key: Key('reports-no-profile'),
        textAlign: TextAlign.center,
      ),
    ),
  );
}
