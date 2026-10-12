import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/core/presentation/paged_search_list.dart';
import 'package:mobile/features/reports/data/reports_api_client.dart';
import 'package:mobile/features/reports/domain/report.dart';
import 'package:mobile/features/reports/presentation/report_widgets.dart';
import 'package:mobile/features/reports/state/report_changes.dart';
import 'package:mobile/features/reports/state/report_lists.dart';

/// More → Reports (Phase 33, R-1): **Service reports** and **Incidents**,
/// each the reports I can see, newest first, with status chips; a **New**
/// button for the shown kind. Both reload after any confirmed change.
class ReportsPage extends StatefulWidget {
  const ReportsPage({
    super.key,
    required this.reportsApiClient,
    required this.reportChanges,
  });

  final ReportsApiClient reportsApiClient;
  final ReportChanges reportChanges;

  @override
  State<ReportsPage> createState() => _ReportsPageState();
}

class _ReportsPageState extends State<ReportsPage> {
  late final ServiceReportsController _services = ServiceReportsController(
    widget.reportsApiClient,
  );
  late final IncidentsController _incidents = IncidentsController(
    widget.reportsApiClient,
  );
  ReportKind _kind = ReportKind.service;

  @override
  void initState() {
    super.initState();
    _services.load();
    _incidents.load();
    widget.reportChanges.addListener(_reload);
  }

  @override
  void dispose() {
    widget.reportChanges.removeListener(_reload);
    _services.dispose();
    _incidents.dispose();
    super.dispose();
  }

  void _reload() {
    _services.refresh();
    _incidents.refresh();
  }

  @override
  Widget build(BuildContext context) {
    final service = _kind == ReportKind.service;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Reports'),
        actions: [
          // In the app bar, not floating or above the list: at large text
          // sizes those covered or squeezed the rows (Gate 3).
          IconButton(
            key: const Key('reports-new'),
            tooltip: service ? 'New service report' : 'Report an incident',
            icon: const Icon(Icons.add),
            onPressed: () => context.push(
              service ? newServiceReportPath : newIncidentPath,
              extra: ReportArgs<Never>(
                companyDay: service
                    ? _services.companyDay
                    : _incidents.companyDay,
              ),
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
            child: SegmentedButton<ReportKind>(
              key: const Key('reports-kind'),
              segments: const [
                ButtonSegment(
                  value: ReportKind.service,
                  label: Text('Service reports'),
                ),
                ButtonSegment(
                  value: ReportKind.incident,
                  label: Text('Incidents'),
                ),
              ],
              selected: {_kind},
              showSelectedIcon: false,
              onSelectionChanged: (s) => setState(() => _kind = s.first),
            ),
          ),
          Expanded(
            child: IndexedStack(
              index: service ? 0 : 1,
              children: [
                ListenableBuilder(
                  listenable: _services,
                  builder: (context, _) => PagedSearchList<ServiceReport>(
                    controller: _services,
                    keyPrefix: 'service-reports',
                    emptyMessage: "You haven't written a service report yet.",
                    errorFallback: 'Something went wrong loading reports.',
                    rowBuilder: (context, r) => _ServiceRow(
                      key: Key('service-reports-row-${r.publicId}'),
                      report: r,
                      onTap: () => context.push(
                        serviceReportPath(r.publicId),
                        extra: ReportArgs<ServiceReport>(
                          initial: r,
                          myStaffId: _services.myStaffId,
                          companyDay: _services.companyDay,
                        ),
                      ),
                    ),
                  ),
                ),
                ListenableBuilder(
                  listenable: _incidents,
                  builder: (context, _) => PagedSearchList<IncidentReport>(
                    controller: _incidents,
                    keyPrefix: 'incidents',
                    emptyMessage: 'No incidents reported.',
                    errorFallback: 'Something went wrong loading reports.',
                    rowBuilder: (context, r) => _IncidentRow(
                      key: Key('incidents-row-${r.publicId}'),
                      report: r,
                      when: reportTimeLabel(
                        r.occurredAt,
                        _incidents.companyDay,
                      ),
                      onTap: () => context.push(
                        incidentPath(r.publicId),
                        extra: ReportArgs<IncidentReport>(
                          initial: r,
                          myStaffId: _incidents.myStaffId,
                          companyDay: _incidents.companyDay,
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _ServiceRow extends StatelessWidget {
  const _ServiceRow({super.key, required this.report, required this.onTap});

  final ServiceReport report;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final r = report;

    return ListTile(
      onTap: onTap,
      leading: const Icon(Icons.assignment_outlined),
      title: Text(r.client.name),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text([reportDateLabel(r.serviceDate), ?r.project?.name].join(' · ')),
          const SizedBox(height: 4),
          ReportChip(label: r.status.label, tone: serviceTone(r.status)),
        ],
      ),
      trailing: const Icon(Icons.chevron_right),
    );
  }
}

class _IncidentRow extends StatelessWidget {
  const _IncidentRow({
    super.key,
    required this.report,
    required this.when,
    required this.onTap,
  });

  final IncidentReport report;
  final String when;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final r = report;

    return ListTile(
      onTap: onTap,
      leading: const Icon(Icons.report_outlined),
      title: Text(r.type.label),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text([when, ?r.location].join(' · ')),
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
              ReportChip(label: r.status.label, tone: incidentTone(r.status)),
            ],
          ),
        ],
      ),
      trailing: const Icon(Icons.chevron_right),
    );
  }
}
