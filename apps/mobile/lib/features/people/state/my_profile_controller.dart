import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/people/data/people_api_client.dart';
import 'package:mobile/features/people/domain/my_profile.dart';
import 'package:mobile/features/people/state/resource_controller.dart';

/// State for the My profile screen (Phase 28, spec §7–§8). A profile with
/// no linked Staff record is a valid loaded state ([MyProfile.staff] null),
/// not an error. Owned by its page for the page's lifetime (DEC-025).
class MyProfileController extends ResourceController<MyProfile> {
  MyProfileController(this._client);

  final PeopleApiClient _client;

  @override
  Future<MyProfile> fetch() => _client.fetchMyProfile();

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load your profile. Check your connection.",
    ApiForbiddenException(:final message) => message,
    _ => 'Something went wrong loading your profile.',
  };
}
