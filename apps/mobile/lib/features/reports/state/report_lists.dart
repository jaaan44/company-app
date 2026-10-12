import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/reports/data/reports_api_client.dart';
import 'package:mobile/features/reports/domain/report.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

/// The two report lists (Phase 33, R-1): the reports I can see, newest
/// first, 20 a page, never searched; with the company day and my Staff id
/// (learnt once). No profile or a `403` is the no-profile state (R-10).
abstract class _ReportList<T> extends PagedSearchController<T> {
  _ReportList(this.client);

  final ReportsApiClient client;
  ScheduleCompanyDay? _companyDay;
  String? _myStaffId;

  ScheduleCompanyDay? get companyDay => _companyDay;
  String? get myStaffId => _myStaffId;

  Future<ReportsPage<T>> fetch(int page);

  @override
  Future<PagedResult<T>?> fetchPage({
    required String query,
    required int page,
  }) async {
    try {
      _myStaffId ??= await client.fetchMyStaffPublicId();
      if (_myStaffId == null) {
        return null;
      }
      final result = await fetch(page);
      _companyDay = result.companyDay;

      return result.page;
    } on ApiForbiddenException {
      return null;
    }
  }

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() => "Couldn't load reports. Check your connection.",
    _ => 'Something went wrong loading reports.',
  };
}

class ServiceReportsController extends _ReportList<ServiceReport> {
  ServiceReportsController(super.client);

  @override
  Future<ReportsPage<ServiceReport>> fetch(int page) =>
      client.fetchServiceReports(page: page);

  @override
  String idOf(ServiceReport item) => item.publicId;
}

class IncidentsController extends _ReportList<IncidentReport> {
  IncidentsController(super.client);

  @override
  Future<ReportsPage<IncidentReport>> fetch(int page) =>
      client.fetchIncidents(page: page);

  @override
  String idOf(IncidentReport item) => item.publicId;
}
