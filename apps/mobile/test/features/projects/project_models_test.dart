import 'package:flutter_test/flutter_test.dart';

import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/features/clients/domain/client.dart';
import 'package:mobile/features/projects/domain/project.dart';

import '../../support/project_fixtures.dart';

/// Phase 29C Gate 2 — strict parsing of the project and client resources
/// (spec §7.4): every field, nulls kept as null, unknown enums and bad
/// dates rejected, notes ignored (R-24).
void main() {
  group('Project', () {
    test('parses every field the app uses', () {
      final p = Project.fromJson(projectJson(myRole: 'project_lead'));

      expect(p.publicId, '01J0PROJ000000000000000AAA');
      expect(p.name, 'Boiler Upgrade');
      expect(p.projectCode, 'PRJ-001');
      expect(p.status, ProjectStatus.active);
      expect(p.description, 'Replace the plant-room boiler.');
      expect(p.startDate, '2026-09-01');
      expect(p.targetEndDate, '2026-12-15');
      expect(p.completedDate, isNull);
      expect(p.client!.name, 'Acme Corp');
      expect(p.myRole, ProjectRole.projectLead);
      expect(p.iAmLead, isTrue);
      expect(p.membersCount, 3);
    });

    test('nullable fields stay null', () {
      final p = Project.fromJson(
        projectJson(
          projectCode: null,
          description: null,
          startDate: null,
          targetEndDate: null,
          client: null,
          myRole: null,
          membersCount: null,
        ),
      );

      expect(p.projectCode, isNull);
      expect(p.description, isNull);
      expect(p.startDate, isNull);
      expect(p.client, isNull);
      expect(p.myRole, isNull);
      expect(p.iAmLead, isFalse);
      expect(p.membersCount, isNull);
    });

    test('every status parses, with its label and closed flag', () {
      final parsed = {
        for (final s in [
          'planned',
          'active',
          'on_hold',
          'completed',
          'cancelled',
        ])
          s: Project.fromJson(projectJson(status: s)).status,
      };

      expect(parsed['on_hold']!.label, 'On hold');
      expect(parsed.values.where((s) => s.isClosed), [
        ProjectStatus.completed,
        ProjectStatus.cancelled,
      ]);
    });

    test('an unknown status or role, a bad date or a missing name is a '
        'FormatException', () {
      for (final json in [
        projectJson(status: 'archived'),
        projectJson(myRole: 'owner'),
        projectJson(startDate: '01/09/2026'),
        {...projectJson(), 'name': null},
      ]) {
        expect(() => Project.fromJson(json), throwsFormatException);
      }
    });
  });

  group('members and milestones', () {
    test('a member parses its staff reference and role', () {
      final m = ProjectMember.fromJson(
        memberJson(displayName: 'Grace Hopper', role: 'project_lead'),
      );

      expect(m.staffPublicId, '01J0STAFF000000000000000AA');
      expect(m.displayName, 'Grace Hopper');
      expect(m.employeeNumber, 'EMP-1001');
      expect(m.role, ProjectRole.projectLead);
      expect(m.role.label, 'Project lead');
    });

    test('leadsFirst moves leads to the top, keeping each group in order', () {
      final members = [
        for (final (name, role) in [
          ('A', 'member'),
          ('B', 'project_lead'),
          ('C', 'member'),
          ('D', 'project_lead'),
        ])
          ProjectMember.fromJson(memberJson(displayName: name, role: role)),
      ];

      expect(leadsFirst(members).map((m) => m.displayName), [
        'B',
        'D',
        'A',
        'C',
      ]);
    });

    test('a milestone parses; an unknown status or missing due date fails', () {
      final m = ProjectMilestone.fromJson(milestoneJson(status: 'completed'));

      expect(m.title, 'Design sign-off');
      expect(m.dueDate, '2026-10-20');
      expect(m.status, MilestoneStatus.completed);
      expect(
        () => ProjectMilestone.fromJson(milestoneJson(status: 'late')),
        throwsFormatException,
      );
      expect(
        () => ProjectMilestone.fromJson({...milestoneJson(), 'due_date': null}),
        throwsFormatException,
      );
    });
  });

  group('Client and ClientContact', () {
    test('a client parses every contact field', () {
      final c = Client.fromJson(clientJson());

      expect(c.name, 'Acme Corp');
      expect(c.clientCode, 'CL-001');
      expect(c.status, ClientStatus.active);
      expect(c.isInactive, isFalse);
      expect(c.email, 'office@acme.test');
      expect(c.phone, '+63 2 8000 1234');
      expect(c.website, 'https://acme.test');
      expect(c.addressLines, [
        '12 Harbor Road',
        'Makati, Metro Manila 1200',
        'Philippines',
      ]);
    });

    test('blank address parts are dropped; no parts is an empty list', () {
      expect(
        Client.fromJson(
          clientJson(city: null, stateProvince: '  ', postalCode: '1200'),
        ).addressLines,
        ['12 Harbor Road', '1200', 'Philippines'],
      );
      expect(
        Client.fromJson(
          clientJson(
            addressLine1: null,
            city: null,
            stateProvince: null,
            postalCode: null,
            country: null,
          ),
        ).addressLines,
        isEmpty,
      );
    });

    test('an inactive client is flagged; an unknown status fails', () {
      expect(
        Client.fromJson(clientJson(status: 'inactive')).isInactive,
        isTrue,
      );
      expect(
        () => Client.fromJson(clientJson(status: 'archived')),
        throwsFormatException,
      );
    });

    test('a contact parses; nullable fields stay null', () {
      final c = ClientContact.fromJson(
        contactJson(isPrimary: true, jobTitle: null, phone: null),
      );

      expect(c.fullName, 'Maria Santos');
      expect(c.isPrimary, isTrue);
      expect(c.jobTitle, isNull);
      expect(c.email, 'maria@acme.test');
      expect(c.phone, isNull);
      expect(
        () => ClientContact.fromJson({...contactJson(), 'is_primary': 'yes'}),
        throwsFormatException,
      );
    });
  });

  group('PagedResult', () {
    test('parses items, pages and total', () {
      final page = PagedResult.fromJson(
        pageJson(projectList(2), page: 1, lastPage: 3, total: 55),
        Project.fromJson,
      );

      expect(page.items.map((p) => p.name), ['P 0', 'P 1']);
      expect(page.hasMore, isTrue);
      expect(page.total, 55);
    });

    test('a missing meta is a FormatException', () {
      expect(
        () => PagedResult.fromJson({'data': <Object>[]}, Project.fromJson),
        throwsFormatException,
      );
    });
  });
}
