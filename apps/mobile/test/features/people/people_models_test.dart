import 'package:flutter_test/flutter_test.dart';

import 'package:mobile/features/people/domain/my_profile.dart';
import 'package:mobile/features/people/domain/staff_directory_page.dart';
import 'package:mobile/features/people/domain/staff_member.dart';

import '../../support/people_fixtures.dart';

/// Phase 28 Gate 2 — strict parsing of the People contracts (spec §7).
void main() {
  group('StaffMember', () {
    test('parses every field the People screens use', () {
      final m = StaffMember.fromJson(staffMemberJson(preferredName: 'Ada L.'));

      expect(m.publicId, '01J0STAFF000000000000000AA');
      expect(m.firstName, 'Ada');
      expect(m.lastName, 'Lovelace');
      expect(m.preferredName, 'Ada L.');
      expect(m.displayName, 'Ada L.');
      expect(m.fullName, 'Ada Lovelace');
      expect(m.hasDistinctFullName, isTrue);
      expect(m.employeeNumber, 'EMP-1001');
      expect(m.companyEmail, 'ada@company.test');
      expect(m.companyPhone, '+63 2 8123 4567');
      expect(m.hireDate, '2024-03-01');
      expect(m.status, 'active');
      expect(m.position!.name, 'Field Technician');
      expect(m.department!.name, 'Operations');
      expect(m.team!.name, 'Field Team A');
      expect(m.manager!.publicId, '01J0MGR00000000000000000AA');
      expect(m.manager!.name, 'Grace Hopper');
    });

    test('without a preferred name the full name is not repeated', () {
      final m = StaffMember.fromJson(staffMemberJson());

      expect(m.displayName, 'Ada Lovelace');
      expect(m.hasDistinctFullName, isFalse);
    });

    test('missing placement and manager stay null, never placeholders', () {
      final m = StaffMember.fromJson(
        staffMemberJson(
          position: null,
          department: null,
          team: null,
          manager: null,
        ),
      );

      expect(m.position, isNull);
      expect(m.department, isNull);
      expect(m.team, isNull);
      expect(m.manager, isNull);
      expect(m.roleLine, isNull);
    });

    test('roleLine joins position and department, skipping a missing one', () {
      expect(
        StaffMember.fromJson(staffMemberJson()).roleLine,
        'Field Technician · Operations',
      );
      expect(
        StaffMember.fromJson(staffMemberJson(department: null)).roleLine,
        'Field Technician',
      );
      expect(
        StaffMember.fromJson(staffMemberJson(position: null)).roleLine,
        'Operations',
      );
    });

    test('a missing required field is a FormatException', () {
      final json = staffMemberJson()..remove('public_id');

      expect(() => StaffMember.fromJson(json), throwsFormatException);
    });

    test('a wrongly typed organization reference is a FormatException', () {
      expect(
        () => StaffMember.fromJson(staffMemberJson(position: 'Technician')),
        throwsFormatException,
      );
      expect(
        () => StaffMember.fromJson(
          staffMemberJson(manager: {'public_id': 'x', 'name': 'no display'}),
        ),
        throwsFormatException,
      );
    });
  });

  group('MyProfile', () {
    test('parses the account and the own staff record', () {
      final p = MyProfile.fromJson(profileDataJson());

      expect(p.user.name, 'Ada Lovelace');
      expect(p.user.email, 'ada@example.com');
      expect(p.user.roleLabel, 'Staff');
      expect(p.hasStaffProfile, isTrue);
      expect(p.staff!.employeeNumber, 'EMP-1001');
    });

    test('a null staff record is a valid no-profile state', () {
      final p = MyProfile.fromJson(
        profileDataJson(staff: null, role: 'administrator'),
      );

      expect(p.hasStaffProfile, isFalse);
      expect(p.staff, isNull);
      expect(p.user.roleLabel, 'Administrator');
    });

    test('roles map to their on-screen labels; no role is null', () {
      expect(
        MyProfile.fromJson(profileDataJson(role: 'manager')).user.roleLabel,
        'Manager',
      );
      expect(
        MyProfile.fromJson(profileDataJson(role: null)).user.roleLabel,
        isNull,
      );
    });

    test('a malformed staff record is a FormatException', () {
      expect(
        () => MyProfile.fromJson(profileDataJson(staff: {'public_id': 1})),
        throwsFormatException,
      );
    });

    test('a missing user block is a FormatException', () {
      expect(() => MyProfile.fromJson({'staff': null}), throwsFormatException);
    });
  });

  group('StaffDirectoryPage', () {
    test('parses items and paging; hasMore while pages remain', () {
      final page = StaffDirectoryPage.fromJson(
        directoryPageJson(staffList(2), page: 1, lastPage: 3),
      );

      expect(page.items, hasLength(2));
      expect(page.currentPage, 1);
      expect(page.hasMore, isTrue);
    });

    test('the last page has no more', () {
      expect(
        StaffDirectoryPage.fromJson(
          directoryPageJson(staffList(1), page: 3, lastPage: 3),
        ).hasMore,
        isFalse,
      );
    });

    test('an empty directory is a valid page', () {
      final page = StaffDirectoryPage.fromJson(directoryPageJson([]));

      expect(page.items, isEmpty);
      expect(page.hasMore, isFalse);
    });

    test('missing meta is a FormatException', () {
      expect(
        () => StaffDirectoryPage.fromJson({'data': <dynamic>[]}),
        throwsFormatException,
      );
    });
  });
}
