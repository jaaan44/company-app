import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/projects/data/projects_api_client.dart';
import 'package:mobile/features/projects/domain/project.dart';

/// State for More → Projects (Phase 29C, docs/phases/V1_PHASE_29_DEFINITION.md
/// §7.2/§7.4): the projects I'm a member of, for **every role** (R-20), in
/// name order with search and paging (R-21).
///
/// The list needs my Staff public id, read once from `/me/profile`. With no
/// linked Staff record the list is [PagedListStatus.noProfile]; that answer
/// isn't cached, so a later "Try again" checks again.
///
/// Owned by its page for the page's lifetime (DEC-025).
class MyProjectsController extends PagedSearchController<Project> {
  MyProjectsController(this._client, {super.debounce});

  final ProjectsApiClient _client;
  String? _staffPublicId;

  @override
  Future<PagedResult<Project>?> fetchPage({
    required String query,
    required int page,
  }) async {
    final staffPublicId = _staffPublicId ??= await _client
        .fetchMyStaffPublicId();
    if (staffPublicId == null) {
      return null;
    }

    return _client.fetchMyProjectsPage(staffPublicId, query: query, page: page);
  }

  @override
  String idOf(Project item) => item.publicId;

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load your projects. Check your connection.",
    ApiForbiddenException() => "You don't have access to projects.",
    _ => 'Something went wrong loading your projects.',
  };
}
