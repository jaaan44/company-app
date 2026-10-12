import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/messages/data/messages_api_client.dart';
import 'package:mobile/features/messages/domain/conversation.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

/// The Messages tab (Phase 32, R-3): my conversations, newest activity
/// first, 20 a page, never searched. Learns my own Staff id once (to name
/// direct chats and mark my own last message). A `403`, or no linked
/// Staff record, is the no-profile state (R-9).
class ConversationsController extends PagedSearchController<Conversation> {
  ConversationsController(this._client);

  final MessagesApiClient _client;
  ScheduleCompanyDay? _companyDay;
  String? _myStaffId;

  ScheduleCompanyDay? get companyDay => _companyDay;

  /// My Staff public id; null until the first page has loaded.
  String? get myStaffId => _myStaffId;

  @override
  Future<PagedResult<Conversation>?> fetchPage({
    required String query,
    required int page,
  }) async {
    try {
      _myStaffId ??= await _client.fetchMyStaffPublicId();
      if (_myStaffId == null) {
        return null;
      }
      final result = await _client.fetchConversations(page: page);
      _companyDay = result.companyDay;

      return result.page;
    } on ApiForbiddenException {
      return null;
    }
  }

  @override
  String idOf(Conversation item) => item.publicId;

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load your conversations. Check your connection.",
    _ => 'Something went wrong loading your conversations.',
  };
}
