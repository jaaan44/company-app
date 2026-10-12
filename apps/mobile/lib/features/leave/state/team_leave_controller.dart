import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/leave/data/leave_api_client.dart';
import 'package:mobile/features/leave/domain/leave.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

/// Team requests (Phase 31, R-3/R-7): my direct reports' requests, pending
/// soonest first by default, with a filter. The Phase 28–30 paging,
/// refresh and stale-response rules ([PagedSearchController], never
/// searched); changing [filter] reloads from the first page.
class TeamLeaveController extends PagedSearchController<LeaveRequest> {
  TeamLeaveController(this._client);

  final LeaveApiClient _client;

  TeamLeaveFilter _filter = TeamLeaveFilter.pending;
  int? _pendingCount;
  ScheduleCompanyDay? _companyDay;

  TeamLeaveFilter get filter => _filter;

  /// Pending requests of my reports (whatever the filter), from the latest
  /// page; null until one has loaded.
  int? get pendingCount => _pendingCount;
  ScheduleCompanyDay? get companyDay => _companyDay;

  Future<void> setFilter(TeamLeaveFilter value) async {
    if (value == _filter) {
      return;
    }
    _filter = value;
    await restart();
  }

  @override
  Future<PagedResult<LeaveRequest>?> fetchPage({
    required String query,
    required int page,
  }) async {
    try {
      final result = await _client.fetchTeamRequests(
        filter: _filter,
        page: page,
      );
      _pendingCount = result.pendingCount;
      _companyDay = result.companyDay;

      return result.page;
    } on ApiForbiddenException {
      return null;
    }
  }

  @override
  String idOf(LeaveRequest item) => item.publicId;

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load team requests. Check your connection.",
    _ => 'Something went wrong loading team requests.',
  };
}
