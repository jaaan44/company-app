import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/my_status/data/my_status_api_client.dart';
import 'package:mobile/features/my_status/domain/my_status.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

/// The "All status changes" and "All check-ins" lists (Phase 30B, R-13):
/// the Phase 28/29 paging, refresh and stale-response rules
/// ([PagedSearchController], never searched), 20 a page, plus the company
/// day of the latest page for showing times. A `403` that reaches them
/// (after the session re-check) is the no-profile state (R-15).
mixin _CompanyDayOf<T> on PagedSearchController<T> {
  ScheduleCompanyDay? _companyDay;

  /// From the latest page; null until one has loaded.
  ScheduleCompanyDay? get companyDay => _companyDay;

  Future<PagedResult<T>?> _page(
    Future<MyHistoryPage<T>> Function() fetch,
  ) async {
    try {
      final page = await fetch();
      _companyDay = page.companyDay;

      return page.page;
    } on ApiForbiddenException {
      return null;
    }
  }
}

class StatusHistoryController extends PagedSearchController<StatusEntry>
    with _CompanyDayOf<StatusEntry> {
  StatusHistoryController(this._client);

  final MyStatusApiClient _client;

  @override
  Future<PagedResult<StatusEntry>?> fetchPage({
    required String query,
    required int page,
  }) => _page(() => _client.fetchStatusHistory(page: page));

  /// Status rows have no id (DEC-017). With the server's unique order
  /// (R-14) the time and status identify a row; two identical changes in
  /// the same second would show once.
  @override
  String idOf(StatusEntry item) =>
      '${item.recordedAt.toIso8601String()}|${item.status.wireValue}';

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load your status changes. Check your connection.",
    _ => 'Something went wrong loading your status changes.',
  };
}

class CheckInsController extends PagedSearchController<CheckIn>
    with _CompanyDayOf<CheckIn> {
  CheckInsController(this._client);

  final MyStatusApiClient _client;

  @override
  Future<PagedResult<CheckIn>?> fetchPage({
    required String query,
    required int page,
  }) => _page(() => _client.fetchCheckIns(page: page));

  @override
  String idOf(CheckIn item) => item.publicId;

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load your check-ins. Check your connection.",
    _ => 'Something went wrong loading your check-ins.',
  };
}
