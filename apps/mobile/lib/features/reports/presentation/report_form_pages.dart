import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/clients/data/clients_api_client.dart';
import 'package:mobile/features/clients/state/clients_controller.dart';
import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/projects/data/projects_api_client.dart';
import 'package:mobile/features/reports/data/reports_api_client.dart';
import 'package:mobile/features/reports/domain/report.dart';
import 'package:mobile/features/reports/presentation/report_widgets.dart';
import 'package:mobile/features/reports/state/report_changes.dart';
import 'package:mobile/features/reports/state/report_forms.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;
import 'package:mobile/features/work_logs/presentation/work_log_widgets.dart'
    show apiDateOf, pickerDateOf, shiftApiDate;

/// Shared form chrome: the discard prompt, Back held while saving, and
/// the standard states.
mixin _FormChrome<W extends StatefulWidget> on State<W> {
  bool _leaving = false;

  void leave([Object? result]) {
    setState(() => _leaving = true);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        context.pop(result);
      }
    });
  }

  Future<void> confirmDiscard() async {
    final discard = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Discard changes?'),
        content: const Text("What you've entered won't be saved."),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep editing'),
          ),
          TextButton(
            key: const Key('report-discard-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Discard'),
          ),
        ],
      ),
    );
    if (discard == true && mounted) {
      leave();
    }
  }
}

Widget _field({
  required Key key,
  required String label,
  required String initial,
  required int max,
  required String? error,
  required bool enabled,
  required ValueChanged<String> onChanged,
  int minLines = 1,
  int maxLines = 1,
}) => TextFormField(
  key: key,
  initialValue: initial,
  enabled: enabled,
  minLines: minLines,
  maxLines: maxLines,
  maxLength: max,
  maxLengthEnforcement: MaxLengthEnforcement.none,
  keyboardType: maxLines > 1 ? TextInputType.multiline : TextInputType.text,
  textCapitalization: TextCapitalization.sentences,
  decoration: InputDecoration(
    labelText: label,
    alignLabelWithHint: maxLines > 1,
    border: const OutlineInputBorder(),
    errorText: error,
    errorMaxLines: 3,
  ),
  onChanged: onChanged,
);

/// A labelled, tappable field showing a choice, with its error beneath.
class _PickTile extends StatelessWidget {
  const _PickTile({
    super.key,
    required this.label,
    required this.value,
    required this.placeholder,
    required this.icon,
    this.error,
    this.onTap,
  });

  final String label;
  final String? value;
  final String placeholder;
  final IconData icon;
  final String? error;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Semantics(
          button: onTap != null,
          label: label,
          value: value ?? placeholder,
          excludeSemantics: true,
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(4),
            child: InputDecorator(
              decoration: InputDecoration(
                labelText: label,
                border: const OutlineInputBorder(),
                enabledBorder: OutlineInputBorder(
                  borderSide: BorderSide(
                    color: error == null
                        ? theme.colorScheme.outline
                        : theme.colorScheme.error,
                  ),
                ),
                prefixIcon: Icon(icon),
                suffixIcon: onTap == null
                    ? null
                    : const Icon(Icons.arrow_drop_down),
              ),
              child: Text(
                value ?? placeholder,
                style: value == null
                    ? theme.textTheme.bodyLarge?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      )
                    : theme.textTheme.bodyLarge,
              ),
            ),
          ),
        ),
        if (error case final message?)
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 6, 12, 0),
            child: Text(
              message,
              key: Key('${(key! as ValueKey<String>).value}-error'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
      ],
    );
  }
}

Widget _formBody(
  BuildContext context, {
  required ReportFormStatus status,
  required String? loadError,
  required VoidCallback onRetry,
  required List<Widget> Function(EdgeInsets padding) children,
}) {
  switch (status) {
    case ReportFormStatus.loading:
      return const Center(child: CircularProgressIndicator());
    case ReportFormStatus.noProfile:
      return const ReportsNoProfile();
    case ReportFormStatus.error:
      return PeopleErrorView(
        message: loadError ?? 'Something went wrong loading the form.',
        onRetry: onRetry,
      );
    case ReportFormStatus.ready:
      break;
  }

  return LayoutBuilder(
    builder: (context, constraints) {
      final padding = peopleListPadding(constraints);

      return ListView(
        key: const Key('report-form'),
        padding: EdgeInsets.fromLTRB(
          padding.left + 16,
          16,
          padding.right + 16,
          32,
        ),
        children: children(padding),
      );
    },
  );
}

/// New or edited service report (R-3). A new one, once saved, opens its
/// detail in place of the form; an edit returns to the detail.
class ServiceReportFormPage extends StatefulWidget {
  const ServiceReportFormPage({
    super.key,
    required this.reportsApiClient,
    required this.clientsApiClient,
    required this.projectsApiClient,
    required this.reportChanges,
    this.args,
  });

  final ReportsApiClient reportsApiClient;
  final ClientsApiClient clientsApiClient;
  final ProjectsApiClient projectsApiClient;
  final ReportChanges reportChanges;
  final ReportArgs<ServiceReport>? args;

  @override
  State<ServiceReportFormPage> createState() => _ServiceReportFormPageState();
}

class _ServiceReportFormPageState extends State<ServiceReportFormPage>
    with _FormChrome {
  late final ServiceReportFormController _form = ServiceReportFormController(
    widget.reportsApiClient,
    widget.projectsApiClient,
    existing: widget.args?.initial,
    companyDate: widget.args?.companyDay?.date,
    changes: widget.reportChanges,
  );

  @override
  void initState() {
    super.initState();
    _form.load();
  }

  @override
  void dispose() {
    _form.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    FocusScope.of(context).unfocus();
    final saved = await _form.submit();
    if (saved == null || !mounted) {
      return;
    }
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(_form.isEditing ? 'Changes saved.' : 'Draft saved.'),
        ),
      );
    if (_form.isEditing) {
      leave(saved);
    } else {
      setState(() => _leaving = true);
      context.pushReplacement(
        serviceReportPath(saved.publicId),
        extra: ReportArgs<ServiceReport>(
          initial: saved,
          myStaffId: saved.creator.publicId,
          companyDay: widget.args?.companyDay,
        ),
      );
    }
  }

  Future<void> _pickClient() async {
    final picked = await showModalBottomSheet<PickedRef>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _ClientPicker(client: widget.clientsApiClient),
    );
    if (picked != null) {
      _form.setClient(picked);
    }
  }

  Future<void> _pickProject() async {
    const none = PickedRef(publicId: '', name: 'No project');
    final picked = await showModalBottomSheet<PickedRef>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: ListView(
          key: const Key('report-project-picker'),
          shrinkWrap: true,
          children: [
            ListTile(
              key: const Key('report-project-none'),
              title: const Text('No project'),
              onTap: () => Navigator.of(context).pop(none),
            ),
            for (final p in _form.projectOptions)
              ListTile(
                key: Key('report-project-${p.publicId}'),
                title: Text(p.name),
                subtitle: p.projectCode == null ? null : Text(p.projectCode!),
                onTap: () =>
                    Navigator.of(context)
                        .pop(PickedRef(publicId: p.publicId, name: p.name)),
              ),
          ],
        ),
      ),
    );
    if (picked != null) {
      _form.setProject(picked.publicId.isEmpty ? null : picked);
    }
  }

  Future<void> _pickDate() async {
    final today = _form.companyDate;
    if (today == null) {
      return;
    }
    final picked = await showDatePicker(
      context: context,
      initialDate: pickerDateOf(_form.serviceDate ?? today),
      firstDate: pickerDateOf(shiftApiDate(today, -365)),
      lastDate: pickerDateOf(today),
      helpText: 'Service date',
    );
    if (picked != null) {
      _form.setDate(apiDateOf(picked));
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _form,
      builder: (context, _) => PopScope(
        canPop: _leaving || (!_form.isSaving && !_form.isDirty),
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop && !_form.isSaving) {
            confirmDiscard();
          }
        },
        child: Scaffold(
          appBar: AppBar(
            title: Text(
              _form.isEditing ? 'Edit service report' : 'New service report',
            ),
          ),
          body: _formBody(
            context,
            status: _form.status,
            loadError: _form.loadError,
            onRetry: _form.load,
            children: (_) => _fields(context),
          ),
        ),
      ),
    );
  }

  List<Widget> _fields(BuildContext context) {
    final e = _form.fieldErrors;
    final busy = _form.isSaving;
    final date = _form.serviceDate;
    final general = _form.generalError;

    return [
      if (general != null) ...[
        ReportErrorBanner(general, key: const Key('report-form-error')),
        const SizedBox(height: 16),
      ],
      _PickTile(
        key: const Key('service-form-client'),
        label: 'Client',
        value: _form.clientRef?.name,
        placeholder: 'Choose a client',
        icon: Icons.business_outlined,
        error: e['client_id'],
        onTap: busy || _form.isEditing ? null : _pickClient,
      ),
      const SizedBox(height: 12),
      _PickTile(
        key: const Key('service-form-project'),
        label: 'Project (optional)',
        value: _form.projectRef?.name,
        placeholder: _form.clientRef == null
            ? 'Choose a client first'
            : 'No project',
        icon: Icons.folder_outlined,
        error: e['project_id'],
        onTap: busy || _form.clientRef == null ? null : _pickProject,
      ),
      const SizedBox(height: 12),
      _PickTile(
        key: const Key('service-form-date'),
        label: 'Service date',
        value: date == null
            ? null
            : '${reportDateLabel(date)}'
                  '${date == _form.companyDate ? ' (today)' : ''}',
        placeholder: 'Choose a date',
        icon: Icons.event_outlined,
        error: e['service_date'],
        onTap: busy ? null : _pickDate,
      ),
      const SizedBox(height: 16),
      _field(
        key: const Key('service-form-work'),
        label: 'Work performed',
        initial: _form.work,
        max: ReportLimits.maxLongText,
        error: e['work_performed'],
        enabled: !busy,
        minLines: 3,
        maxLines: 8,
        onChanged: (v) => _form.setText('work_performed', v),
      ),
      const SizedBox(height: 12),
      for (final (key, label, field, value) in [
        (
          'service-form-findings',
          'Findings (optional)',
          'findings',
          _form.findings,
        ),
        (
          'service-form-recommendations',
          'Recommendations (optional)',
          'recommendations',
          _form.recommendations,
        ),
        (
          'service-form-follow-up',
          'Follow-up actions (optional)',
          'follow_up_actions',
          _form.followUp,
        ),
      ]) ...[
        _field(
          key: Key(key),
          label: label,
          initial: value,
          max: ReportLimits.maxText,
          error: e[field],
          enabled: !busy,
          minLines: 2,
          maxLines: 6,
          onChanged: (v) => _form.setText(field, v),
        ),
        const SizedBox(height: 12),
      ],
      _field(
        key: const Key('service-form-site-rep'),
        label: 'Site representative (optional)',
        initial: _form.siteRep,
        max: ReportLimits.maxShortText,
        error: e['site_representative_name'],
        enabled: !busy,
        onChanged: (v) => _form.setText('site_representative_name', v),
      ),
      const SizedBox(height: 16),
      FilledButton(
        key: const Key('service-form-save'),
        onPressed: busy ? null : _save,
        child: busy
            ? const SizedBox.square(
                dimension: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Text(_form.isEditing ? 'Save changes' : 'Save draft'),
      ),
      const SizedBox(height: 8),
      Text(
        "It's saved as a draft. You can add photos, then submit it for review.",
        style: Theme.of(context).textTheme.bodySmall
            ?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant),
      ),
    ];
  }
}

/// Active clients, searched (the Phase 29C clients list, in a sheet).
class _ClientPicker extends StatefulWidget {
  const _ClientPicker({required this.client});

  final ClientsApiClient client;

  @override
  State<_ClientPicker> createState() => _ClientPickerState();
}

class _ClientPickerState extends State<_ClientPicker> {
  late final ClientsController _clients = ClientsController(widget.client);

  @override
  void initState() {
    super.initState();
    _clients.load();
  }

  @override
  void dispose() {
    _clients.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.8,
      maxChildSize: 0.95,
      builder: (context, scroll) => ListenableBuilder(
        listenable: _clients,
        builder: (context, _) => ListView(
          key: const Key('report-client-picker'),
          controller: scroll,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: TextField(
                key: const Key('report-client-search'),
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search),
                  hintText: 'Search clients',
                  border: OutlineInputBorder(),
                ),
                onChanged: _clients.setQuery,
              ),
            ),
            if (_clients.status == PagedListStatus.loading)
              const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (_clients.status == PagedListStatus.error)
              ListTile(
                title: Text(_clients.errorMessage ?? "Couldn't load clients."),
                trailing: TextButton(
                  onPressed: _clients.load,
                  child: const Text('Try again'),
                ),
              )
            else if (_clients.items.isEmpty)
              const ListTile(title: Text('No clients found.'))
            else
              for (final c in _clients.items)
                ListTile(
                  key: Key('report-client-${c.publicId}'),
                  title: Text(c.name),
                  subtitle: c.clientCode == null ? null : Text(c.clientCode!),
                  onTap: () =>
                      Navigator.of(context)
                          .pop(PickedRef(publicId: c.publicId, name: c.name)),
                ),
            if (_clients.hasMore)
              TextButton(
                onPressed: _clients.loadMore,
                child: const Text('Show more'),
              ),
          ],
        ),
      ),
    );
  }
}

/// Report or edit an incident (R-5). Times are chosen and shown in
/// company time when the list gave the company day, else device time.
class IncidentFormPage extends StatefulWidget {
  const IncidentFormPage({
    super.key,
    required this.reportsApiClient,
    required this.reportChanges,
    this.args,
    this.clock,
  });

  final ReportsApiClient reportsApiClient;
  final ReportChanges reportChanges;
  final ReportArgs<IncidentReport>? args;
  final DateTime Function()? clock;

  @override
  State<IncidentFormPage> createState() => _IncidentFormPageState();
}

class _IncidentFormPageState extends State<IncidentFormPage> with _FormChrome {
  late final IncidentFormController _form = IncidentFormController(
    widget.reportsApiClient,
    existing: widget.args?.initial,
    clock: widget.clock,
    changes: widget.reportChanges,
  );

  ScheduleCompanyDay? get _day => widget.args?.companyDay;

  @override
  void initState() {
    super.initState();
    _form.load();
  }

  @override
  void dispose() {
    _form.dispose();
    super.dispose();
  }

  /// Wall-clock fields of [instant] in company (or device) time.
  DateTime _wall(DateTime instant) {
    final day = _day;

    return day == null ? instant.toLocal() : day.toCompanyTime(instant);
  }

  /// The instant for company (or device) wall-clock [wall].
  DateTime _instant(DateTime wall) {
    final day = _day;
    if (day == null) {
      return DateTime(
        wall.year,
        wall.month,
        wall.day,
        wall.hour,
        wall.minute,
      ).toUtc();
    }

    return DateTime.utc(
      wall.year,
      wall.month,
      wall.day,
      wall.hour,
      wall.minute,
    ).subtract(day.utcOffset);
  }

  Future<void> _pickDate() async {
    final current = _wall(_form.occurredAt);
    final today = _wall((widget.clock ?? DateTime.now)().toUtc());
    final picked = await showDatePicker(
      context: context,
      initialDate: DateTime(current.year, current.month, current.day),
      firstDate: DateTime(today.year - 1, today.month, today.day),
      lastDate: DateTime(today.year, today.month, today.day),
      helpText: 'Date it happened',
    );
    if (picked != null) {
      _form.setOccurredAt(
        _instant(
          DateTime(
            picked.year,
            picked.month,
            picked.day,
            current.hour,
            current.minute,
          ),
        ),
      );
    }
  }

  Future<void> _pickTime() async {
    final current = _wall(_form.occurredAt);
    final picked = await showTimePicker(
      context: context,
      initialTime: TimeOfDay(hour: current.hour, minute: current.minute),
      helpText: 'Time it happened',
    );
    if (picked != null) {
      _form.setOccurredAt(
        _instant(
          DateTime(
            current.year,
            current.month,
            current.day,
            picked.hour,
            picked.minute,
          ),
        ),
      );
    }
  }

  Future<void> _pickType() async {
    final picked = await showModalBottomSheet<IncidentType>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: ListView(
          key: const Key('incident-type-picker'),
          shrinkWrap: true,
          children: [
            for (final t in IncidentType.choices)
              ListTile(
                key: Key('incident-type-${t.wireValue}'),
                title: Text(t.label),
                selected: _form.type == t,
                trailing: _form.type == t ? const Icon(Icons.check) : null,
                onTap: () => Navigator.of(context).pop(t),
              ),
          ],
        ),
      ),
    );
    if (picked != null) {
      _form.setType(picked);
    }
  }

  Future<void> _save() async {
    FocusScope.of(context).unfocus();
    final saved = await _form.submit();
    if (saved == null || !mounted) {
      return;
    }
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(
            _form.isEditing ? 'Changes saved.' : 'Incident reported.',
          ),
        ),
      );
    if (_form.isEditing) {
      leave(saved);
    } else {
      setState(() => _leaving = true);
      context.pushReplacement(
        incidentPath(saved.publicId),
        extra: ReportArgs<IncidentReport>(
          initial: saved,
          myStaffId: saved.reporter.publicId,
          companyDay: _day,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _form,
      builder: (context, _) => PopScope(
        canPop: _leaving || (!_form.isSaving && !_form.isDirty),
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop && !_form.isSaving) {
            confirmDiscard();
          }
        },
        child: Scaffold(
          appBar: AppBar(
            title: Text(
              _form.isEditing ? 'Edit incident' : 'Report an incident',
            ),
          ),
          body: _formBody(
            context,
            status: _form.status,
            loadError: _form.loadError,
            onRetry: _form.load,
            children: (_) => _fields(context),
          ),
        ),
      ),
    );
  }

  List<Widget> _fields(BuildContext context) {
    final e = _form.fieldErrors;
    final busy = _form.isSaving;
    final wall = _wall(_form.occurredAt);
    final general = _form.generalError;

    return [
      if (general != null) ...[
        ReportErrorBanner(general, key: const Key('report-form-error')),
        const SizedBox(height: 16),
      ],
      _PickTile(
        key: const Key('incident-form-type'),
        label: 'Type of incident',
        value: _form.type?.label,
        placeholder: 'Choose a type',
        icon: Icons.category_outlined,
        error: e['incident_type'],
        onTap: busy ? null : _pickType,
      ),
      const SizedBox(height: 16),
      Semantics(
        header: true,
        child: Text('Severity', style: Theme.of(context).textTheme.titleSmall),
      ),
      const SizedBox(height: 8),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final s in IncidentSeverity.choices)
            ChoiceChip(
              key: Key('incident-severity-${s.wireValue}'),
              label: Text(s.label),
              selected: _form.severity == s,
              onSelected: busy ? null : (_) => _form.setSeverity(s),
            ),
        ],
      ),
      const SizedBox(height: 16),
      Row(
        children: [
          Expanded(
            child: _PickTile(
              key: const Key('incident-form-date'),
              label: 'Date',
              value: '${formatDayMonth(wall)} ${wall.year}',
              placeholder: '',
              icon: Icons.event_outlined,
              error: e['occurred_at'],
              onTap: busy ? null : _pickDate,
            ),
          ),
        ],
      ),
      const SizedBox(height: 12),
      _PickTile(
        key: const Key('incident-form-time'),
        label: _day == null ? 'Time (device time)' : 'Time',
        value: formatTime(wall),
        placeholder: '',
        icon: Icons.schedule_outlined,
        onTap: busy ? null : _pickTime,
      ),
      const SizedBox(height: 16),
      _field(
        key: const Key('incident-form-location'),
        label: 'Location (optional)',
        initial: _form.location,
        max: ReportLimits.maxShortText,
        error: e['location'],
        enabled: !busy,
        onChanged: (v) => _form.setText('location', v),
      ),
      const SizedBox(height: 12),
      _field(
        key: const Key('incident-form-description'),
        label: 'What happened',
        initial: _form.description,
        max: ReportLimits.maxLongText,
        error: e['description'],
        enabled: !busy,
        minLines: 3,
        maxLines: 8,
        onChanged: (v) => _form.setText('description', v),
      ),
      const SizedBox(height: 12),
      for (final (key, label, field, value) in [
        (
          'incident-form-immediate',
          'Immediate action taken (optional)',
          'immediate_action_taken',
          _form.immediate,
        ),
        (
          'incident-form-people',
          'People involved (optional)',
          'people_involved',
          _form.people,
        ),
        (
          'incident-form-witnesses',
          'Witness notes (optional)',
          'witness_notes',
          _form.witnesses,
        ),
      ]) ...[
        _field(
          key: Key(key),
          label: label,
          initial: value,
          max: ReportLimits.maxText,
          error: e[field],
          enabled: !busy,
          minLines: 2,
          maxLines: 6,
          onChanged: (v) => _form.setText(field, v),
        ),
        const SizedBox(height: 12),
      ],
      FilledButton(
        key: const Key('incident-form-save'),
        onPressed: busy ? null : _save,
        child: busy
            ? const SizedBox.square(
                dimension: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Text(_form.isEditing ? 'Save changes' : 'Report incident'),
      ),
    ];
  }
}
