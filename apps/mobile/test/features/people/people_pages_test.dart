import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/app/app.dart';
import 'package:mobile/features/auth/presentation/login_page.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/people/presentation/more_page.dart';
import 'package:mobile/features/people/presentation/my_profile_page.dart';
import 'package:mobile/features/people/presentation/staff_detail_page.dart';
import 'package:mobile/features/people/presentation/staff_directory_page.dart';

import '../../support/fake_backend.dart';
import '../../support/home_fixtures.dart';
import '../../support/people_fixtures.dart';

/// Phase 28 Gate 3 — the People screens through the real app (`CompanyApp`,
/// router, shell, `AuthController`, `ApiClient`) against a scripted API
/// (docs/phases/V1_PHASE_28_DEFINITION.md §7–§8).
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late List<Uri> requests;

  /// Per-test overrides; anything not overridden gets a sensible default.
  late FutureOr<http.Response> Function(Uri url)? onProfile;
  late FutureOr<http.Response> Function(Uri url)? onDirectory;
  late FutureOr<http.Response> Function(Uri url, String publicId)? onStaff;

  Future<http.Response> route(http.Request request) async {
    final url = request.url;
    requests.add(url);
    final path = url.path;

    if (path.endsWith('/me/home')) {
      return homeResponse();
    }
    if (path.endsWith('/me/profile')) {
      return onProfile == null
          ? jsonResponse({'data': profileDataJson()})
          : await onProfile!(url);
    }
    if (path.endsWith('/staff')) {
      return onDirectory == null
          ? jsonResponse(directoryPageJson(staffList(3)))
          : await onDirectory!(url);
    }
    if (path.contains('/staff/')) {
      final id = url.pathSegments.last;

      return onStaff == null
          ? jsonResponse({'data': staffMemberJson(publicId: id)})
          : await onStaff!(url, id);
    }

    fail('unexpected request $url');
  }

  List<Uri> staffRequests() =>
      requests.where((u) => u.path.endsWith('/staff')).toList();

  Future<void> pumpApp(WidgetTester tester, {bool tall = true}) async {
    if (tall) {
      tester.view.physicalSize =
          const Size(800, 2400) * tester.view.devicePixelRatio;
      addTearDown(tester.view.resetPhysicalSize);
    }
    backend = FakeBackend();
    requests = [];
    backend.onApi = route;
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));

    await tester.pumpWidget(
      CompanyApp(
        authController: auth,
        homeApiClient: homeClientFor(backend, auth),
        peopleApiClient: peopleClientFor(backend, auth),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> openMore(WidgetTester tester) async {
    await tester.tap(find.widgetWithText(NavigationDestination, 'More'));
    await tester.pumpAndSettle();
  }

  Future<void> openProfile(WidgetTester tester) async {
    await openMore(tester);
    await tester.tap(find.byKey(const Key('more-profile')));
    await tester.pumpAndSettle();
  }

  Future<void> openDirectory(WidgetTester tester) async {
    await openMore(tester);
    await tester.tap(find.byKey(const Key('more-directory')));
    await tester.pumpAndSettle();
  }

  Finder pageScrollable(Type page) => find
      .descendant(of: find.byType(page), matching: find.byType(Scrollable))
      .last;

  setUp(() {
    onProfile = null;
    onDirectory = null;
    onStaff = null;
  });

  // A gesture that misses its target must fail the test, not just warn.
  setUpAll(() => WidgetController.hitTestWarningShouldBeFatal = true);
  tearDownAll(() => WidgetController.hitTestWarningShouldBeFatal = false);

  group('More (R-8)', () {
    testWidgets('lists exactly My profile, Staff directory, My work logs, '
        'Projects and Clients', (tester) async {
      await pumpApp(tester);
      await openMore(tester);

      expect(find.byType(MorePage), findsOneWidget);
      // Phase 29B adds My work logs and Phase 29C Projects and Clients
      // (each phase adds its own row, R-8 / R-27).
      expect(find.byType(ListTile), findsNWidgets(5));
      expect(find.text('My profile'), findsOneWidget);
      // The signed-in account's name, known without a request.
      expect(find.text('Ada Lovelace'), findsOneWidget);
      expect(find.text('Staff directory'), findsOneWidget);
      expect(find.text('My work logs'), findsOneWidget);
      expect(find.text('Projects'), findsOneWidget);
      expect(find.text('Clients'), findsOneWidget);
      expect(find.textContaining('coming soon'), findsNothing);
      expect(
        requests.where((u) => !u.path.endsWith('/me/home')),
        isEmpty,
        reason: 'More fetches nothing',
      );
    });
  });

  group('My profile', () {
    testWidgets('shows the own record, read-only', (tester) async {
      onProfile = (_) => jsonResponse({
        'data': profileDataJson(
          staff: staffMemberJson(preferredName: 'Ada L.'),
        ),
      });
      await pumpApp(tester);
      await openProfile(tester);

      expect(find.byType(MyProfilePage), findsOneWidget);
      for (final text in [
        'Ada L.', // display name
        'Ada Lovelace', // full name, since it differs
        'Field Technician · Operations',
        'Field Team A',
        'Grace Hopper',
        'ada@company.test',
        '+63 2 8123 4567',
        'EMP-1001',
        '1 Mar 2024',
        'ada@example.com', // login email
        'Staff', // role
        'To change these details, contact an administrator.',
      ]) {
        expect(find.text(text), findsWidgets, reason: text);
      }
      for (final key in [
        'profile-work',
        'profile-employment',
        'profile-account',
      ]) {
        expect(find.byKey(Key(key)), findsOneWidget);
      }
      // No edit control of any kind.
      expect(find.byType(TextField), findsNothing);
      expect(find.byIcon(Icons.edit), findsNothing);
      expect(find.byIcon(Icons.edit_outlined), findsNothing);
    });

    testWidgets('no linked staff record: a message and the Account section', (
      tester,
    ) async {
      onProfile = (_) => jsonResponse({
        'data': profileDataJson(staff: null, role: 'administrator'),
      });
      await pumpApp(tester);
      await openProfile(tester);

      expect(
        find.text('No staff profile is linked to this account.'),
        findsOneWidget,
      );
      expect(find.byKey(const Key('profile-account')), findsOneWidget);
      expect(find.text('Administrator'), findsOneWidget);
      expect(find.byKey(const Key('profile-work')), findsNothing);
      expect(find.byKey(const Key('profile-employment')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('missing values read "Not set", never blank', (tester) async {
      final staff = staffMemberJson(manager: null)
        ..['company_phone'] = null
        ..['hire_date'] = null;
      onProfile = (_) =>
          jsonResponse({'data': profileDataJson(staff: staff, role: null)});
      await pumpApp(tester);
      await openProfile(tester);

      // Manager, phone, hire date and role.
      expect(find.text('Not set'), findsNWidgets(4));
    });

    testWidgets('an error offers Try again, which recovers', (tester) async {
      var fail = true;
      onProfile = (_) async {
        if (fail) {
          throw const NetworkFailure();
        }

        return jsonResponse({'data': profileDataJson()});
      };
      await pumpApp(tester);
      await openProfile(tester);

      expect(
        find.text("Couldn't load your profile. Check your connection."),
        findsOneWidget,
      );
      fail = false;
      await tester.tap(find.text('Try again'));
      await tester.pumpAndSettle();

      expect(find.text('EMP-1001'), findsOneWidget);
    });
  });

  group('Staff directory', () {
    testWidgets('lists active staff with name and position · department', (
      tester,
    ) async {
      onDirectory = (_) => jsonResponse(
        directoryPageJson([
          staffMemberJson(publicId: 'A1', firstName: 'Ana', lastName: 'Abad'),
          staffMemberJson(
            publicId: 'B2',
            firstName: 'Ben',
            lastName: 'Bautista',
            department: null,
          ),
          staffMemberJson(
            publicId: 'C3',
            firstName: 'Cy',
            lastName: 'Cruz',
            position: null,
            department: null,
          ),
        ]),
      );
      await pumpApp(tester);
      await openDirectory(tester);

      expect(find.byType(StaffDirectoryPage), findsOneWidget);
      final names = tester
          .widgetList<ListTile>(find.byType(ListTile))
          .map((t) => (t.title! as Text).data)
          .toList();
      expect(names, ['Ana Abad', 'Ben Bautista', 'Cy Cruz']);
      expect(find.text('Field Technician · Operations'), findsOneWidget);
      expect(find.text('Field Technician'), findsOneWidget);
      // Cy has neither, so no subtitle at all.
      final cy = tester.widget<ListTile>(
        find.byKey(const Key('directory-row-C3')),
      );
      expect(cy.subtitle, isNull);
      expect(staffRequests().single.queryParameters['status'], 'active');
      // R-4: no operational status anywhere.
      expect(find.textContaining('available'), findsNothing);
    });

    testWidgets('search narrows the list after typing pauses; clear resets', (
      tester,
    ) async {
      onDirectory = (url) {
        final q = url.queryParameters['q'];

        return jsonResponse(
          directoryPageJson(
            q == null
                ? staffList(3)
                : [staffMemberJson(firstName: 'Grace', lastName: 'Hopper')],
          ),
        );
      };
      await pumpApp(tester);
      await openDirectory(tester);

      await tester.enterText(find.byKey(const Key('directory-search')), 'Gr');
      await tester.pump(const Duration(milliseconds: 100));
      await tester.enterText(
        find.byKey(const Key('directory-search')),
        'Grace',
      );
      await tester.pump(const Duration(milliseconds: 350));
      await tester.pumpAndSettle();

      expect(staffRequests().map((u) => u.queryParameters['q']), [
        null,
        'Grace',
      ], reason: 'only the paused text is searched');
      expect(find.text('Grace Hopper'), findsOneWidget);
      expect(find.byType(ListTile), findsOneWidget);

      await tester.tap(find.byTooltip('Clear search'));
      await tester.pump(const Duration(milliseconds: 350));
      await tester.pumpAndSettle();

      expect(find.byType(ListTile), findsNWidgets(3));
      expect(staffRequests().last.queryParameters.containsKey('q'), isFalse);
    });

    testWidgets('empty states distinguish no staff from no matches', (
      tester,
    ) async {
      onDirectory = (_) => jsonResponse(directoryPageJson([]));
      await pumpApp(tester);
      await openDirectory(tester);

      expect(find.text('No active staff yet.'), findsOneWidget);

      await tester.enterText(find.byKey(const Key('directory-search')), 'zz');
      await tester.pump(const Duration(milliseconds: 350));
      await tester.pumpAndSettle();

      expect(find.text('No one matches "zz".'), findsOneWidget);
    });

    testWidgets('scrolling to the end loads the next page, without gaps', (
      tester,
    ) async {
      onDirectory = (url) {
        final page = int.parse(url.queryParameters['page']!);

        return jsonResponse(
          directoryPageJson(
            staffList(page == 1 ? 25 : 3, prefix: 'P$page'),
            page: page,
            lastPage: 2,
          ),
        );
      };
      await pumpApp(tester, tall: false);
      await openDirectory(tester);

      expect(staffRequests(), hasLength(1));

      await tester.scrollUntilVisible(
        find.text('P2 2'),
        300,
        scrollable: pageScrollable(StaffDirectoryPage),
      );
      await tester.pumpAndSettle();

      expect(staffRequests().map((u) => u.queryParameters['page']), ['1', '2']);
      expect(find.byKey(const Key('directory-loading-more')), findsNothing);
      expect(find.text('P2 0'), findsOneWidget);
    });

    testWidgets('a failed page shows a retry row that works', (tester) async {
      var failPage2 = true;
      onDirectory = (url) async {
        final page = int.parse(url.queryParameters['page']!);
        if (page == 2 && failPage2) {
          throw const NetworkFailure();
        }

        return jsonResponse(
          directoryPageJson(
            staffList(page == 1 ? 3 : 2, prefix: 'P$page'),
            page: page,
            lastPage: 2,
          ),
        );
      };
      await pumpApp(tester);
      await openDirectory(tester);

      expect(find.text("Couldn't load more. Tap to retry."), findsOneWidget);
      expect(find.text('P1 0'), findsOneWidget, reason: 'list kept');

      failPage2 = false;
      await tester.tap(find.byKey(const Key('directory-load-more-retry')));
      await tester.pumpAndSettle();

      expect(find.text('P2 1'), findsOneWidget);
      expect(find.text("Couldn't load more. Tap to retry."), findsNothing);
    });

    testWidgets('pull-to-refresh reloads the first page', (tester) async {
      await pumpApp(tester);
      await openDirectory(tester);

      await tester.fling(
        pageScrollable(StaffDirectoryPage),
        // RefreshIndicator arms at 25% of the (tall, 2400px) viewport.
        const Offset(0, 1000),
        1000,
      );
      await tester.pumpAndSettle();

      expect(staffRequests(), hasLength(2));
      expect(find.byType(ListTile), findsNWidgets(3));
    });

    testWidgets('no staff.view: an access message, and the session is kept', (
      tester,
    ) async {
      onDirectory = (_) => jsonError(403, 'This action is unauthorized.');
      await pumpApp(tester);
      await openDirectory(tester);

      expect(
        find.text("You don't have access to the staff directory."),
        findsOneWidget,
      );
      expect(auth.status, AuthStatus.authenticated);
      expect(find.byType(LoginPage), findsNothing);
    });

    testWidgets('a revoked session returns to Login with the notice', (
      tester,
    ) async {
      onDirectory = (_) => jsonError(401);
      await pumpApp(tester);
      await openDirectory(tester);

      expect(find.byType(LoginPage), findsOneWidget);
      expect(
        find.text('Your session has ended. Please sign in again.'),
        findsOneWidget,
      );
    });
  });

  group('Staff detail', () {
    Future<void> openAda(WidgetTester tester) async {
      onDirectory = (_) => jsonResponse(
        directoryPageJson([
          staffMemberJson(publicId: 'ADA', preferredName: 'Ada L.'),
        ]),
      );
      onStaff = (url, id) => jsonResponse({
        'data': id == 'ADA'
            ? staffMemberJson(publicId: 'ADA', preferredName: 'Ada L.')
            : staffMemberJson(
                publicId: id,
                firstName: 'Grace',
                lastName: 'Hopper',
                manager: null,
              ),
      });
      await pumpApp(tester);
      await openDirectory(tester);
      await tester.tap(find.byKey(const Key('directory-row-ADA')));
      await tester.pumpAndSettle();
    }

    testWidgets('shows the approved fields only (R-5)', (tester) async {
      await openAda(tester);

      expect(find.byType(StaffDetailPage), findsOneWidget);
      expect(requests.last.path, '/api/v1/staff/ADA');
      for (final text in [
        'Ada L.',
        'Ada Lovelace',
        'Field Technician',
        'Operations',
        'Field Team A',
        'Grace Hopper',
        'ada@company.test',
        '+63 2 8123 4567',
      ]) {
        expect(find.text(text), findsWidgets, reason: text);
      }
      for (final hidden in ['EMP-1001', '1 Mar 2024', 'active', 'available']) {
        expect(find.textContaining(hidden), findsNothing, reason: hidden);
      }
    });

    testWidgets('Copy puts the email or phone on the clipboard', (
      tester,
    ) async {
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
      await openAda(tester);

      await tester.tap(find.byTooltip('Copy email'));
      await tester.pump();
      expect(find.text('Copied'), findsOneWidget);
      await tester.tap(find.byTooltip('Copy phone'));
      await tester.pump();

      expect(copied, ['ada@company.test', '+63 2 8123 4567']);
    });

    testWidgets('tapping the manager opens their entry; back returns', (
      tester,
    ) async {
      await openAda(tester);

      await tester.tap(find.byKey(const Key('detail-manager')));
      await tester.pumpAndSettle();

      expect(requests.last.path, '/api/v1/staff/01J0MGR00000000000000000AA');
      expect(find.text('Grace Hopper'), findsWidgets);
      expect(find.text('ada@company.test'), findsNothing);
      // Grace has no manager: the row is plain, not a link.
      final row = tester.widget<ListTile>(
        find.descendant(
          of: find.byKey(const Key('detail-manager')),
          matching: find.byType(ListTile),
        ),
      );
      expect(row.onTap, isNull);
      expect(find.text('Not set'), findsOneWidget);

      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.text('ada@company.test'), findsOneWidget);
    });

    testWidgets('switching tabs keeps the More stack', (tester) async {
      await openAda(tester);

      await tester.tap(find.widgetWithText(NavigationDestination, 'Home'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(NavigationDestination, 'More'));
      await tester.pumpAndSettle();

      expect(find.byType(StaffDetailPage), findsOneWidget);
      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.byType(StaffDirectoryPage), findsOneWidget);
      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.byType(MorePage), findsOneWidget);
    });

    testWidgets('controls meet the tap-target and labelling guidelines', (
      tester,
    ) async {
      final handle = tester.ensureSemantics();
      await openAda(tester);

      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
      handle.dispose();
    });
  });

  group('resilience: theme, text scale and width', () {
    Map<String, dynamic> longStaff(String id) => staffMemberJson(
      publicId: id,
      firstName: 'Maximiliana Concepcion',
      lastName: 'de los Santos-Villanueva',
      preferredName: 'Maximiliana Concepcion de los Santos-Villanueva Jr.',
      position: {
        'public_id': 'P',
        'title': 'Senior Field Service Technician and Safety Coordinator',
      },
      department: {
        'public_id': 'D',
        'name': 'Northern Luzon Regional Operations and Maintenance',
      },
      team: {'public_id': 'T', 'name': 'Rapid Response Night Shift Team B'},
      manager: {
        'public_id': 'M',
        'display_name': 'Bartholomew Alexander Fitzgerald-Montgomery',
      },
    );

    for (final (label, brightness, scale, size)
        in <(String, Brightness, double, Size)>[
          ('light, phone', Brightness.light, 1.0, const Size(360, 740)),
          ('dark, phone', Brightness.dark, 1.0, const Size(360, 740)),
          (
            'light, 200% text, phone',
            Brightness.light,
            2.0,
            const Size(360, 740),
          ),
          (
            'dark, 200% text, phone',
            Brightness.dark,
            2.0,
            const Size(360, 740),
          ),
          (
            'light, 200% text, tablet',
            Brightness.light,
            2.0,
            const Size(1024, 768),
          ),
        ]) {
      testWidgets('$label: More, profile, directory and detail fit', (
        tester,
      ) async {
        tester.view.physicalSize = size * tester.view.devicePixelRatio;
        addTearDown(tester.view.resetPhysicalSize);
        tester.platformDispatcher.platformBrightnessTestValue = brightness;
        addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
        tester.platformDispatcher.textScaleFactorTestValue = scale;
        addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);

        onProfile = (_) =>
            jsonResponse({'data': profileDataJson(staff: longStaff('ME'))});
        onDirectory = (_) => jsonResponse(
          directoryPageJson([for (var i = 0; i < 4; i++) longStaff('S$i')]),
        );
        onStaff = (url, id) => jsonResponse({'data': longStaff(id)});
        await pumpApp(tester, tall: false);

        await openMore(tester);
        expect(tester.takeException(), isNull, reason: 'More');
        expect(
          Theme.of(tester.element(find.byType(MorePage))).brightness,
          brightness,
        );

        // Profile: walk to the last row.
        await tester.tap(find.byKey(const Key('more-profile')));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull, reason: 'profile');
        await tester.scrollUntilVisible(
          find.byKey(const Key('profile-read-only-note')),
          200,
          scrollable: pageScrollable(MyProfilePage),
        );
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull, reason: 'profile end');
        await tester.pageBack();
        await tester.pumpAndSettle();

        // Directory: the search field and the last row.
        await tester.tap(find.byKey(const Key('more-directory')));
        await tester.pumpAndSettle();
        expect(
          find.byKey(const Key('directory-search')).hitTestable(),
          findsOneWidget,
        );
        await tester.scrollUntilVisible(
          find.byKey(const Key('directory-row-S3')),
          // Rows with these long names are ~500px tall at 200% text.
          400,
          scrollable: pageScrollable(StaffDirectoryPage),
        );
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull, reason: 'directory');

        // Detail: walk to the phone row and its copy button.
        await tester.tap(find.byKey(const Key('directory-row-S3')));
        await tester.pumpAndSettle();
        await tester.scrollUntilVisible(
          find.byTooltip('Copy phone'),
          200,
          scrollable: pageScrollable(StaffDetailPage),
        );
        await tester.pumpAndSettle();
        expect(find.byTooltip('Copy phone').hitTestable(), findsOneWidget);
        expect(tester.takeException(), isNull, reason: 'detail');
      });
    }
  });
}
