import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/reports/data/photo_source.dart';
import 'package:mobile/features/reports/data/reports_api_client.dart';
import 'package:mobile/features/reports/domain/report.dart';
import 'package:mobile/features/reports/state/report_changes.dart';

enum ReportDetailStatus { loading, loaded, noProfile, error }

/// A report's detail (Phase 33, R-4/R-6/R-7): loading (quietly when the
/// list gave it), photos added and removed, and the creator's actions.
/// Everything is confirmed by the server: after a change the report is
/// read again, never patched locally. One action at a time; nothing is
/// retried.
abstract class ReportDetailController<T> extends ChangeNotifier {
  ReportDetailController(
    this.client,
    this.publicId, {
    required this.kind,
    required this.photoSource,
    T? initial,
    String? myStaffId,
    this.changes,
  }) : _data = initial,
       _myStaffId = myStaffId, // ignore: prefer_initializing_formals
       _status = initial == null
           ? ReportDetailStatus.loading
           : ReportDetailStatus.loaded;

  final ReportsApiClient client;
  final String publicId;
  final ReportKind kind;
  final PhotoSource photoSource;
  final ReportChanges? changes;

  ReportDetailStatus _status;
  T? _data;
  String? _myStaffId;
  String? _errorMessage;
  String? _actionMessage;
  String? _busy;
  bool _deleted = false;
  bool _disposed = false;

  ReportDetailStatus get status => _status;
  T? get data => _data;
  String? get myStaffId => _myStaffId;
  String? get errorMessage => _errorMessage;

  /// The outcome of the last failed action, safe to show.
  String? get actionMessage => _actionMessage;

  /// What is running: `upload`, `remove`, `submit`, `return`, `delete`.
  String? get busy => _busy;
  bool get isBusy => _busy != null;

  /// True once the report was deleted (the page leaves).
  bool get deleted => _deleted;

  @protected
  Future<T> fetch();

  @protected
  List<ReportAttachment> attachmentsOf(T report);

  /// Whether photos can be added or removed now, by me.
  bool get canAttach;

  List<ReportAttachment> get attachments {
    final d = _data;

    return d == null ? const [] : attachmentsOf(d);
  }

  Future<void> load() async {
    if (_data == null) {
      _status = ReportDetailStatus.loading;
      _errorMessage = null;
      _notify();
    }
    try {
      _myStaffId ??= await client.fetchMyStaffPublicId();
      _data = await fetch();
      _status = ReportDetailStatus.loaded;
    } on ApiSessionExpiredException {
      // The app is returning to Login.
    } on ApiForbiddenException {
      if (_data == null) {
        _status = ReportDetailStatus.noProfile;
      }
    } on ApiException catch (e) {
      if (_data == null) {
        _status = ReportDetailStatus.error;
        _errorMessage = switch (e) {
          ApiNetworkException() =>
            "Couldn't load this report. Check your connection.",
          ApiRequestException(statusCode: 404) =>
            'This report is no longer available.',
          _ => 'Something went wrong loading this report.',
        };
      }
    } finally {
      _notify();
    }
  }

  /// Picks a photo and uploads it (R-7). Returns true when the server
  /// confirmed it. Cancelling the picker sends nothing.
  Future<bool> addPhoto(PhotoOrigin origin) async {
    if (isBusy || !canAttach) {
      return false;
    }
    final PickedPhoto? photo;
    try {
      photo = await photoSource.pick(origin);
    } on Exception {
      _actionMessage = origin == PhotoOrigin.camera
          ? "Couldn't open the camera."
          : "Couldn't open your photos.";
      _notify();

      return false;
    }
    if (photo == null) {
      return false;
    }
    if (photo.bytes.length > ReportLimits.maxAttachmentBytes) {
      _actionMessage = 'This photo is larger than 10 MB.';
      _notify();

      return false;
    }

    return _act('upload', () async {
      await client.upload(
        kind,
        publicId,
        bytes: photo!.bytes,
        filename: _jpegName(photo.filename),
      );
    });
  }

  Future<bool> removePhoto(ReportAttachment a) {
    if (!canAttach) {
      return Future.value(false);
    }

    return _act(
      'remove',
      () => client.deleteAttachment(kind, publicId, a.publicId),
    );
  }

  /// The photo's bytes, read through the authorized download (R-7).
  Future<Uint8List> photoBytes(ReportAttachment a) =>
      client.download(kind, publicId, a.publicId);

  /// Deletes the report (the page confirms first).
  Future<bool> deleteReport() async {
    final ok = await _act('delete', () => client.delete(kind, publicId));
    if (ok) {
      _deleted = true;
    }

    return ok;
  }

  /// Runs [send] once, then reads the report again (not after a delete).
  @protected
  Future<bool> act(String name, Future<void> Function() send) =>
      _act(name, send);

  Future<bool> _act(String name, Future<void> Function() send) async {
    if (isBusy) {
      return false;
    }
    _busy = name;
    _actionMessage = null;
    _notify();
    try {
      await send();
      changes?.record();
      if (name != 'delete') {
        try {
          _data = await fetch();
        } on ApiException {
          // The change happened; the next refresh shows it.
        }
      }

      return true;
    } on ApiSessionExpiredException {
      return false;
    } on ApiValidationException catch (e) {
      _actionMessage = e.firstErrorFor('file') ?? e.message;

      return false;
    } on ApiException catch (e) {
      _actionMessage = switch (e) {
        ApiNetworkException() =>
          "Couldn't do that. Check your connection and try again.",
        ApiForbiddenException(:final message) => message,
        ApiRequestException(statusCode: 409, :final message) => message,
        ApiRequestException(statusCode: 404) =>
          'This report is no longer available.',
        _ => "Couldn't do that. Please try again.",
      };
      if (e is ApiRequestException && e.statusCode == 409) {
        try {
          _data = await fetch();
        } on ApiException {
          // Keep what we have.
        }
      }

      return false;
    } finally {
      _busy = null;
      _notify();
    }
  }

  static String _jpegName(String name) {
    final lower = name.toLowerCase();
    if (lower.endsWith('.jpg') ||
        lower.endsWith('.jpeg') ||
        lower.endsWith('.png')) {
      return name;
    }

    return '$name.jpg';
  }

  void _notify() {
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

class ServiceReportController extends ReportDetailController<ServiceReport> {
  ServiceReportController(
    super.client,
    super.publicId, {
    required super.photoSource,
    super.initial,
    super.myStaffId,
    super.changes,
  }) : super(kind: ReportKind.service);

  @override
  Future<ServiceReport> fetch() => client.fetchServiceReport(publicId);

  @override
  List<ReportAttachment> attachmentsOf(ServiceReport r) => r.attachments;

  bool get canEdit => data?.canEditBy(myStaffId) ?? false;

  @override
  bool get canAttach => canEdit;

  bool get canReturnToDraft => data?.canReturnBy(myStaffId) ?? false;

  Future<bool> submit() => canEdit
      ? act('submit', () => client.serviceAction(publicId, 'submit'))
      : Future.value(false);

  Future<bool> returnToDraft() => canReturnToDraft
      ? act('return', () => client.serviceAction(publicId, 'return-to-draft'))
      : Future.value(false);

  @override
  Future<bool> deleteReport() =>
      canEdit ? super.deleteReport() : Future.value(false);
}

class IncidentController extends ReportDetailController<IncidentReport> {
  IncidentController(
    super.client,
    super.publicId, {
    required super.photoSource,
    super.initial,
    super.myStaffId,
    super.changes,
  }) : super(kind: ReportKind.incident);

  @override
  Future<IncidentReport> fetch() => client.fetchIncident(publicId);

  @override
  List<ReportAttachment> attachmentsOf(IncidentReport r) => r.attachments;

  bool get canEdit => data?.canEditBy(myStaffId) ?? false;

  @override
  bool get canAttach => canEdit;
}
