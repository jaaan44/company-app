import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/reports/data/photo_source.dart';
import 'package:mobile/features/reports/data/reports_api_client.dart';
import 'package:mobile/features/reports/domain/report.dart';
import 'package:mobile/features/reports/presentation/report_widgets.dart';
import 'package:mobile/features/reports/state/report_changes.dart';
import 'package:mobile/features/reports/state/report_detail_controllers.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

/// Asks before an action; true when confirmed.
Future<bool> _confirm(
  BuildContext context, {
  required String title,
  required String body,
  required String confirm,
  required String confirmKey,
  bool destructive = false,
}) async =>
    await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: Text(body),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          TextButton(
            key: Key(confirmKey),
            onPressed: () => Navigator.of(context).pop(true),
            style: destructive
                ? TextButton.styleFrom(
                    foregroundColor: Theme.of(context).colorScheme.error,
                  )
                : null,
            child: Text(confirm),
          ),
        ],
      ),
    ) ??
    false;

/// The common detail frame: states, refresh, the action banner, history
/// and photos (R-4/R-6/R-7).
class _DetailFrame<T> extends StatelessWidget {
  const _DetailFrame({
    required this.controller,
    required this.title,
    required this.companyDay,
    required this.header,
    required this.actions,
  });

  final ReportDetailController<T> controller;
  final String title;
  final ScheduleCompanyDay? companyDay;
  final List<Widget> Function(T report) header;
  final List<Widget> Function(T report) actions;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) => PopScope(
        // An upload or action already sent wouldn't stop on Back.
        canPop: !controller.isBusy,
        child: Scaffold(
          appBar: AppBar(title: Text(title)),
          body: LayoutBuilder(
            builder: (context, constraints) =>
                _body(context, peopleListPadding(constraints)),
          ),
        ),
      ),
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    switch (controller.status) {
      case ReportDetailStatus.loading:
        return const Center(child: CircularProgressIndicator());
      case ReportDetailStatus.noProfile:
        return const ReportsNoProfile();
      case ReportDetailStatus.error:
        return PeopleErrorView(
          message:
              controller.errorMessage ??
              'Something went wrong loading this report.',
          onRetry: controller.load,
        );
      case ReportDetailStatus.loaded:
        break;
    }
    final report = controller.data as T;
    final message = controller.actionMessage;

    return RefreshIndicator(
      onRefresh: controller.load,
      child: ListView(
        key: const Key('report-detail'),
        physics: const AlwaysScrollableScrollPhysics(),
        padding: padding,
        children: [
          if (message != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
              child: ReportErrorBanner(
                message,
                key: const Key('report-action-error'),
              ),
            ),
          ...header(report),
          ...actions(report),
          _Photos(controller: controller),
          const PeopleSectionHeader('History'),
          for (final (i, h) in _history(report).indexed)
            ListTile(
              key: Key('report-history-$i'),
              leading: const Icon(Icons.history),
              title: Text(
                [
                  h.actedBy == null
                      ? h.label
                      : '${h.label} by ${h.actedBy!.displayName}',
                  if (h.createdAt != null)
                    reportTimeLabel(h.createdAt!, companyDay),
                ].join(' · '),
              ),
              subtitle: h.note == null ? null : Text(h.note!),
            ),
          if (_history(report).isEmpty)
            const ListTile(title: Text('No history yet.')),
          const SizedBox(height: 32),
        ],
      ),
    );
  }

  List<ReportHistoryEntry> _history(T report) => switch (report) {
    ServiceReport(:final history) => history,
    IncidentReport(:final history) => history,
    _ => const [],
  };
}

/// Photos and other attachments (R-7).
class _Photos<T> extends StatelessWidget {
  const _Photos({required this.controller});

  final ReportDetailController<T> controller;

  Future<void> _open(BuildContext context, ReportAttachment a) async {
    Uint8List bytes;
    try {
      bytes = await controller.photoBytes(a);
    } on Exception {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text("Couldn't open the photo. Try again.")),
        );
      }

      return;
    }
    if (context.mounted) {
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          fullscreenDialog: true,
          builder: (_) => PhotoViewerPage(title: a.filename, bytes: bytes),
        ),
      );
    }
  }

  Future<void> _remove(BuildContext context, ReportAttachment a) async {
    if (await _confirm(
      context,
      title: 'Remove this photo?',
      body: a.filename,
      confirm: 'Remove',
      confirmKey: 'report-photo-remove-confirm',
      destructive: true,
    )) {
      await controller.removePhoto(a);
    }
  }

  @override
  Widget build(BuildContext context) {
    final attachments = controller.attachments;
    final canAttach = controller.canAttach;
    final busy = controller.busy;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PeopleSectionHeader('Photos and files (${attachments.length})'),
        if (attachments.isEmpty) const ListTile(title: Text('No photos yet.')),
        for (final a in attachments)
          ListTile(
            key: Key('report-attachment-${a.publicId}'),
            leading: Icon(
              a.isImage ? Icons.image_outlined : Icons.picture_as_pdf_outlined,
            ),
            title: Text(a.filename),
            subtitle: Text(
              a.isImage
                  ? fileSizeLabel(a.sizeBytes)
                  : '${fileSizeLabel(a.sizeBytes)} · Open it on the web',
            ),
            onTap: a.isImage ? () => _open(context, a) : null,
            trailing: canAttach
                ? IconButton(
                    key: Key('report-attachment-remove-${a.publicId}'),
                    tooltip: 'Remove ${a.filename}',
                    icon: const Icon(Icons.delete_outline),
                    onPressed: busy == null ? () => _remove(context, a) : null,
                  )
                : null,
          ),
        if (canAttach)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: busy == 'upload'
                ? const Row(
                    key: Key('report-uploading'),
                    children: [
                      SizedBox.square(
                        dimension: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                      SizedBox(width: 12),
                      Text('Uploading photo…'),
                    ],
                  )
                : Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      OutlinedButton.icon(
                        key: const Key('report-take-photo'),
                        onPressed: busy == null
                            ? () => controller.addPhoto(PhotoOrigin.camera)
                            : null,
                        icon: const Icon(Icons.photo_camera_outlined),
                        label: const Text('Take photo'),
                      ),
                      OutlinedButton.icon(
                        key: const Key('report-choose-photo'),
                        onPressed: busy == null
                            ? () => controller.addPhoto(PhotoOrigin.gallery)
                            : null,
                        icon: const Icon(Icons.photo_library_outlined),
                        label: const Text('Choose photo'),
                      ),
                    ],
                  ),
          ),
      ],
    );
  }
}

Widget _progressOr(bool busy, String label) => busy
    ? const SizedBox.square(
        dimension: 20,
        child: CircularProgressIndicator(strokeWidth: 2),
      )
    : Text(label);

/// A service report (R-4).
class ServiceReportPage extends StatefulWidget {
  const ServiceReportPage({
    super.key,
    required this.reportsApiClient,
    required this.reportChanges,
    required this.photoSource,
    required this.publicId,
    this.args,
  });

  final ReportsApiClient reportsApiClient;
  final ReportChanges reportChanges;
  final PhotoSource photoSource;
  final String publicId;
  final ReportArgs<ServiceReport>? args;

  @override
  State<ServiceReportPage> createState() => _ServiceReportPageState();
}

class _ServiceReportPageState extends State<ServiceReportPage> {
  late final ServiceReportController _c = ServiceReportController(
    widget.reportsApiClient,
    widget.publicId,
    photoSource: widget.photoSource,
    initial: widget.args?.initial,
    myStaffId: widget.args?.myStaffId,
    changes: widget.reportChanges,
  );

  @override
  void initState() {
    super.initState();
    _c.load();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  void _done(String message) => ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message)));

  Future<void> _edit(ServiceReport r) async {
    await context.push(
      '${serviceReportPath(r.publicId)}/edit',
      extra: ReportArgs<ServiceReport>(
        initial: r,
        myStaffId: _c.myStaffId,
        companyDay: widget.args?.companyDay,
      ),
    );
    if (mounted) {
      await _c.load();
    }
  }

  Future<void> _submit() async {
    if (await _confirm(
          context,
          title: 'Submit this report?',
          body:
              "Your manager will review it. You can't edit it after "
              'submitting.',
          confirm: 'Submit',
          confirmKey: 'report-submit-confirm',
        ) &&
        await _c.submit() &&
        mounted) {
      _done('Report submitted.');
    }
  }

  Future<void> _return() async {
    if (await _confirm(
          context,
          title: 'Return to draft?',
          body: 'You can then edit it and submit it again.',
          confirm: 'Return to draft',
          confirmKey: 'report-return-confirm',
        ) &&
        await _c.returnToDraft() &&
        mounted) {
      _done('Returned to draft.');
    }
  }

  Future<void> _delete() async {
    if (await _confirm(
          context,
          title: 'Delete this draft?',
          body: "This can't be undone.",
          confirm: 'Delete',
          confirmKey: 'report-delete-confirm',
          destructive: true,
        ) &&
        await _c.deleteReport() &&
        mounted) {
      _done('Draft deleted.');
      context.pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return _DetailFrame<ServiceReport>(
      controller: _c,
      title: 'Service report',
      companyDay: widget.args?.companyDay,
      header: (r) => [
        ListTile(
          title: Text(
            r.client.name,
            style: Theme.of(context).textTheme.titleLarge,
          ),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                [reportDateLabel(r.serviceDate), ?r.project?.name].join(' · '),
              ),
              Text('By ${r.creator.displayName}'),
              const SizedBox(height: 4),
              ReportChip(label: r.status.label, tone: serviceTone(r.status)),
            ],
          ),
        ),
        ReportTextSection(title: 'Work performed', text: r.workPerformed),
        ReportTextSection(title: 'Findings', text: r.findings),
        ReportTextSection(title: 'Recommendations', text: r.recommendations),
        ReportTextSection(title: 'Follow-up actions', text: r.followUpActions),
        ReportTextSection(
          title: 'Site representative',
          text: r.siteRepresentative,
        ),
      ],
      actions: (r) {
        final busy = _c.busy;

        return [
          if (_c.canEdit)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              child: Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  FilledButton(
                    key: const Key('report-submit'),
                    onPressed: busy == null ? _submit : null,
                    child: _progressOr(busy == 'submit', 'Submit'),
                  ),
                  OutlinedButton(
                    key: const Key('report-edit'),
                    onPressed: busy == null ? () => _edit(r) : null,
                    child: const Text('Edit'),
                  ),
                  TextButton(
                    key: const Key('report-delete'),
                    onPressed: busy == null ? _delete : null,
                    style: TextButton.styleFrom(
                      foregroundColor: Theme.of(context).colorScheme.error,
                    ),
                    child: _progressOr(busy == 'delete', 'Delete draft'),
                  ),
                ],
              ),
            ),
          if (_c.canReturnToDraft)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              child: OutlinedButton(
                key: const Key('report-return'),
                onPressed: busy == null ? _return : null,
                child: _progressOr(busy == 'return', 'Return to draft'),
              ),
            ),
        ];
      },
    );
  }
}

/// An incident (R-6).
class IncidentPage extends StatefulWidget {
  const IncidentPage({
    super.key,
    required this.reportsApiClient,
    required this.reportChanges,
    required this.photoSource,
    required this.publicId,
    this.args,
  });

  final ReportsApiClient reportsApiClient;
  final ReportChanges reportChanges;
  final PhotoSource photoSource;
  final String publicId;
  final ReportArgs<IncidentReport>? args;

  @override
  State<IncidentPage> createState() => _IncidentPageState();
}

class _IncidentPageState extends State<IncidentPage> {
  late final IncidentController _c = IncidentController(
    widget.reportsApiClient,
    widget.publicId,
    photoSource: widget.photoSource,
    initial: widget.args?.initial,
    myStaffId: widget.args?.myStaffId,
    changes: widget.reportChanges,
  );

  @override
  void initState() {
    super.initState();
    _c.load();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  Future<void> _edit(IncidentReport r) async {
    await context.push(
      '${incidentPath(r.publicId)}/edit',
      extra: ReportArgs<IncidentReport>(
        initial: r,
        myStaffId: _c.myStaffId,
        companyDay: widget.args?.companyDay,
      ),
    );
    if (mounted) {
      await _c.load();
    }
  }

  @override
  Widget build(BuildContext context) {
    final day = widget.args?.companyDay;

    return _DetailFrame<IncidentReport>(
      controller: _c,
      title: 'Incident',
      companyDay: day,
      header: (r) => [
        ListTile(
          title: Text(
            r.type.label,
            style: Theme.of(context).textTheme.titleLarge,
          ),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                [reportTimeLabel(r.occurredAt, day), ?r.location].join(' · '),
              ),
              Text('Reported by ${r.reporter.displayName}'),
              if (r.assignedTo != null)
                Text('Assigned to ${r.assignedTo!.displayName}'),
              const SizedBox(height: 4),
              Wrap(
                spacing: 6,
                runSpacing: 4,
                children: [
                  ReportChip(
                    label: r.severity.label,
                    tone: severityTone(r.severity),
                    semanticsPrefix: 'Severity',
                  ),
                  ReportChip(
                    label: r.status.label,
                    tone: incidentTone(r.status),
                  ),
                ],
              ),
            ],
          ),
        ),
        ReportTextSection(title: 'What happened', text: r.description),
        ReportTextSection(
          title: 'Immediate action taken',
          text: r.immediateActionTaken,
        ),
        ReportTextSection(title: 'People involved', text: r.peopleInvolved),
        ReportTextSection(title: 'Witness notes', text: r.witnessNotes),
        ReportTextSection(title: 'Root cause', text: r.rootCause),
        ReportTextSection(title: 'Corrective action', text: r.correctiveAction),
        ReportTextSection(title: 'Preventive action', text: r.preventiveAction),
        ReportTextSection(title: 'Follow-up actions', text: r.followUpActions),
        ReportTextSection(title: 'Resolution', text: r.resolution),
      ],
      actions: (r) => [
        if (_c.canEdit)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton(
                key: const Key('report-edit'),
                onPressed: _c.isBusy ? null : () => _edit(r),
                child: const Text('Edit'),
              ),
            ),
          ),
      ],
    );
  }
}
