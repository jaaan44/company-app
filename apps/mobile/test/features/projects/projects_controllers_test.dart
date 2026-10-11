import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/clients/state/client_detail_controller.dart';
import 'package:mobile/features/clients/state/clients_controller.dart';
import 'package:mobile/features/people/state/resource_controller.dart';
import 'package:mobile/features/projects/state/my_projects_controller.dart';
import 'package:mobile/features/projects/state/project_detail_controller.dart';

import '../../support/fake_backend.dart';
import '../../support/people_fixtures.dart';
import '../../support/project_fixtures.dart';

/// Phase 29C Gate 2 — the Projects and Clients controllers over the real
/// `ApiClient` (spec §7.4): the generic paged search list (paging, no
/// duplicates, debounced search, stale responses dropped, refresh, errors),
/// the no-profile state (R-20), and the two detail controllers (leads first,
/// totals, 403/404 messages — R-22/R-25/R-26).
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late List<Uri> requests;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    requests = [];
  });

  void answer(Future<http.Response> Function(Uri url) respond) {
    backend.onApi = (request) {
      requests.add(request.url);

      return respond(request.url);
    };
  }

  Future<void> settle() =>
      Future<void>.delayed(const Duration(milliseconds: 60));

  List<Uri> projectCalls() =>
      requests.where((u) => u.path == '/api/v1/projects').toList();

  /// /me/profile plus a 3-page member-project list of 2 per page.
  Future<http.Response> threeProjectPages(Uri url) async {
    if (url.path.endsWith('/me/profile')) {
      return jsonResponse({'data': profileDataJson()});
    }
    final page = int.parse(url.queryParameters['page']!);

    return jsonResponse(
      pageJson(projectList(2, prefix: 'Pg$page'), page: page, lastPage: 3),
    );
  }

  group('MyProjectsController', () {
    late MyProjectsController list;

    setUp(() {
      list = MyProjectsController(
        projectsClientFor(backend, auth),
        debounce: const Duration(milliseconds: 20),
      );
    });

    tearDown(() => list.dispose());

    test('loads my member projects: profile once, then member=<me>', () async {
      answer(threeProjectPages);

      expect(list.status, PagedListStatus.loading);
      await list.load();

      expect(list.status, PagedListStatus.loaded);
      expect(list.items.map((p) => p.name), ['Pg1 0', 'Pg1 1']);
      expect(list.hasMore, isTrue);
      expect(
        projectCalls().single.queryParameters['member'],
        '01J0STAFF000000000000000AA',
      );

      await list.loadMore();
      await list.loadMore();
      await list.loadMore(); // no page 4

      expect(list.items, hasLength(6));
      expect(list.hasMore, isFalse);
      expect(projectCalls().map((u) => u.queryParameters['page']), [
        '1',
        '2',
        '3',
      ]);
      expect(
        requests.where((u) => u.path.endsWith('/me/profile')),
        hasLength(1),
      );
    });

    test('no linked Staff record → noProfile, without asking for projects; '
        '"Try again" checks the profile again', () async {
      answer(
        (url) async => jsonResponse({'data': profileDataJson(staff: null)}),
      );

      await list.load();
      expect(list.status, PagedListStatus.noProfile);
      expect(list.items, isEmpty);
      expect(projectCalls(), isEmpty);

      answer(threeProjectPages);
      await list.load();
      expect(list.status, PagedListStatus.loaded);
    });

    test('a repeated item across pages is shown once', () async {
      answer((url) async {
        if (url.path.endsWith('/me/profile')) {
          return jsonResponse({'data': profileDataJson()});
        }
        final page = int.parse(url.queryParameters['page']!);

        return jsonResponse(
          pageJson(
            page == 1 ? projectList(2) : projectList(3),
            page: page,
            lastPage: 2,
          ),
        );
      });

      await list.load();
      await list.loadMore();

      expect(list.items.map((p) => p.name), ['P 0', 'P 1', 'P 2']);
    });

    test(
      'search is debounced, trimmed, and starts again from page 1',
      () async {
        answer(threeProjectPages);
        await list.load();
        await list.loadMore();

        // Keystrokes 5 ms apart: inside the 20 ms debounce, so only the
        // last one may search.
        list.setQuery(' b');
        await Future<void>.delayed(const Duration(milliseconds: 5));
        list.setQuery(' bo');
        await Future<void>.delayed(const Duration(milliseconds: 5));
        list.setQuery(' boiler ');
        await settle();

        final searches = projectCalls().where(
          (u) => u.queryParameters.containsKey('q'),
        );
        expect(searches.map((u) => u.queryParameters['q']), ['boiler']);
        expect(searches.single.queryParameters['page'], '1');
        expect(list.query, 'boiler');
        expect(list.items, hasLength(2));
      },
    );

    test('a slow response for an older search is dropped', () async {
      final slow = Completer<http.Response>();
      answer((url) {
        if (url.path.endsWith('/me/profile')) {
          return Future.value(jsonResponse({'data': profileDataJson()}));
        }
        final q = url.queryParameters['q'];
        if (q == 'old') {
          return slow.future;
        }

        return Future.value(
          jsonResponse(pageJson(projectList(1, prefix: q ?? 'All'))),
        );
      });
      await list.load();

      list.setQuery('old');
      await settle();
      list.setQuery('new');
      await settle();
      slow.complete(jsonResponse(pageJson(projectList(1, prefix: 'old'))));
      await settle();

      expect(list.query, 'new');
      expect(list.items.single.name, 'new 0');
    });

    test('a failed refresh keeps the list; a failed first load is an error '
        'with a message', () async {
      answer(threeProjectPages);
      await list.load();

      answer((url) async => throw const NetworkFailure());
      expect(await list.refresh(), isFalse);
      expect(list.status, PagedListStatus.loaded);
      expect(list.items, hasLength(2));

      final fresh = MyProjectsController(projectsClientFor(backend, auth));
      addTearDown(fresh.dispose);
      await fresh.load();
      expect(fresh.status, PagedListStatus.error);
      expect(
        fresh.errorMessage,
        "Couldn't load your projects. Check your connection.",
      );
    });

    test('a failed "load more" keeps the list and can be retried', () async {
      answer(threeProjectPages);
      await list.load();

      answer((url) async => jsonError(500));
      await list.loadMore();
      expect(list.loadMoreFailed, isTrue);
      expect(list.items, hasLength(2));

      answer(threeProjectPages);
      await list.loadMore();
      expect(list.loadMoreFailed, isFalse);
      expect(list.items, hasLength(4));
    });
  });

  group('ClientsController', () {
    test('lists active clients without needing a profile', () async {
      final list = ClientsController(clientsClientFor(backend, auth));
      addTearDown(list.dispose);
      answer((url) async => jsonResponse(pageJson(clientList(2))));

      await list.load();

      expect(list.status, PagedListStatus.loaded);
      expect(list.items.map((c) => c.name), ['C 0', 'C 1']);
      expect(requests.single.path, '/api/v1/clients');
      expect(requests.single.queryParameters['status'], 'active');
    });

    test('a 403 is the "no access" error', () async {
      final list = ClientsController(clientsClientFor(backend, auth));
      addTearDown(list.dispose);
      answer((url) async => jsonError(403));

      await list.load();

      expect(list.status, PagedListStatus.error);
      expect(list.errorMessage, "You don't have access to clients.");
      expect(auth.status, AuthStatus.authenticated);
    });
  });

  group('ProjectDetailController', () {
    Future<http.Response> fullProject(Uri url) async {
      if (url.path.endsWith('/members')) {
        return jsonResponse(
          pageJson(
            [
              memberJson(publicId: 'S1', displayName: 'Ana', role: 'member'),
              memberJson(
                publicId: 'S2',
                displayName: 'Ben',
                role: 'project_lead',
              ),
              memberJson(publicId: 'S3', displayName: 'Cy', role: 'member'),
            ],
            perPage: 50,
            lastPage: 2,
            total: 53,
          ),
        );
      }
      if (url.path.endsWith('/milestones')) {
        return jsonResponse(
          pageJson([
            milestoneJson(),
            milestoneJson(publicId: 'M2'),
          ], perPage: 50),
        );
      }

      return jsonResponse({'data': projectJson()});
    }

    test('loads the project, members (leads first) and milestones together, '
        'with totals', () async {
      final detail = ProjectDetailController(
        projectsClientFor(backend, auth),
        'P1',
      );
      addTearDown(detail.dispose);
      answer(fullProject);

      await detail.load();

      expect(detail.status, ResourceStatus.loaded);
      final data = detail.data!;
      expect(data.project.name, 'Boiler Upgrade');
      expect(data.members.map((m) => m.displayName), ['Ben', 'Ana', 'Cy']);
      expect(data.membersTotal, 53);
      expect(data.milestones, hasLength(2));
      expect(data.milestonesTotal, 2);
      expect(requests, hasLength(3));
    });

    test('a 403 (not a member, R-25) is "You don\'t have access to this '
        'project." and keeps the session', () async {
      final detail = ProjectDetailController(
        projectsClientFor(backend, auth),
        'P1',
      );
      addTearDown(detail.dispose);
      answer(
        (url) async =>
            jsonError(403, 'You do not have access to view this project.'),
      );

      await detail.load();

      expect(detail.status, ResourceStatus.error);
      expect(detail.errorMessage, "You don't have access to this project.");
      expect(auth.status, AuthStatus.authenticated);
    });

    test('a 404 is "This project no longer exists."; one failing sub-list '
        'fails the screen', () async {
      final gone = ProjectDetailController(
        projectsClientFor(backend, auth),
        'P1',
      );
      addTearDown(gone.dispose);
      answer((url) async => jsonError(404, 'Not found.'));
      await gone.load();
      expect(gone.errorMessage, 'This project no longer exists.');

      final partial = ProjectDetailController(
        projectsClientFor(backend, auth),
        'P1',
      );
      addTearDown(partial.dispose);
      answer(
        (url) async => url.path.endsWith('/milestones')
            ? throw const NetworkFailure()
            : fullProject(url),
      );
      await partial.load();
      expect(partial.status, ResourceStatus.error);
      expect(
        partial.errorMessage,
        "Couldn't load this project. Check your connection.",
      );
    });
  });

  group('ClientDetailController', () {
    test('loads the client and its active contacts, with the total', () async {
      final detail = ClientDetailController(
        clientsClientFor(backend, auth),
        'C1',
      );
      addTearDown(detail.dispose);
      answer(
        (url) async => url.path.endsWith('/contacts')
            ? jsonResponse(
                pageJson([
                  contactJson(isPrimary: true),
                  contactJson(publicId: 'K2'),
                ]),
              )
            : jsonResponse({'data': clientJson(status: 'inactive')}),
      );

      await detail.load();

      expect(detail.status, ResourceStatus.loaded);
      expect(detail.data!.client.isInactive, isTrue);
      expect(detail.data!.contacts.first.isPrimary, isTrue);
      expect(detail.data!.contactsTotal, 2);
    });

    test('a 404 is "This client no longer exists."', () async {
      final detail = ClientDetailController(
        clientsClientFor(backend, auth),
        'C1',
      );
      addTearDown(detail.dispose);
      answer((url) async => jsonError(404, 'Not found.'));

      await detail.load();

      expect(detail.errorMessage, 'This client no longer exists.');
    });
  });
}
