import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/clients/data/clients_api_client.dart';
import 'package:mobile/features/projects/data/projects_api_client.dart';
import 'package:mobile/features/projects/domain/project.dart';

import '../../support/fake_backend.dart';
import '../../support/people_fixtures.dart';
import '../../support/project_fixtures.dart';

/// Phase 29C Gate 2 — [ProjectsApiClient] and [ClientsApiClient] over the
/// real Phase 27 `ApiClient` (spec §7.4): exact paths and query encoding,
/// member-only projects (R-20), active-only clients and contacts (R-26),
/// page sizes, contract failures and the unchanged 403 rule.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late ProjectsApiClient projects;
  late ClientsApiClient clients;
  late List<Uri> requests;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    requests = [];
    projects = projectsClientFor(backend, auth);
    clients = clientsClientFor(backend, auth);
  });

  void answer(http.Response Function(Uri url) respond) {
    backend.onApi = (request) async {
      requests.add(request.url);

      return respond(request.url);
    };
  }

  group('ProjectsApiClient', () {
    test('fetchMyStaffPublicId reads /me/profile; no staff → null', () async {
      answer((_) => jsonResponse({'data': profileDataJson()}));
      expect(
        await projects.fetchMyStaffPublicId(),
        '01J0STAFF000000000000000AA',
      );
      expect(requests.single.path, '/api/v1/me/profile');

      answer((_) => jsonResponse({'data': profileDataJson(staff: null)}));
      expect(await projects.fetchMyStaffPublicId(), isNull);
    });

    test('my projects always pass member=<me>, 25 per page; q only when '
        'non-blank (R-20/R-21)', () async {
      answer((_) => jsonResponse(pageJson(projectList(2))));

      final page = await projects.fetchMyProjectsPage('STAFF-1', query: '  ');
      await projects.fetchMyProjectsPage('STAFF-1', query: ' boiler ', page: 3);

      expect(requests[0].path, '/api/v1/projects');
      expect(requests[0].queryParameters, {
        'member': 'STAFF-1',
        'per_page': '25',
        'page': '1',
      });
      expect(requests[1].queryParameters, {
        'member': 'STAFF-1',
        'per_page': '25',
        'page': '3',
        'q': 'boiler',
      });
      expect(page.items.map((p) => p.name), ['P 0', 'P 1']);
    });

    test('project, members and milestones use the encoded public id and '
        'one page of 50 (R-22)', () async {
      answer((url) {
        if (url.path.endsWith('/members')) {
          return jsonResponse(
            pageJson([memberJson()], perPage: 50, lastPage: 2, total: 51),
          );
        }
        if (url.path.endsWith('/milestones')) {
          return jsonResponse(pageJson([milestoneJson()], perPage: 50));
        }

        return jsonResponse({'data': projectJson()});
      });

      final project = await projects.fetchProject('A/B');
      final members = await projects.fetchMembers('A/B');
      final milestones = await projects.fetchMilestones('A/B');

      expect(requests.map((u) => u.path), [
        '/api/v1/projects/A%2FB',
        '/api/v1/projects/A%2FB/members',
        '/api/v1/projects/A%2FB/milestones',
      ]);
      expect(requests[1].queryParameters, {'per_page': '50', 'page': '1'});
      expect(requests[2].queryParameters, {'per_page': '50', 'page': '1'});
      expect(project.status, ProjectStatus.active);
      expect(members.total, 51);
      expect(milestones.items.single.title, 'Design sign-off');
    });

    test('a 403 is an ApiForbiddenException with the server message and the '
        'session survives', () async {
      backend.meStatus = 200;
      answer(
        (_) => jsonError(403, 'You do not have access to view this project.'),
      );

      await expectLater(
        projects.fetchProject('X'),
        throwsA(
          isA<ApiForbiddenException>().having(
            (e) => e.message,
            'message',
            'You do not have access to view this project.',
          ),
        ),
      );
      expect(auth.status, AuthStatus.authenticated);
    });

    test('an unexpected shape is an ApiRequestException, not a crash', () {
      answer((_) => jsonResponse({'data': projectJson(status: 'archived')}));

      expect(projects.fetchProject('X'), throwsA(isA<ApiRequestException>()));
    });
  });

  group('ClientsApiClient', () {
    test('clients are active only, 25 per page; q only when non-blank '
        '(R-26)', () async {
      answer((_) => jsonResponse(pageJson(clientList(1))));

      await clients.fetchClientsPage();
      await clients.fetchClientsPage(query: ' acme ', page: 2);

      expect(requests[0].path, '/api/v1/clients');
      expect(requests[0].queryParameters, {
        'status': 'active',
        'per_page': '25',
        'page': '1',
      });
      expect(requests[1].queryParameters['q'], 'acme');
      expect(requests[1].queryParameters['page'], '2');
    });

    test('a client opens by id (any status); its contacts are active only, '
        'one page of 50', () async {
      answer(
        (url) => url.path.endsWith('/contacts')
            ? jsonResponse(
                pageJson([contactJson(isPrimary: true)], perPage: 50),
              )
            : jsonResponse({'data': clientJson(status: 'inactive')}),
      );

      final client = await clients.fetchClient('C/1');
      final contacts = await clients.fetchContacts('C/1');

      expect(requests[0].path, '/api/v1/clients/C%2F1');
      expect(requests[1].path, '/api/v1/contacts');
      expect(requests[1].queryParameters, {
        'client': 'C/1',
        'status': 'active',
        'per_page': '50',
        'page': '1',
      });
      expect(client.isInactive, isTrue);
      expect(contacts.items.single.isPrimary, isTrue);
    });

    test('a 404 is an ApiRequestException with status 404', () {
      answer((_) => jsonError(404, 'Not found.'));

      expect(
        clients.fetchClient('X'),
        throwsA(
          isA<ApiRequestException>().having((e) => e.statusCode, 'status', 404),
        ),
      );
    });
  });
}
