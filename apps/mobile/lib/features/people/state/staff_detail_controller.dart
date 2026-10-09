import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/people/data/people_api_client.dart';
import 'package:mobile/features/people/domain/staff_member.dart';
import 'package:mobile/features/people/state/resource_controller.dart';

/// State for one colleague's directory entry (Phase 28, spec §7–§8). Owned
/// by its page for the page's lifetime (DEC-025); a manager tap (R-7) opens
/// a new page with its own controller.
class StaffDetailController extends ResourceController<StaffMember> {
  StaffDetailController(this._client, this.publicId);

  final PeopleApiClient _client;
  final String publicId;

  @override
  Future<StaffMember> fetch() => _client.fetchStaff(publicId);

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load this person. Check your connection.",
    ApiForbiddenException() => "You don't have access to the staff directory.",
    ApiRequestException(statusCode: 404) =>
      'This person is no longer in the directory.',
    _ => 'Something went wrong loading this person.',
  };
}
