import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/app/app.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/people/presentation/more_page.dart';
import 'package:mobile/features/reports/data/photo_source.dart';
import 'package:mobile/features/reports/presentation/report_detail_pages.dart';
import 'package:mobile/features/reports/presentation/report_form_pages.dart';
import 'package:mobile/features/reports/presentation/report_widgets.dart';
import 'package:mobile/features/reports/presentation/reports_page.dart';

import '../../support/fake_backend.dart';
import '../../support/home_fixtures.dart' show homeClientFor, homeResponse;
import '../../support/leave_fixtures.dart' show pageBody;
import '../../support/my_status_fixtures.dart' show myStatusClientFor;
import '../../support/people_fixtures.dart' show jsonResponse;
import '../../support/project_fixtures.dart'
    show clientJson, clientsClientFor, pageJson, projectsClientFor;
import '../../support/report_fixtures.dart';

/// Phase 33 Gate 3 — More → Reports, the forms, the details and photos
/// through the real app against an in-memory reports API and a fake photo
/// source (docs/phases/V1_PHASE_33_DEFINITION.md, R-1…R-11). The company
/// day is Wed 14 Oct 2026 in Manila.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late FakeReportsServer server;
  late FakePhotoSource photos;

  Future<http.Response> route(http.Request r) async {
    final path = r.url.path;
    if (path.endsWith('/me/home')) {
      return homeResponse();
    }
    if (path.endsWith('/me/status')) {
      return jsonResponse(pageBody([]));
    }
    if (path.endsWith('/clients')) {
      return jsonResponse(
        pageJson([
          clientJson(publicId: 'CL1', name: 'Acme Foods', clientCode: 'AF'),
          clientJson(publicId: 'CL2', name: 'Other Co', clientCode: null),
        ]),
      );
    }

    return server.handle(r);
  }

  setUp(() {
    photos = FakePhotoSource();
    server = FakeReportsServer()
      ..services = [
        serviceReportJson(
          'SR-DRAFT',
          project: {'public_id': 'P1', 'name': 'Plant Room'},
        ),
        serviceReportJson('SR-SENT', status: 'submitted', date: '2026-10-10'),
      ]
      ..incidents = [incidentJson('IR1')]
      ..projects = [
        projectJson('P1', 'Plant Room'),
        projectJson('P3', 'Elsewhere', clientId: 'CL2'),
      ];
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
        myStatusApiClient: myStatusClientFor(backend, auth),
        clientsApiClient: clientsClientFor(backend, auth),
        projectsApiClient: projectsClientFor(backend, auth),
        reportsApiClient: reportsClientFor(backend, auth),
        photoSource: photos,
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> tapKey(WidgetTester tester, String key) async {
    await tester.ensureVisible(find.byKey(Key(key)));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(Key(key)));
    await tester.pumpAndSettle();
  }

  Future<void> openReports(WidgetTester tester) async {
    await tester.tap(find.widgetWithText(NavigationDestination, 'More'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.byKey(const Key('more-reports')),
      100,
      scrollable: find
          .descendant(
            of: find.byType(MorePage),
            matching: find.byType(Scrollable),
          )
          .first,
    );
    await tapKey(tester, 'more-reports');
  }

  Finder inKey(String key, Finder matching) =>
      find.descendant(of: find.byKey(Key(key)), matching: matching);

  String target(http.Request r) =>
      '${r.method} ${r.url.path.replaceFirst('/api/v1', '')}';

  PickedPhoto photo() =>
      PickedPhoto(bytes: Uint8List.fromList(tinyPng), filename: 'scaled_1.jpg');

  group('Reports (R-1)', () {
    testWidgets('service reports and incidents with their chips', (
      tester,
    ) async {
      await pumpApp(tester);
      await openReports(tester);

      expect(find.byType(ReportsPage), findsOneWidget);
      expect(
        inKey('service-reports-row-SR-DRAFT', find.text('Acme Foods')),
        findsOneWidget,
      );
      expect(
        inKey(
          'service-reports-row-SR-DRAFT',
          find.text('Tue 13 Oct 2026 · Plant Room'),
        ),
        findsOneWidget,
      );
      expect(
        inKey('service-reports-row-SR-DRAFT', find.text('Draft')),
        findsOneWidget,
      );
      expect(
        inKey('service-reports-row-SR-SENT', find.text('Submitted')),
        findsOneWidget,
      );

      await tester.tap(find.text('Incidents'));
      await tester.pumpAndSettle();
      // 01:30 UTC is 09:30 in Manila.
      expect(
        inKey('incidents-row-IR1', find.text('Workplace safety')),
        findsOneWidget,
      );
      expect(
        inKey(
          'incidents-row-IR1',
          find.text('Wed 14 Oct 2026 09:30 · Loading bay'),
        ),
        findsOneWidget,
      );
      expect(inKey('incidents-row-IR1', find.text('High')), findsOneWidget);
      expect(inKey('incidents-row-IR1', find.text('Reported')), findsOneWidget);
      expect(find.byTooltip('Report an incident'), findsOneWidget);
    });

    testWidgets('no profile', (tester) async {
      server.myStaffId = null;
      await pumpApp(tester);
      await openReports(tester);

      expect(
        find.byKey(const Key('service-reports-no-profile')),
        findsOneWidget,
      );
    });
  });

  group('Service reports (R-3, R-4, R-7)', () {
    testWidgets('a new draft: client, project, today; it opens its detail', (
      tester,
    ) async {
      await pumpApp(tester);
      await openReports(tester);
      await tapKey(tester, 'reports-new');

      expect(find.byType(ServiceReportFormPage), findsOneWidget);
      expect(
        inKey('service-form-date', find.text('Wed 14 Oct 2026 (today)')),
        findsOneWidget,
      );
      await tapKey(tester, 'service-form-save');
      expect(find.text('Choose a client.'), findsOneWidget);
      expect(find.text('Describe the work performed.'), findsOneWidget);

      await tapKey(tester, 'service-form-client');
      await tapKey(tester, 'report-client-CL1');
      await tapKey(tester, 'service-form-project');
      expect(find.byKey(const Key('report-project-P3')), findsNothing);
      await tapKey(tester, 'report-project-P1');
      await tester.enterText(
        find.byKey(const Key('service-form-work')),
        'Serviced the chiller.',
      );
      await tester.pumpAndSettle();
      await tapKey(tester, 'service-form-save');

      final post = server.requests.singleWhere((r) => r.method == 'POST');
      expect(server.bodyOf(post), {
        'client_id': 'CL1',
        'project_id': 'P1',
        'service_date': '2026-10-14',
        'work_performed': 'Serviced the chiller.',
        'findings': null,
        'recommendations': null,
        'follow_up_actions': null,
        'site_representative_name': null,
      });
      expect(find.byType(ServiceReportPage), findsOneWidget);
      expect(find.text('Draft saved.'), findsOneWidget);
      // Back goes to the list (the form was replaced), which reloaded.
      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.byType(ReportsPage), findsOneWidget);
      expect(
        find.byKey(const Key('service-reports-row-SR-NEW-1')),
        findsOneWidget,
      );
    });

    testWidgets('on my draft: take a photo, open it, remove it', (
      tester,
    ) async {
      await pumpApp(tester);
      await openReports(tester);
      await tapKey(tester, 'service-reports-row-SR-DRAFT');

      expect(find.text('Photos and files (0)'), findsOneWidget);
      photos.answers = [photo()];
      await tapKey(tester, 'report-take-photo');

      expect(photos.picks, [PhotoOrigin.camera]);
      expect(find.text('Photos and files (1)'), findsOneWidget);
      expect(
        server.requests.map(target),
        contains('POST /service-reports/SR-DRAFT/attachments'),
      );

      await tapKey(tester, 'report-attachment-AT-1');
      expect(find.byType(PhotoViewerPage), findsOneWidget);
      expect(find.byKey(const Key('photo-viewer-image')), findsOneWidget);
      await tester.tap(find.byType(CloseButton));
      await tester.pumpAndSettle();

      await tapKey(tester, 'report-attachment-remove-AT-1');
      await tapKey(tester, 'report-photo-remove-confirm');
      expect(find.text('Photos and files (0)'), findsOneWidget);
    });

    testWidgets('submit: confirmed, then no editing', (tester) async {
      await pumpApp(tester);
      await openReports(tester);
      await tapKey(tester, 'service-reports-row-SR-DRAFT');

      await tapKey(tester, 'report-submit');
      expect(
        find.text(
          "Your manager will review it. You can't edit it after submitting.",
        ),
        findsOneWidget,
      );
      await tapKey(tester, 'report-submit-confirm');

      expect(find.text('Report submitted.'), findsOneWidget);
      expect(inKey('report-detail', find.text('Submitted')), findsWidgets);
      for (final k in [
        'report-submit',
        'report-edit',
        'report-delete',
        'report-take-photo',
      ]) {
        expect(find.byKey(Key(k)), findsNothing, reason: k);
      }
      expect(
        inKey(
          'report-history-0',
          find.textContaining('Submitted by Ada Lovelace'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('edit a draft', (tester) async {
      await pumpApp(tester);
      await openReports(tester);
      await tapKey(tester, 'service-reports-row-SR-DRAFT');
      await tapKey(tester, 'report-edit');

      expect(find.text('Edit service report'), findsOneWidget);
      await tester.enterText(
        find.byKey(const Key('service-form-work')),
        'Replaced the belt too.',
      );
      await tester.pumpAndSettle();
      await tapKey(tester, 'service-form-save');

      expect(
        server.requests.map(target),
        contains('PATCH /service-reports/SR-DRAFT'),
      );
      expect(find.byType(ServiceReportPage), findsOneWidget);
      expect(find.text('Replaced the belt too.'), findsOneWidget);
    });

    testWidgets('delete a draft', (tester) async {
      await pumpApp(tester);
      await openReports(tester);
      await tapKey(tester, 'service-reports-row-SR-DRAFT');
      await tapKey(tester, 'report-delete');
      await tapKey(tester, 'report-delete-confirm');

      expect(find.byType(ReportsPage), findsOneWidget);
      expect(
        find.byKey(const Key('service-reports-row-SR-DRAFT')),
        findsNothing,
      );
    });

    testWidgets('rejected: the reason shows; Return to draft', (tester) async {
      server.services.first
        ..['status'] = 'rejected'
        ..['history'] = <Map<String, dynamic>>[
          {
            'action': 'rejected',
            'acted_by': rPerson('S-MGR', 'Grace Hopper'),
            'note': 'Add the meter reading.',
            'created_at': '2026-10-14T02:00:00+00:00',
          },
        ];
      await pumpApp(tester);
      await openReports(tester);
      await tapKey(tester, 'service-reports-row-SR-DRAFT');

      expect(find.text('Add the meter reading.'), findsOneWidget);
      expect(find.byKey(const Key('report-edit')), findsNothing);
      await tapKey(tester, 'report-return');
      await tapKey(tester, 'report-return-confirm');

      expect(find.text('Returned to draft.'), findsOneWidget);
      expect(find.byKey(const Key('report-edit')), findsOneWidget);
    });

    testWidgets('a PDF is listed for the web; offline upload says so', (
      tester,
    ) async {
      server.services.first['attachments'] = [
        attachmentJson(
          'A-PDF',
          filename: 'signed.pdf',
          mime: 'application/pdf',
          size: 1572864,
        ),
      ];
      await pumpApp(tester);
      await openReports(tester);
      await tapKey(tester, 'service-reports-row-SR-DRAFT');

      expect(
        inKey(
          'report-attachment-A-PDF',
          find.text('1.5 MB · Open it on the web'),
        ),
        findsOneWidget,
      );
      expect(
        tester
            .widget<ListTile>(find.byKey(const Key('report-attachment-A-PDF')))
            .onTap,
        isNull,
      );

      server.override = (r) =>
          r.method == 'POST' ? throw const NetworkFailure() : null;
      photos.answers = [photo()];
      await tapKey(tester, 'report-choose-photo');
      expect(
        find.text("Couldn't do that. Check your connection and try again."),
        findsOneWidget,
      );
      expect(find.text('Photos and files (1)'), findsOneWidget);
    });

    testWidgets("someone else's report: read-only", (tester) async {
      server.services.first['creator'] = rPerson('S-GRACE', 'Grace Hopper');
      await pumpApp(tester);
      await openReports(tester);
      await tapKey(tester, 'service-reports-row-SR-DRAFT');

      expect(find.text('By Grace Hopper'), findsOneWidget);
      for (final k in ['report-submit', 'report-edit', 'report-take-photo']) {
        expect(find.byKey(Key(k)), findsNothing, reason: k);
      }
    });
  });

  group('Incidents (R-5, R-6)', () {
    testWidgets('report one; add a photo; edit while reported', (tester) async {
      await pumpApp(tester);
      await openReports(tester);
      await tester.tap(find.text('Incidents'));
      await tester.pumpAndSettle();
      await tapKey(tester, 'reports-new');

      expect(find.byType(IncidentFormPage), findsOneWidget);
      await tapKey(tester, 'incident-form-save');
      expect(find.text('Choose the type of incident.'), findsOneWidget);
      expect(find.text('Describe what happened.'), findsOneWidget);

      await tapKey(tester, 'incident-form-type');
      await tapKey(tester, 'incident-type-property_equipment_damage');
      await tapKey(tester, 'incident-severity-critical');
      await tester.enterText(
        find.byKey(const Key('incident-form-description')),
        'Forklift hit a rack.',
      );
      await tester.pumpAndSettle();
      await tapKey(tester, 'incident-form-save');

      final body = server.bodyOf(
        server.requests.singleWhere((r) => r.method == 'POST'),
      );
      expect(body['incident_type'], 'property_equipment_damage');
      expect(body['severity'], 'critical');
      expect(body['description'], 'Forklift hit a rack.');
      expect(DateTime.parse(body['occurred_at'] as String).isUtc, isTrue);
      expect(find.byType(IncidentPage), findsOneWidget);
      expect(find.text('Incident reported.'), findsOneWidget);

      photos.answers = [photo()];
      await tapKey(tester, 'report-choose-photo');
      expect(find.text('Photos and files (1)'), findsOneWidget);
      expect(find.byKey(const Key('report-edit')), findsOneWidget);
    });

    testWidgets('under investigation: no edit or photos', (tester) async {
      server.incidents.first['status'] = 'under_investigation';
      server.incidents.first['assigned_to'] = rPerson('S-MGR', 'Grace Hopper');
      await pumpApp(tester);
      await openReports(tester);
      await tester.tap(find.text('Incidents'));
      await tester.pumpAndSettle();
      await tapKey(tester, 'incidents-row-IR1');

      expect(find.text('Assigned to Grace Hopper'), findsOneWidget);
      expect(find.text('Under investigation'), findsOneWidget);
      expect(find.byKey(const Key('report-edit')), findsNothing);
      expect(find.byKey(const Key('report-take-photo')), findsNothing);
    });
  });

  group('appearance', () {
    for (final (label, brightness) in [
      ('light', Brightness.light),
      ('dark', Brightness.dark),
    ]) {
      testWidgets(
        'lists, both forms and both details fit a small phone in $label mode at 200% text',
        (tester) async {
          tester.platformDispatcher.platformBrightnessTestValue = brightness;
          tester.platformDispatcher.textScaleFactorTestValue = 2.0;
          addTearDown(tester.platformDispatcher.clearAllTestValues);
          tester.view.physicalSize =
              const Size(320, 568) * tester.view.devicePixelRatio;
          addTearDown(tester.view.resetPhysicalSize);
          server.services.first['attachments'] = [
            attachmentJson(
              'A1',
              filename: 'a-very-long-photo-file-name-from-the-camera.jpg',
            ),
          ];

          await pumpApp(tester, tall: false);
          await openReports(tester);
          expect(tester.takeException(), isNull);
          expect(
            Theme.of(tester.element(find.byType(ReportsPage))).brightness,
            brightness,
          );

          // At 200% a row is taller than the list: tap its title.
          await tester.tap(find.text('Acme Foods').first);
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull);
          await tester.pageBack();
          await tester.pumpAndSettle();

          await tapKey(tester, 'reports-new');
          expect(tester.takeException(), isNull);
          await tester.pageBack();
          await tester.pumpAndSettle();

          await tester.tap(find.text('Incidents'));
          await tester.pumpAndSettle();
          await tester.tap(find.text('Workplace safety').first);
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull);
          await tester.pageBack();
          await tester.pumpAndSettle();
          await tapKey(tester, 'reports-new');
          expect(tester.takeException(), isNull);
        },
      );
    }

    testWidgets('controls meet tap-target and label guidelines', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      await pumpApp(tester);
      await openReports(tester);
      await tapKey(tester, 'service-reports-row-SR-DRAFT');

      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
      semantics.dispose();
    });
  });
}
