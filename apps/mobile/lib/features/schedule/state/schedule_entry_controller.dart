import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/people/state/resource_controller.dart';
import 'package:mobile/features/schedule/data/schedule_api_client.dart';
import 'package:mobile/features/schedule/domain/schedule.dart';

/// State for one schedule entry's read-only detail (Phase 30A, R-10).
/// Someone else's private entry, or one that's gone, is a `404`:
/// "This item is no longer available."
///
/// Owned by its page for the page's lifetime (DEC-025).
class ScheduleEntryController extends ResourceController<ScheduleEntryDetail> {
  ScheduleEntryController(this._client, this.publicId);

  final ScheduleApiClient _client;
  final String publicId;

  @override
  Future<ScheduleEntryDetail> fetch() => _client.fetchEntry(publicId);

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() => "Couldn't load this item. Check your connection.",
    ApiRequestException(statusCode: 404) => 'This item is no longer available.',
    _ => 'Something went wrong loading this item.',
  };
}
