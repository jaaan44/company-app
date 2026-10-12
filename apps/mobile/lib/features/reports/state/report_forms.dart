import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/projects/data/projects_api_client.dart';
import 'package:mobile/features/projects/domain/project.dart';
import 'package:mobile/features/reports/data/reports_api_client.dart';
import 'package:mobile/features/reports/domain/report.dart';
import 'package:mobile/features/reports/state/report_changes.dart';

enum ReportFormStatus { loading, ready, noProfile, error }

/// A picked client or project.
class PickedRef {
  const PickedRef({required this.publicId, required this.name});

  final String publicId;
  final String name;
}

/// Shared form behaviour: field errors, the server's `422` on the same
/// fields (others at the top), single-flight save, and "dirty".
abstract class _ReportForm extends ChangeNotifier {
  _ReportForm({this.changes});

  final ReportChanges? changes;

  ReportFormStatus _status = ReportFormStatus.loading;
  String? _loadError;
  final Map<String, String> _errors = {};
  String? _generalError;
  bool _isSaving = false;
  late String _initial;
  bool _disposed = false;

  ReportFormStatus get status => _status;
  String? get loadError => _loadError;
  Map<String, String> get fieldErrors => Map.unmodifiable(_errors);
  String? get generalError => _generalError;
  bool get isSaving => _isSaving;
  bool get isDirty => state() != _initial;

  /// The server field names this form shows inline.
  @protected
  Set<String> get fields;

  @protected
  String state();

  @protected
  void snapshot() => _initial = state();

  @protected
  void setError(String field, String message) => _errors[field] = message;

  @protected
  void clearError(String field) {
    _errors.remove(field);
    _generalError = null;
    notify();
  }

  @protected
  bool finishValidation() {
    notify();

    return _errors.isEmpty;
  }

  @protected
  void startValidation() {
    _errors.clear();
    _generalError = null;
  }

  @protected
  void setStatus(ReportFormStatus status, {String? loadError}) {
    _status = status;
    _loadError = loadError;
    notify();
  }

  /// Saves once with [send]. Returns the saved report or null.
  @protected
  Future<R?> save<R>(Future<R> Function() send) async {
    if (_isSaving || _status != ReportFormStatus.ready) {
      return null;
    }
    _isSaving = true;
    notify();
    try {
      final saved = await send();
      changes?.record();
      snapshot();

      return saved;
    } on ApiSessionExpiredException {
      return null;
    } on ApiValidationException catch (e) {
      for (final entry in e.fieldErrors.entries) {
        if (entry.value.isEmpty) {
          continue;
        }
        if (fields.contains(entry.key)) {
          _errors[entry.key] = entry.value.first;
        } else {
          _generalError ??= entry.value.first;
        }
      }
      if (_errors.isEmpty && _generalError == null) {
        _generalError = e.message;
      }

      return null;
    } on ApiException catch (e) {
      _generalError = switch (e) {
        ApiNetworkException() =>
          "Couldn't save. Check your connection and try again.",
        ApiForbiddenException(:final message) => message,
        ApiRequestException(statusCode: 409, :final message) => message,
        _ => "Couldn't save. Please try again.",
      };

      return null;
    } finally {
      _isSaving = false;
      notify();
    }
  }

  @protected
  void notify() {
    if (!_disposed) {
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}

String? _check(String value, int max, {bool required = false}) {
  final t = value.trim();
  if (required && t.isEmpty) {
    return 'This is required.';
  }

  return t.length > max ? 'Use at most $max characters.' : null;
}

/// New or edited service report (Phase 33, R-3): a client, an optional
/// project of mine for that client, a date no later than the company
/// today, and the texts. Checked before sending; saved once.
class ServiceReportFormController extends _ReportForm {
  ServiceReportFormController(
    this._client,
    this._projects, {
    this.existing,
    String? companyDate,
    super.changes,
  }) : _companyDate = companyDate {
    final r = existing;
    if (r != null) {
      _clientRef = PickedRef(publicId: r.client.publicId, name: r.client.name);
      _projectRef = r.project == null
          ? null
          : PickedRef(publicId: r.project!.publicId, name: r.project!.name);
      _date = r.serviceDate;
      work = r.workPerformed;
      findings = r.findings ?? '';
      recommendations = r.recommendations ?? '';
      followUp = r.followUpActions ?? '';
      siteRep = r.siteRepresentative ?? '';
    } else {
      _date = companyDate;
    }
    snapshot();
  }

  final ReportsApiClient _client;
  final ProjectsApiClient _projects;

  /// The report being edited, or null for a new one.
  final ServiceReport? existing;

  String? _companyDate;
  PickedRef? _clientRef;
  PickedRef? _projectRef;
  String? _date;
  List<Project> _myProjects = const [];
  String work = '';
  String findings = '';
  String recommendations = '';
  String followUp = '';
  String siteRep = '';

  bool get isEditing => existing != null;
  String? get companyDate => _companyDate;
  PickedRef? get clientRef => _clientRef;
  PickedRef? get projectRef => _projectRef;
  String? get serviceDate => _date;

  /// R-3: my open projects for the chosen client.
  List<Project> get projectOptions => [
    for (final p in _myProjects)
      if (!p.status.isClosed && p.client?.publicId == _clientRef?.publicId) p,
  ];

  @override
  Set<String> get fields => const {
    'client_id',
    'project_id',
    'service_date',
    'work_performed',
    'findings',
    'recommendations',
    'follow_up_actions',
    'site_representative_name',
  };

  Future<void> load() async {
    setStatus(ReportFormStatus.loading);
    try {
      final me = await _client.fetchMyStaffPublicId();
      if (me == null) {
        setStatus(ReportFormStatus.noProfile);

        return;
      }
      if (_companyDate == null) {
        final day = (await _client.fetchServiceReports()).companyDay.date;
        _companyDate = day;
        if (!isEditing) {
          _date ??= day;
          snapshot();
        }
      }
      final projects = <Project>[];
      for (var page = 1; page <= 5; page++) {
        final result = await _projects.fetchMyProjectsPage(me, page: page);
        projects.addAll(result.items);
        if (!result.hasMore) {
          break;
        }
      }
      _myProjects = List.unmodifiable(projects);
      setStatus(ReportFormStatus.ready);
    } on ApiSessionExpiredException {
      // The app is returning to Login.
    } on ApiForbiddenException {
      setStatus(ReportFormStatus.noProfile);
    } on ApiException catch (e) {
      setStatus(
        ReportFormStatus.error,
        loadError: e is ApiNetworkException
            ? "Couldn't load the form. Check your connection."
            : 'Something went wrong loading the form.',
      );
    }
  }

  void setClient(PickedRef value) {
    if (value.publicId != _clientRef?.publicId) {
      _projectRef = null;
    }
    _clientRef = value;
    clearError('client_id');
  }

  void setProject(PickedRef? value) {
    _projectRef = value;
    clearError('project_id');
  }

  void setDate(String value) {
    _date = value;
    clearError('service_date');
  }

  void setText(String field, String value) {
    switch (field) {
      case 'work_performed':
        work = value;
      case 'findings':
        findings = value;
      case 'recommendations':
        recommendations = value;
      case 'follow_up_actions':
        followUp = value;
      case 'site_representative_name':
        siteRep = value;
    }
    clearError(field);
  }

  bool validate() {
    startValidation();
    if (_clientRef == null) {
      setError('client_id', 'Choose a client.');
    }
    final date = _date;
    final today = _companyDate;
    if (date == null) {
      setError('service_date', 'Choose the service date.');
    } else if (today != null && date.compareTo(today) > 0) {
      setError('service_date', 'The service date cannot be later than today.');
    }
    for (final (field, value, max, required) in [
      ('work_performed', work, ReportLimits.maxLongText, true),
      ('findings', findings, ReportLimits.maxText, false),
      ('recommendations', recommendations, ReportLimits.maxText, false),
      ('follow_up_actions', followUp, ReportLimits.maxText, false),
      ('site_representative_name', siteRep, ReportLimits.maxShortText, false),
    ]) {
      final message = _check(value, max, required: required);
      if (message != null) {
        setError(
          field,
          field == 'work_performed' && message == 'This is required.'
              ? 'Describe the work performed.'
              : message,
        );
      }
    }

    return finishValidation();
  }

  /// Saves once. Returns the server's report, or null.
  Future<ServiceReport?> submit() async {
    if (isSaving || !validate()) {
      return null;
    }
    final draft = ServiceReportDraft(
      clientId: _clientRef!.publicId,
      projectId: _projectRef?.publicId,
      serviceDate: _date!,
      workPerformed: work,
      findings: findings,
      recommendations: recommendations,
      followUpActions: followUp,
      siteRepresentative: siteRep,
    );
    final r = existing;

    return save(
      () => r == null
          ? _client.createServiceReport(draft)
          : _client.updateServiceReport(r.publicId, draft),
    );
  }

  @override
  String state() =>
      '${_clientRef?.publicId}|${_projectRef?.publicId}|$_date|$work|'
      '$findings|$recommendations|$followUp|$siteRep';
}

/// New or edited incident (Phase 33, R-5).
class IncidentFormController extends _ReportForm {
  IncidentFormController(
    this._client, {
    this.existing,
    DateTime Function()? clock,
    super.changes,
  }) : _clock = clock ?? DateTime.now {
    final r = existing;
    if (r != null) {
      type = r.type == IncidentType.unknown ? null : r.type;
      severity = r.severity == IncidentSeverity.unknown
          ? IncidentSeverity.medium
          : r.severity;
      _occurredAt = r.occurredAt;
      location = r.location ?? '';
      description = r.description;
      immediate = r.immediateActionTaken ?? '';
      people = r.peopleInvolved ?? '';
      witnesses = r.witnessNotes ?? '';
    } else {
      // "Now" is an instant, not a calendar day (DEC-052 concerns days),
      // rounded down to the minute: a device clock a little ahead of the
      // server's would otherwise be refused as "in the future".
      final now = _clock().toUtc();
      _occurredAt = DateTime.utc(
        now.year,
        now.month,
        now.day,
        now.hour,
        now.minute,
      );
    }
    snapshot();
  }

  final ReportsApiClient _client;
  final DateTime Function() _clock;
  final IncidentReport? existing;

  IncidentType? type;
  IncidentSeverity severity = IncidentSeverity.medium;
  late DateTime _occurredAt;
  String location = '';
  String description = '';
  String immediate = '';
  String people = '';
  String witnesses = '';

  bool get isEditing => existing != null;

  /// UTC.
  DateTime get occurredAt => _occurredAt;

  @override
  Set<String> get fields => const {
    'incident_type',
    'severity',
    'occurred_at',
    'location',
    'description',
    'immediate_action_taken',
    'people_involved',
    'witness_notes',
  };

  Future<void> load() async {
    setStatus(ReportFormStatus.loading);
    try {
      final me = await _client.fetchMyStaffPublicId();
      setStatus(
        me == null ? ReportFormStatus.noProfile : ReportFormStatus.ready,
      );
    } on ApiSessionExpiredException {
      // The app is returning to Login.
    } on ApiException catch (e) {
      setStatus(
        ReportFormStatus.error,
        loadError: e is ApiNetworkException
            ? "Couldn't load the form. Check your connection."
            : 'Something went wrong loading the form.',
      );
    }
  }

  void setType(IncidentType value) {
    type = value;
    clearError('incident_type');
  }

  void setSeverity(IncidentSeverity value) {
    severity = value;
    clearError('severity');
  }

  void setOccurredAt(DateTime value) {
    _occurredAt = value.toUtc();
    clearError('occurred_at');
  }

  void setText(String field, String value) {
    switch (field) {
      case 'location':
        location = value;
      case 'description':
        description = value;
      case 'immediate_action_taken':
        immediate = value;
      case 'people_involved':
        people = value;
      case 'witness_notes':
        witnesses = value;
    }
    clearError(field);
  }

  bool validate() {
    startValidation();
    if (type == null) {
      setError('incident_type', 'Choose the type of incident.');
    }
    if (_occurredAt.isAfter(_clock().toUtc().add(const Duration(minutes: 1)))) {
      setError('occurred_at', 'The time cannot be in the future.');
    }
    for (final (field, value, max, required) in [
      ('location', location, ReportLimits.maxShortText, false),
      ('description', description, ReportLimits.maxLongText, true),
      ('immediate_action_taken', immediate, ReportLimits.maxText, false),
      ('people_involved', people, ReportLimits.maxText, false),
      ('witness_notes', witnesses, ReportLimits.maxText, false),
    ]) {
      final message = _check(value, max, required: required);
      if (message != null) {
        setError(
          field,
          field == 'description' && message == 'This is required.'
              ? 'Describe what happened.'
              : message,
        );
      }
    }

    return finishValidation();
  }

  Future<IncidentReport?> submit() async {
    if (isSaving || !validate()) {
      return null;
    }
    // Never later than the server's now: a few seconds' clock skew would
    // otherwise refuse "now".
    final now = _clock().toUtc();
    final draft = IncidentDraft(
      type: type!,
      severity: severity,
      occurredAt: _occurredAt.isAfter(now) ? now : _occurredAt,
      description: description,
      location: location,
      immediateActionTaken: immediate,
      peopleInvolved: people,
      witnessNotes: witnesses,
    );
    final r = existing;

    return save(
      () => r == null
          ? _client.createIncident(draft)
          : _client.updateIncident(r.publicId, draft),
    );
  }

  @override
  String state() =>
      '${type?.wireValue}|${severity.wireValue}|${_occurredAt.toIso8601String()}|'
      '$location|$description|$immediate|$people|$witnesses';
}
