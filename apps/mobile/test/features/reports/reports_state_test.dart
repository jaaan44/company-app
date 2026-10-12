import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/reports/data/photo_source.dart';
import 'package:mobile/features/reports/domain/report.dart';
import 'package:mobile/features/reports/state/report_changes.dart';
import 'package:mobile/features/reports/state/report_detail_controllers.dart';
import 'package:mobile/features/reports/state/report_forms.dart';
import 'package:mobile/features/reports/state/report_lists.dart';

import '../../support/fake_backend.dart';
import '../../support/report_fixtures.dart';

/// Phase 33 Gate 2 — report models, the reports client, lists, forms and
/// details with photos, over the real `ApiClient` (spec R-1…R-11).
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late FakeReportsServer server;
  late FakePhotoSource photos;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    server = FakeReportsServer();
    backend.onApi = server.handle;
    photos = FakePhotoSource();
  });

  String target(http.Request r) =>
      '${r.method} ${r.url.path.replaceFirst('/api/v1', '')}';

  group('models', () {
    test('a service report parses and knows what I may do', () {
      final r = ServiceReport.fromJson(
        serviceReportJson(
          'SR1',
          project: {'public_id': 'P1', 'name': 'Plant Room'},
          attachments: [
            attachmentJson('A1'),
            attachmentJson('A2', filename: 'form.pdf', mime: 'application/pdf'),
          ],
          history: [
            {
              'action': 'returned_to_draft',
              'acted_by': null,
              'note': null,
              'created_at': '2026-10-14T03:00:00+00:00',
            },
          ],
        ),
      );
      expect(r.client.name, 'Acme Foods');
      expect(r.project?.name, 'Plant Room');
      expect(r.findings, 'Worn belt.');
      expect(r.recommendations, isNull);
      expect(r.attachments.map((a) => a.isImage), [true, false]);
      expect(r.history.single.label, 'Returned to draft');
      expect(r.canEditBy(reportMe), isTrue);
      expect(r.canEditBy('S-OTHER'), isFalse);
      expect(
        ServiceReport.fromJson(serviceReportJson('X', status: 'submitted'))
            .canEditBy(reportMe),
        isFalse,
      );
      expect(
        ServiceReport.fromJson(serviceReportJson('X', status: 'rejected'))
            .canReturnBy(reportMe),
        isTrue,
      );
      expect(
        ServiceReport.fromJson(serviceReportJson('X', status: 'archived'))
            .status,
        ServiceReportStatus.unknown,
      );
    });

    test('an incident parses; edit only while reported and mine', () {
      final i = IncidentReport.fromJson(incidentJson('IR1'));
      expect(
        (i.type, i.severity, i.status),
        (
          IncidentType.workplaceSafety,
          IncidentSeverity.high,
          IncidentStatus.reported,
        ),
      );
      expect(i.occurredAt, DateTime.utc(2026, 10, 14, 1, 30));
      expect(i.canEditBy(reportMe), isTrue);
      expect(
        IncidentReport.fromJson(
          incidentJson('X', status: 'under_investigation'),
        ).canEditBy(reportMe),
        isFalse,
      );
      expect(
        IncidentReport.fromJson(incidentJson('X', reporter: 'S-G'))
            .canEditBy(reportMe),
        isFalse,
      );
      expect(
        IncidentReport.fromJson(incidentJson('X', type: 'alien')).type,
        IncidentType.unknown,
      );
    });

    test('broken shapes are rejected', () {
      expect(
        () => ServiceReport.fromJson(serviceReportJson('X')..remove('client')),
        throwsFormatException,
      );
      expect(
        () => ServiceReport.fromJson(
          serviceReportJson('X')..['service_date'] = '14/10',
        ),
        throwsFormatException,
      );
      expect(
        () => IncidentReport.fromJson(incidentJson('X')..remove('reporter')),
        throwsFormatException,
      );
      expect(
        () => IncidentReport.fromJson(
          incidentJson('X')..['occurred_at'] = 'today',
        ),
        throwsFormatException,
      );
    });

    test('drafts send blank optional text as null', () {
      expect(
        const ServiceReportDraft(
          clientId: 'C',
          serviceDate: '2026-10-14',
          workPerformed: ' Fixed ',
          findings: '  ',
        ).toJson(),
        {
          'client_id': 'C',
          'project_id': null,
          'service_date': '2026-10-14',
          'work_performed': 'Fixed',
          'findings': null,
          'recommendations': null,
          'follow_up_actions': null,
          'site_representative_name': null,
        },
      );
      expect(
        IncidentDraft(
          type: IncidentType.other,
          severity: IncidentSeverity.low,
          occurredAt: DateTime.utc(2026, 10, 14, 1),
          description: 'x',
        ).toJson()['occurred_at'],
        '2026-10-14T01:00:00.000Z',
      );
    });

    test('file sizes read naturally', () {
      expect(fileSizeLabel(204800), '200 KB');
      expect(fileSizeLabel(2516582), '2.4 MB');
    });
  });

  test('only photo_source.dart imports image_picker (R-8)', () {
    final importers = Directory('lib')
        .listSync(recursive: true)
        .whereType<File>()
        .where((f) => f.path.endsWith('.dart'))
        .where((f) => f.readAsStringSync().contains('package:image_picker'))
        .map((f) => f.path.replaceAll(r'\', '/'))
        .toList();

    expect(importers, ['lib/features/reports/data/photo_source.dart']);
  });

  group('lists', () {
    test(
      'service reports and incidents, with the company day; no profile',
      () async {
        server
          ..services = [serviceReportJson('SR2'), serviceReportJson('SR1')]
          ..incidents = [incidentJson('IR1')];
        final s = ServiceReportsController(reportsClientFor(backend, auth));
        final i = IncidentsController(reportsClientFor(backend, auth));
        await s.load();
        await i.load();

        expect(s.items.map((r) => r.publicId), ['SR2', 'SR1']);
        expect(s.myStaffId, reportMe);
        expect(s.companyDay?.date, '2026-10-14');
        expect(i.items.single.publicId, 'IR1');

        server.myStaffId = null;
        final none = ServiceReportsController(reportsClientFor(backend, auth));
        await none.load();
        expect(none.status, PagedListStatus.noProfile);
      },
    );
  });

  group('ServiceReportFormController', () {
    ServiceReportFormController form({ServiceReport? existing}) =>
        ServiceReportFormController(
          reportsClientFor(backend, auth),
          projectsClientForReports(backend, auth),
          existing: existing,
          companyDate: '2026-10-14',
          changes: ReportChanges(),
        );

    test('offers my open projects of the chosen client', () async {
      server.projects = [
        projectJson('P1', 'Plant Room'),
        projectJson('P2', 'Old job', status: 'completed'),
        projectJson('P3', 'Elsewhere', clientId: 'CL2'),
      ];
      final f = form();
      await f.load();
      expect(f.status, ReportFormStatus.ready);
      expect(f.serviceDate, '2026-10-14');
      expect(f.projectOptions, isEmpty);

      f.setClient(const PickedRef(publicId: 'CL1', name: 'Acme Foods'));
      expect(f.projectOptions.map((p) => p.publicId), ['P1']);
      f.setProject(const PickedRef(publicId: 'P1', name: 'Plant Room'));
      f.setClient(const PickedRef(publicId: 'CL2', name: 'Other'));
      expect(f.projectRef, isNull);
    });

    test('checks before sending', () async {
      final f = form();
      await f.load();
      f
        ..setDate('2026-10-15')
        ..setText('findings', 'x' * 5001);

      expect(await f.submit(), isNull);
      expect(f.fieldErrors, {
        'client_id': 'Choose a client.',
        'service_date': 'The service date cannot be later than today.',
        'work_performed': 'Describe the work performed.',
        'findings': 'Use at most 5000 characters.',
      });
      expect(server.writes(), isEmpty);
    });

    test(
      'creates a draft once; the server\'s 422 lands on its field',
      () async {
        final f = form();
        await f.load();
        f
          ..setClient(const PickedRef(publicId: 'CL1', name: 'Acme Foods'))
          ..setText('work_performed', 'Replaced the belt.');
        expect(f.isDirty, isTrue);

        final first = f.submit();
        expect(await f.submit(), isNull);
        final saved = await first;
        expect(saved?.status, ServiceReportStatus.draft);
        expect(server.writes().map(target), ['POST /service-reports']);
        expect(
          server.bodyOf(server.writes().single)['service_date'],
          '2026-10-14',
        );
        expect(f.isDirty, isFalse);

        server.override = (r) => r.method == 'POST'
            ? http.Response(
                '{"message":"Invalid.","errors":{"service_date":["The service date cannot be later than today."],"project_id":["You are not a member of this project."]}}',
                422,
              )
            : null;
        f.setText('work_performed', 'Again');
        expect(await f.submit(), isNull);
        expect(
          f.fieldErrors['service_date'],
          'The service date cannot be later than today.',
        );
        expect(
          f.fieldErrors['project_id'],
          'You are not a member of this project.',
        );
      },
    );

    test('edits an existing draft with PATCH', () async {
      server.services = [serviceReportJson('SR1')];
      final f = form(existing: ServiceReport.fromJson(server.services.first));
      await f.load();
      expect(f.isDirty, isFalse);
      f.setText('work_performed', 'Replaced the filter and belt.');

      expect(
        (await f.submit())?.workPerformed,
        'Replaced the filter and belt.',
      );
      expect(server.writes().map(target), ['PATCH /service-reports/SR1']);
    });

    test('no profile', () async {
      server.myStaffId = null;
      final f = form();
      await f.load();
      expect(f.status, ReportFormStatus.noProfile);
    });
  });

  group('IncidentFormController', () {
    final now = DateTime.utc(2026, 10, 14, 2, 30, 45);

    test(
      'defaults to now (to the minute), Medium; checks; creates once',
      () async {
        final f = IncidentFormController(
          reportsClientFor(backend, auth),
          clock: () => now,
        );
        await f.load();
        expect(f.occurredAt, DateTime.utc(2026, 10, 14, 2, 30));
        expect(f.severity, IncidentSeverity.medium);

        f.setOccurredAt(now.add(const Duration(hours: 1)));
        expect(await f.submit(), isNull);
        expect(f.fieldErrors, {
          'incident_type': 'Choose the type of incident.',
          'occurred_at': 'The time cannot be in the future.',
          'description': 'Describe what happened.',
        });

        f
          ..setOccurredAt(DateTime.utc(2026, 10, 14, 1))
          ..setType(IncidentType.clientSite)
          ..setSeverity(IncidentSeverity.critical)
          ..setText('description', 'Gate left open.')
          ..setText('location', ' Gate 3 ');
        final saved = await f.submit();
        expect(saved?.type, IncidentType.clientSite);
        expect(server.bodyOf(server.writes().single), {
          'incident_type': 'client_site',
          'severity': 'critical',
          'occurred_at': '2026-10-14T01:00:00.000Z',
          'location': 'Gate 3',
          'description': 'Gate left open.',
          'immediate_action_taken': null,
          'people_involved': null,
          'witness_notes': null,
        });
      },
    );
  });

  group('details and photos', () {
    PickedPhoto photo([int size = 3]) => PickedPhoto(
      bytes: Uint8List.fromList(List.filled(size, 7)),
      filename: 'scaled_123.jpg',
    );

    ServiceReportController service(
      Map<String, dynamic> json, {
      ReportChanges? changes,
    }) {
      server.services = [json];

      return ServiceReportController(
        reportsClientFor(backend, auth),
        json['public_id'] as String,
        photoSource: photos,
        initial: ServiceReport.fromJson(json),
        myStaffId: reportMe,
        changes: changes,
      );
    }

    test('add a photo: one upload, then the report is read again', () async {
      final changes = ReportChanges();
      final c = service(serviceReportJson('SR1'), changes: changes);
      photos.answers = [photo()];

      expect(await c.addPhoto(PhotoOrigin.camera), isTrue);

      expect(photos.picks, [PhotoOrigin.camera]);
      expect(server.writes().map(target), [
        'POST /service-reports/SR1/attachments',
      ]);
      final raw = String.fromCharCodes(server.writes().single.bodyBytes);
      expect(raw, contains('name="file"; filename="scaled_123.jpg"'));
      expect(c.attachments.single.filename, 'scaled_123.jpg');
      expect(changes.revision, 1);
    });

    test(
      'cancelled, oversized, or the picker failing: nothing is sent',
      () async {
        final c = service(serviceReportJson('SR1'));
        photos.answers = [
          null,
          photo(ReportLimits.maxAttachmentBytes + 1),
          Exception('no camera'),
        ];

        expect(await c.addPhoto(PhotoOrigin.gallery), isFalse);
        expect(c.actionMessage, isNull);
        expect(await c.addPhoto(PhotoOrigin.gallery), isFalse);
        expect(c.actionMessage, 'This photo is larger than 10 MB.');
        expect(await c.addPhoto(PhotoOrigin.camera), isFalse);
        expect(c.actionMessage, "Couldn't open the camera.");
        expect(server.writes(), isEmpty);
      },
    );

    test("the server's 422 and 409 are shown; a 409 reloads", () async {
      final json = serviceReportJson('SR1');
      final c = service(json);
      photos.answers = [photo(), photo()];
      server.override = (r) => r.method == 'POST'
          ? http.Response(
              '{"message":"Invalid.","errors":{"file":["The file field must be a file of type: jpg, jpeg, png, pdf."]}}',
              422,
            )
          : null;
      expect(await c.addPhoto(PhotoOrigin.gallery), isFalse);
      expect(
        c.actionMessage,
        'The file field must be a file of type: jpg, jpeg, png, pdf.',
      );

      server.override = (r) {
        if (r.method == 'POST') {
          json['status'] = 'submitted';

          return jsonError(
            409,
            'Attachments may only be added or removed while this service report is a draft.',
          );
        }

        return null;
      };
      expect(await c.addPhoto(PhotoOrigin.gallery), isFalse);
      expect(c.actionMessage, startsWith('Attachments may only be added'));
      expect(c.data?.status, ServiceReportStatus.submitted);
      expect(c.canAttach, isFalse);
    });

    test('a web server size limit (413) says the photo is too large', () async {
      final c = service(serviceReportJson('SR1'));
      photos.answers = [photo()];
      server.override = (r) => r.method == 'POST'
          ? http.Response('<html>413 Request Entity Too Large</html>', 413)
          : null;

      expect(await c.addPhoto(PhotoOrigin.camera), isFalse);
      expect(c.actionMessage, 'This photo is too large to upload.');
    });

    test('remove a photo; read a photo\'s bytes', () async {
      server.files['A1'] = Uint8List.fromList([1, 2, 3]);
      final c = service(
        serviceReportJson('SR1', attachments: [attachmentJson('A1')]),
      );

      expect(await c.photoBytes(c.attachments.single), [1, 2, 3]);
      expect(await c.removePhoto(c.attachments.single), isTrue);
      expect(c.attachments, isEmpty);
      expect(server.writes().map(target), [
        'DELETE /service-reports/SR1/attachments/A1',
      ]);
    });

    test('submit, return to draft, delete — only when allowed', () async {
      final c = service(serviceReportJson('SR1'));
      expect(c.canReturnToDraft, isFalse);
      expect(await c.returnToDraft(), isFalse);

      expect(await c.submit(), isTrue);
      expect(c.data?.status, ServiceReportStatus.submitted);
      expect(c.canEdit, isFalse);
      expect(await c.deleteReport(), isFalse);

      c.data!; // still loaded
      server.services.first['status'] = 'rejected';
      await c.load();
      expect(c.canReturnToDraft, isTrue);
      expect(await c.returnToDraft(), isTrue);
      expect(c.data?.status, ServiceReportStatus.draft);

      expect(await c.deleteReport(), isTrue);
      expect(c.deleted, isTrue);
      expect(server.services, isEmpty);
      expect(server.writes().map(target), [
        'POST /service-reports/SR1/submit',
        'POST /service-reports/SR1/return-to-draft',
        'DELETE /service-reports/SR1',
      ]);
    });

    test('someone else\'s report: nothing is offered or sent', () async {
      final c = service(serviceReportJson('SR1', creator: 'S-GRACE'));
      photos.answers = [photo()];

      expect((c.canEdit, c.canAttach), (false, false));
      expect(await c.addPhoto(PhotoOrigin.camera), isFalse);
      expect(await c.submit(), isFalse);
      expect(photos.picks, isEmpty);
      expect(server.writes(), isEmpty);
    });

    test('an incident: photos while reported', () async {
      server.incidents = [incidentJson('IR1')];
      final c = IncidentController(
        reportsClientFor(backend, auth),
        'IR1',
        photoSource: photos,
      );
      await c.load();
      expect(c.canAttach, isTrue);
      photos.answers = [photo()];
      expect(await c.addPhoto(PhotoOrigin.gallery), isTrue);
      expect(server.writes().map(target), [
        'POST /incident-reports/IR1/attachments',
      ]);

      server.incidents.first['status'] = 'under_investigation';
      await c.load();
      expect(c.canAttach, isFalse);
    });

    test('a gone report', () async {
      final c = ServiceReportController(
        reportsClientFor(backend, auth),
        'GONE',
        photoSource: photos,
      );
      await c.load();

      expect(c.errorMessage, 'This report is no longer available.');
    });
  });
}
