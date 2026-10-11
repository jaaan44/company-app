import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/app/app.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/clients/presentation/client_detail_page.dart';
import 'package:mobile/features/clients/presentation/clients_page.dart';
import 'package:mobile/features/people/presentation/staff_detail_page.dart';
import 'package:mobile/features/projects/presentation/project_detail_page.dart';
import 'package:mobile/features/projects/presentation/projects_page.dart';
import 'package:mobile/features/tasks/presentation/task_detail_page.dart';

import '../../support/fake_backend.dart';
import '../../support/home_fixtures.dart';
import '../../support/people_fixtures.dart';
import '../../support/project_fixtures.dart';
import '../../support/task_fixtures.dart';

/// Phase 29C Gate 3 — the Projects and Clients screens through the real app
/// (`CompanyApp`, router, shell, `ApiClient`) against an in-memory API
/// (docs/phases/V1_PHASE_29_DEFINITION.md §7.5, R-20…R-27).
void main() {
  const lead = '01J0PROJLEAD00000000000AAA';
  const done = '01J0PROJDONE00000000000AAA';
  const secret = '01J0PROJSECRET000000000AAA';
  const taskProject = '01PROJ00000000000000000001';
  const acme = '01J0CLNT000000000000000AAA';
  const dormant = '01J0CLNTDORMANT00000000AAA';

  late FakeBackend backend;
  late AuthController auth;
  late List<Uri> requests;

  /// Per-test overrides.
  late Map<String, dynamic>? profileStaff;
  late List<Map<String, dynamic>> myProjects;
  late int membersTotal;

  Map<String, dynamic> project(String id) => switch (id) {
    lead => projectJson(publicId: lead, myRole: 'project_lead'),
    done => projectJson(
      publicId: done,
      name: 'Archive Move',
      projectCode: 'PRJ-009',
      status: 'completed',
      completedDate: '2026-08-30',
      description: null,
      client: {'public_id': dormant, 'name': 'Dormant Ltd'},
    ),
    _ => projectJson(publicId: id, name: 'Fit-out', projectCode: null),
  };

  Future<http.Response> route(http.Request request) async {
    final url = request.url;
    requests.add(url);
    final path = url.path;
    final segments = url.pathSegments;
    final last = segments.last;

    if (path.endsWith('/me/home')) {
      return homeResponse();
    }
    if (path.endsWith('/me/profile')) {
      return jsonResponse({'data': profileDataJson(staff: profileStaff)});
    }
    if (path.endsWith('/me/tasks')) {
      return jsonResponse(
        myTasksBody(
          url.queryParameters['state'] == 'closed'
              ? []
              : [
                  myTaskJson(
                    'T1',
                    title: 'Fix the boiler',
                    dueDate: '2026-10-20',
                  ),
                ],
          date: '2026-10-11',
        ),
      );
    }
    if (segments.contains('tasks')) {
      return jsonResponse({
        'data': taskJson(
          publicId: last,
          project: last == 'T2'
              ? null
              : const {'public_id': taskProject, 'name': 'Fit-out'},
        ),
      });
    }
    if (path.endsWith('/projects')) {
      final q = (url.queryParameters['q'] ?? '').toLowerCase();
      final page = int.parse(url.queryParameters['page'] ?? '1');
      final matching = myProjects
          .where((p) => (p['name'] as String).toLowerCase().contains(q))
          .toList();
      final pages = (matching.length / 25).ceil().clamp(1, 99);

      return jsonResponse(
        pageJson(
          matching.skip((page - 1) * 25).take(25).toList(),
          page: page,
          lastPage: pages,
          total: matching.length,
        ),
      );
    }
    if (segments.contains('projects')) {
      final id = segments[segments.indexOf('projects') + 1];
      if (id == secret) {
        return jsonError(403, 'You do not have access to view this project.');
      }
      if (last == 'members') {
        return jsonResponse(
          pageJson(
            [
              memberJson(publicId: 'S-ANA', displayName: 'Ana Reyes'),
              memberJson(
                publicId: 'S-BEN',
                displayName: 'Ben Cruz',
                role: 'project_lead',
              ),
            ],
            perPage: 50,
            total: membersTotal,
          ),
        );
      }
      if (last == 'milestones') {
        return jsonResponse(
          pageJson([
            milestoneJson(publicId: 'M1', title: 'Design sign-off'),
            milestoneJson(
              publicId: 'M2',
              title: 'Handover',
              dueDate: '2026-12-01',
              status: 'completed',
            ),
          ], perPage: 50),
        );
      }

      return jsonResponse({'data': project(id)});
    }
    if (path.endsWith('/clients')) {
      final q = (url.queryParameters['q'] ?? '').toLowerCase();

      return jsonResponse(
        pageJson(
          [
                clientJson(publicId: acme),
                clientJson(
                  publicId: '01J0CLNTBETA0000000000AAAA',
                  name: 'Beta Works',
                  clientCode: null,
                ),
              ]
              .where((c) => (c['name'] as String).toLowerCase().contains(q))
              .toList(),
        ),
      );
    }
    if (segments.contains('clients')) {
      return jsonResponse({
        'data': last == dormant
            ? clientJson(
                publicId: dormant,
                name: 'Dormant Ltd',
                status: 'inactive',
                email: null,
                website: null,
                addressLine1: null,
                city: null,
                stateProvince: null,
                postalCode: null,
                country: null,
              )
            : clientJson(publicId: last),
      });
    }
    if (path.endsWith('/contacts')) {
      final id = url.queryParameters['client'];

      return jsonResponse(
        pageJson(
          id == dormant
              ? []
              : [
                  contactJson(publicId: 'K1', isPrimary: true),
                  contactJson(
                    publicId: 'K2',
                    firstName: 'Leo',
                    lastName: 'Tan',
                    jobTitle: null,
                    phone: null,
                  ),
                ],
          perPage: 50,
          total: id == dormant ? 0 : 57,
        ),
      );
    }
    if (segments.contains('staff')) {
      return jsonResponse({
        'data': staffMemberJson(
          publicId: last,
          firstName: last == 'S-BEN' ? 'Ben' : 'Ana',
          lastName: last == 'S-BEN' ? 'Cruz' : 'Reyes',
        ),
      });
    }

    return jsonError(404, 'Not found.');
  }

  setUp(() {
    requests = [];
    profileStaff = staffMemberJson();
    membersTotal = 2;
    myProjects = [project(lead), project(done)];
  });

  setUpAll(() => WidgetController.hitTestWarningShouldBeFatal = true);
  tearDownAll(() => WidgetController.hitTestWarningShouldBeFatal = false);

  Future<void> pumpApp(WidgetTester tester, {bool tall = true}) async {
    if (tall) {
      tester.view.physicalSize =
          const Size(800, 2400) * tester.view.devicePixelRatio;
      addTearDown(tester.view.resetPhysicalSize);
    }
    backend = FakeBackend()..onApi = route;
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));

    await tester.pumpWidget(
      CompanyApp(
        authController: auth,
        homeApiClient: homeClientFor(backend, auth),
        peopleApiClient: peopleClientFor(backend, auth),
        tasksApiClient: tasksClientFor(backend, auth),
        projectsApiClient: projectsClientFor(backend, auth),
        clientsApiClient: clientsClientFor(backend, auth),
      ),
    );
    await tester.pumpAndSettle();
  }

  /// More's rows are built lazily; at large text the last ones start
  /// off-screen.
  Future<void> tapMoreRow(WidgetTester tester, String key) async {
    await tester.scrollUntilVisible(
      find.byKey(Key(key)),
      100,
      scrollable: find.byType(Scrollable).last,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(Key(key)));
    await tester.pumpAndSettle();
  }

  Future<void> openMoreRow(WidgetTester tester, String key) async {
    await tester.tap(find.widgetWithText(NavigationDestination, 'More'));
    await tester.pumpAndSettle();
    await tapMoreRow(tester, key);
  }

  Future<void> openProject(WidgetTester tester, String id) async {
    await openMoreRow(tester, 'more-projects');
    await tester.tap(find.byKey(Key('projects-row-$id')));
    await tester.pumpAndSettle();
  }

  Finder pageScrollable(Type page) => find
      .descendant(of: find.byType(page), matching: find.byType(Scrollable))
      .first;

  List<Uri> calls(String suffix) =>
      requests.where((u) => u.path.endsWith(suffix)).toList();

  group('Projects list (R-20/R-21)', () {
    testWidgets('lists my member projects with code, client, status and '
        '"Project lead"', (tester) async {
      await pumpApp(tester);
      await openMoreRow(tester, 'more-projects');

      expect(find.byType(ProjectsPage), findsOneWidget);
      expect(
        calls('/projects').single.queryParameters['member'],
        '01J0STAFF000000000000000AA',
      );
      expect(find.text('Boiler Upgrade'), findsOneWidget);
      expect(find.text('PRJ-001 · Acme Corp'), findsOneWidget);
      expect(find.byKey(const Key('project-status-active')), findsOneWidget);
      expect(find.byKey(const Key('projects-lead-$lead')), findsOneWidget);
      expect(find.text('Archive Move'), findsOneWidget);
      expect(find.byKey(const Key('project-status-completed')), findsOneWidget);
      expect(find.byKey(const Key('projects-lead-$done')), findsNothing);
    });

    testWidgets('without a staff profile: the no-profile message and no '
        'search, and no project request', (tester) async {
      profileStaff = null;
      await pumpApp(tester);
      await openMoreRow(tester, 'more-projects');

      expect(find.byKey(const Key('projects-no-profile')), findsOneWidget);
      expect(
        find.text('No staff profile is linked to this account.'),
        findsOneWidget,
      );
      expect(find.byKey(const Key('projects-search')), findsNothing);
      expect(calls('/projects'), isEmpty);
    });

    testWidgets('search narrows the list and says when nothing matches', (
      tester,
    ) async {
      await pumpApp(tester);
      await openMoreRow(tester, 'more-projects');

      await tester.enterText(find.byKey(const Key('projects-search')), 'arch');
      await tester.pump(const Duration(milliseconds: 400));
      await tester.pumpAndSettle();
      expect(calls('/projects').last.queryParameters['q'], 'arch');
      expect(find.text('Archive Move'), findsOneWidget);
      expect(find.text('Boiler Upgrade'), findsNothing);

      await tester.enterText(find.byKey(const Key('projects-search')), 'zzz');
      await tester.pump(const Duration(milliseconds: 400));
      await tester.pumpAndSettle();
      expect(find.text('No project matches "zzz".'), findsOneWidget);
    });

    testWidgets('no projects: "You aren\'t a member of any projects yet."', (
      tester,
    ) async {
      myProjects = [];
      await pumpApp(tester);
      await openMoreRow(tester, 'more-projects');

      expect(
        find.text("You aren't a member of any projects yet."),
        findsOneWidget,
      );
    });

    testWidgets('scrolling to the end loads the next page, once', (
      tester,
    ) async {
      myProjects = projectList(30);
      await pumpApp(tester);
      await openMoreRow(tester, 'more-projects');

      await tester.scrollUntilVisible(
        find.byKey(Key('projects-row-${myProjects.last['public_id']}')),
        400,
        scrollable: find
            .descendant(
              of: find.byKey(const Key('projects-list')),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      await tester.pumpAndSettle();

      expect(calls('/projects').map((u) => u.queryParameters['page']), [
        '1',
        '2',
      ]);
      expect(find.byKey(const Key('projects-loading-more')), findsNothing);
    });
  });

  group('Project detail (R-22…R-25)', () {
    testWidgets('shows the project, members (leads first) and milestones; '
        'no notes', (tester) async {
      await pumpApp(tester);
      await openProject(tester, lead);

      expect(find.byType(ProjectDetailPage), findsOneWidget);
      final header = find.byKey(const Key('project-header'));
      expect(
        find.descendant(of: header, matching: find.text('Boiler Upgrade')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: header, matching: find.text('PRJ-001')),
        findsOneWidget,
      );
      expect(find.text('Acme Corp'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const Key('project-my-role')),
          matching: find.text('Project lead'),
        ),
        findsOneWidget,
      );
      expect(find.text('1 Sep 2026'), findsOneWidget);
      expect(find.text('15 Dec 2026'), findsOneWidget);
      expect(find.byKey(const Key('project-completed-date')), findsNothing);
      expect(find.text('Replace the plant-room boiler.'), findsOneWidget);
      expect(find.text('Members (2)'), findsOneWidget);

      final ben = tester.getTopLeft(
        find.byKey(const Key('project-member-S-BEN')),
      );
      final ana = tester.getTopLeft(
        find.byKey(const Key('project-member-S-ANA')),
      );
      expect(ben.dy, lessThan(ana.dy), reason: 'leads first');
      expect(find.byKey(const Key('project-members-more')), findsOneWidget);
      expect(find.text('Showing 2 of 2'), findsNothing);

      expect(find.text('Design sign-off'), findsOneWidget);
      expect(find.text('20 Oct 2026'), findsOneWidget);
      expect(find.byKey(const Key('milestone-status-pending')), findsOneWidget);
      expect(
        find.byKey(const Key('milestone-status-completed')),
        findsOneWidget,
      );
      expect(find.textContaining('verdue'), findsNothing);
      expect(find.textContaining('Internal:'), findsNothing);
    });

    testWidgets('a completed project shows its completed date; a long member '
        'list says "Showing 2 of 53"', (tester) async {
      membersTotal = 53;
      await pumpApp(tester);
      await openProject(tester, done);

      expect(find.byKey(const Key('project-completed-date')), findsOneWidget);
      expect(find.text('30 Aug 2026'), findsOneWidget);
      expect(find.byKey(const Key('project-description')), findsNothing);
      expect(find.text('Members (53)'), findsOneWidget);
      expect(find.text('Showing 2 of 53'), findsOneWidget);
    });

    testWidgets('a member opens their Staff directory entry; the client opens '
        'the client', (tester) async {
      await pumpApp(tester);
      await openProject(tester, lead);

      await tester.tap(find.byKey(const Key('project-member-S-BEN')));
      await tester.pumpAndSettle();
      expect(find.byType(StaffDetailPage), findsOneWidget);
      expect(find.text('Ben Cruz'), findsOneWidget);

      await tester.pageBack();
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('project-client')));
      await tester.pumpAndSettle();
      expect(find.byType(ClientDetailPage), findsOneWidget);
      expect(calls('/clients/$acme'), hasLength(1));
    });
  });

  group('Task → project (R-25)', () {
    Future<void> openTask(WidgetTester tester, String id) async {
      await tester.tap(find.widgetWithText(NavigationDestination, 'Tasks'));
      await tester.pumpAndSettle();
      GoRouter.of(tester.element(find.byType(Scaffold).last))
          .push('/tasks/$id');
      await tester.pumpAndSettle();
    }

    testWidgets('a task\'s project opens in the More tab; the Tasks tab keeps '
        'the task', (tester) async {
      await pumpApp(tester);
      await openTask(tester, 'T1');
      expect(find.byType(TaskDetailPage), findsOneWidget);

      await tester.tap(find.byKey(const Key('task-project')));
      await tester.pumpAndSettle();

      expect(find.byType(ProjectDetailPage), findsOneWidget);
      expect(find.text('Fit-out'), findsWidgets);
      expect(calls('/projects/$taskProject'), hasLength(1));

      // Back within the More tab goes to the Projects list.
      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.byType(ProjectsPage), findsOneWidget);

      // The Tasks tab still shows the task.
      await tester.tap(find.widgetWithText(NavigationDestination, 'Tasks'));
      await tester.pumpAndSettle();
      expect(find.byType(TaskDetailPage), findsOneWidget);
    });

    testWidgets('a project I can\'t see says so, with "Try again"; the '
        'session stays', (tester) async {
      await pumpApp(tester);
      GoRouter.of(tester.element(find.byType(Scaffold).last))
          .go('/more/projects/$secret');
      await tester.pumpAndSettle();

      expect(
        find.text("You don't have access to this project."),
        findsOneWidget,
      );
      expect(find.text('Try again'), findsOneWidget);
      expect(auth.status, AuthStatus.authenticated);
    });

    testWidgets('an independent task\'s project row is not a link', (
      tester,
    ) async {
      await pumpApp(tester);
      await openTask(tester, 'T2');

      final row = tester.widget<ListTile>(
        find.descendant(
          of: find.byKey(const Key('task-project')),
          matching: find.byType(ListTile),
        ),
      );
      expect(row.onTap, isNull);
      expect(find.text('Independent task'), findsOneWidget);
    });
  });

  group('Clients (R-26)', () {
    testWidgets('lists active clients with search; a row opens the client', (
      tester,
    ) async {
      await pumpApp(tester);
      await openMoreRow(tester, 'more-clients');

      expect(find.byType(ClientsPage), findsOneWidget);
      expect(calls('/clients').single.queryParameters['status'], 'active');
      expect(find.text('Acme Corp'), findsOneWidget);
      expect(find.text('CL-001'), findsOneWidget);
      expect(find.text('Beta Works'), findsOneWidget);

      await tester.enterText(find.byKey(const Key('clients-search')), 'zzz');
      await tester.pump(const Duration(milliseconds: 400));
      await tester.pumpAndSettle();
      expect(find.text('No client matches "zzz".'), findsOneWidget);

      await tester.tap(find.byTooltip('Clear search'));
      await tester.pump(const Duration(milliseconds: 400));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('clients-row-$acme')));
      await tester.pumpAndSettle();
      expect(find.byType(ClientDetailPage), findsOneWidget);
    });

    testWidgets('a client shows contact details, address and active contacts '
        'with "Primary"; no notes', (tester) async {
      await pumpApp(tester);
      await openMoreRow(tester, 'more-clients');
      await tester.tap(find.byKey(const Key('clients-row-$acme')));
      await tester.pumpAndSettle();

      expect(find.text('office@acme.test'), findsOneWidget);
      expect(find.text('+63 2 8000 1234'), findsOneWidget);
      expect(find.text('https://acme.test'), findsOneWidget);
      expect(
        find.text('12 Harbor Road\nMakati, Metro Manila 1200\nPhilippines'),
        findsOneWidget,
      );
      expect(find.byKey(const Key('client-inactive')), findsNothing);
      expect(find.text('Contacts (57)'), findsOneWidget);
      expect(find.text('Maria Santos'), findsOneWidget);
      expect(find.text('Facilities Manager'), findsOneWidget);
      expect(find.byKey(const Key('client-contact-primary')), findsOneWidget);
      expect(find.text('Leo Tan'), findsOneWidget);
      expect(find.text('Showing 2 of 57'), findsOneWidget);
      expect(find.textContaining('Internal:'), findsNothing);
      expect(calls('/contacts').single.queryParameters, {
        'client': acme,
        'status': 'active',
        'per_page': '50',
        'page': '1',
      });
    });

    testWidgets('Copy puts the value on the clipboard', (tester) async {
      final copied = <String>[];
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        (call) async {
          if (call.method == 'Clipboard.setData') {
            copied.add((call.arguments as Map)['text'] as String);
          }

          return null;
        },
      );
      addTearDown(
        () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
          SystemChannels.platform,
          null,
        ),
      );
      await pumpApp(tester);
      await openMoreRow(tester, 'more-clients');
      await tester.tap(find.byKey(const Key('clients-row-$acme')));
      await tester.pumpAndSettle();

      await tester.tap(
        find.descendant(
          of: find.byKey(const Key('client-website')),
          matching: find.byTooltip('Copy website'),
        ),
      );
      await tester.pump();
      await tester.tap(
        find.descendant(
          of: find.byKey(const Key('client-contact-K1')),
          matching: find.byTooltip('Copy email'),
        ),
      );
      await tester.pump();

      expect(copied, ['https://acme.test', 'maria@acme.test']);
      expect(find.text('Copied'), findsOneWidget);
    });

    testWidgets('an inactive client opened from a project says "Inactive"; '
        'missing values read "Not set"', (tester) async {
      await pumpApp(tester);
      await openProject(tester, done);
      await tester.tap(find.byKey(const Key('project-client')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('client-inactive')), findsOneWidget);
      expect(find.text('Inactive'), findsOneWidget);
      expect(find.text('Not set'), findsNWidgets(3));
      expect(find.byTooltip('Copy email'), findsNothing);
      expect(find.byKey(const Key('client-no-contacts')), findsOneWidget);
    });
  });

  group('Dark mode and large text (UAT-29C-08)', () {
    for (final (label, brightness, scale) in [
      ('light, 200% text', Brightness.light, 2.0),
      ('dark, 200% text', Brightness.dark, 2.0),
    ]) {
      testWidgets('$label: the four screens fit on a small phone', (
        tester,
      ) async {
        tester.view.physicalSize =
            const Size(360, 640) * tester.view.devicePixelRatio;
        addTearDown(tester.view.resetPhysicalSize);
        tester.platformDispatcher.platformBrightnessTestValue = brightness;
        addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
        tester.platformDispatcher.textScaleFactorTestValue = scale;
        addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
        membersTotal = 53;
        await pumpApp(tester, tall: false);

        await openMoreRow(tester, 'more-projects');
        expect(tester.takeException(), isNull, reason: 'projects');
        expect(
          Theme.of(tester.element(find.byType(ProjectsPage))).brightness,
          brightness,
        );

        await tester.tap(find.byKey(const Key('projects-row-$lead')));
        await tester.pumpAndSettle();
        await tester.scrollUntilVisible(
          find.byKey(const Key('project-milestone-M2')),
          200,
          scrollable: pageScrollable(ProjectDetailPage),
        );
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull, reason: 'project detail');

        await tester.pageBack();
        await tester.pumpAndSettle();
        await tester.pageBack();
        await tester.pumpAndSettle();
        await tapMoreRow(tester, 'more-clients');
        expect(tester.takeException(), isNull, reason: 'clients');

        await tester.tap(find.byKey(const Key('clients-row-$acme')));
        await tester.pumpAndSettle();
        await tester.scrollUntilVisible(
          find.byKey(const Key('client-contacts-more')),
          200,
          scrollable: pageScrollable(ClientDetailPage),
        );
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull, reason: 'client detail');
      });
    }
  });
}
