import 'dart:typed_data';

import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/reports/domain/report.dart';

/// Which report API a call is for.
enum ReportKind {
  service('/service-reports'),
  incident('/incident-reports');

  const ReportKind(this.basePath);

  final String basePath;
}

/// The report calls (Phase 33, R-1…R-9) through the shared [ApiClient]:
/// lists, create, update, delete, the service report lifecycle actions
/// the creator owns, and attachments (multipart upload, authorized
/// download into memory, delete). Writes are sent once.
class ReportsApiClient {
  ReportsApiClient(this._apiClient);

  static const pageSize = 20;

  final ApiClient _apiClient;

  Future<ReportListPage<ServiceReport>> fetchServiceReports({int page = 1}) =>
      _page(ReportKind.service, page, ServiceReport.fromJson);

  Future<ReportListPage<IncidentReport>> fetchIncidents({int page = 1}) =>
      _page(ReportKind.incident, page, IncidentReport.fromJson);

  Future<ServiceReport> fetchServiceReport(String id) async => _one(
    await _apiClient.getJson(_path(ReportKind.service, id)),
    ServiceReport.fromJson,
  );

  Future<IncidentReport> fetchIncident(String id) async => _one(
    await _apiClient.getJson(_path(ReportKind.incident, id)),
    IncidentReport.fromJson,
  );

  /// `POST /service-reports` — a new draft.
  Future<ServiceReport> createServiceReport(ServiceReportDraft draft) async =>
      _one(
        await _apiClient.postJson(
          ReportKind.service.basePath,
          body: draft.toJson(),
        ),
        ServiceReport.fromJson,
      );

  /// `PATCH /service-reports/{id}`.
  Future<ServiceReport> updateServiceReport(
    String id,
    ServiceReportDraft draft,
  ) async => _one(
    await _apiClient.patchJson(_path(ReportKind.service, id), draft.toJson()),
    ServiceReport.fromJson,
  );

  /// `POST /service-reports/{id}/submit` or `/return-to-draft`.
  Future<ServiceReport> serviceAction(String id, String action) async => _one(
    await _apiClient.postJson('${_path(ReportKind.service, id)}/$action'),
    ServiceReport.fromJson,
  );

  /// `POST /incident-reports` — reported.
  Future<IncidentReport> createIncident(IncidentDraft draft) async => _one(
    await _apiClient.postJson(
      ReportKind.incident.basePath,
      body: draft.toJson(),
    ),
    IncidentReport.fromJson,
  );

  /// `PATCH /incident-reports/{id}`.
  Future<IncidentReport> updateIncident(String id, IncidentDraft draft) async =>
      _one(
        await _apiClient.patchJson(
          _path(ReportKind.incident, id),
          draft.toJson(),
        ),
        IncidentReport.fromJson,
      );

  /// `DELETE /{kind}/{id}`.
  Future<void> delete(ReportKind kind, String id) =>
      _apiClient.delete(_path(kind, id));

  /// `POST /{kind}/{id}/attachments` with one photo.
  Future<ReportAttachment> upload(
    ReportKind kind,
    String id, {
    required Uint8List bytes,
    required String filename,
  }) async => _one(
    await _apiClient.postMultipart(
      '${_path(kind, id)}/attachments',
      field: 'file',
      bytes: bytes,
      filename: filename,
    ),
    ReportAttachment.fromJson,
  );

  /// `GET …/attachments/{a}/download` — into memory only.
  Future<Uint8List> download(
    ReportKind kind,
    String id,
    String attachmentId,
  ) => _apiClient.getBytes(
    '${_path(kind, id)}/attachments/${Uri.encodeComponent(attachmentId)}/download',
  );

  /// `DELETE …/attachments/{a}`.
  Future<void> deleteAttachment(
    ReportKind kind,
    String id,
    String attachmentId,
  ) => _apiClient.delete(
    '${_path(kind, id)}/attachments/${Uri.encodeComponent(attachmentId)}',
  );

  /// My own Staff record's public id from `GET /me/profile`, or null.
  Future<String?> fetchMyStaffPublicId() async {
    final body = await _apiClient.getJson('/me/profile');

    return _parse(() {
      final staff = (body['data'] as Map<String, dynamic>)['staff'];

      return staff == null
          ? null
          : (staff as Map<String, dynamic>)['public_id'] as String;
    });
  }

  Future<ReportListPage<T>> _page<T>(
    ReportKind kind,
    int page,
    T Function(Map<String, dynamic>) item,
  ) async {
    final body = await _apiClient.getJson(
      Uri(
        path: kind.basePath,
        queryParameters: {'per_page': '$pageSize', 'page': '$page'},
      ).toString(),
    );

    return _parse(() => ReportListPage.fromJson(body, item));
  }

  String _path(ReportKind kind, String id) =>
      '${kind.basePath}/${Uri.encodeComponent(id)}';

  T _one<T>(Map<String, dynamic>? body, T Function(Map<String, dynamic>) f) =>
      _parse(() {
        final data = body?['data'];
        if (data is! Map<String, dynamic>) {
          throw const FormatException('Missing data object');
        }

        return f(data);
      });

  T _parse<T>(T Function() parse) {
    try {
      return parse();
    } on FormatException {
      throw const ApiRequestException(
        'Something went wrong. Please try again.',
      );
    } on TypeError {
      throw const ApiRequestException(
        'Something went wrong. Please try again.',
      );
    }
  }
}
