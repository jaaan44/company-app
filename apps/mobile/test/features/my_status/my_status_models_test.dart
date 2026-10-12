import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:mobile/features/my_status/domain/my_status.dart';

import '../../support/my_status_fixtures.dart';

/// Phase 30B Gate 2 — the My status models (spec §6.4): strict structure,
/// lenient status values, and exactly what a check-in sends (R-19).
void main() {
  group('OperationalStatus', () {
    test('maps the five wire values and labels; unknown values are kept', () {
      expect(
        {for (final s in OperationalStatus.choices) s.wireValue: s.label},
        {
          'available': 'Available',
          'busy': 'Busy',
          'in_meeting': 'In a meeting',
          'in_field': 'In the field',
          'off_duty': 'Off duty',
        },
      );
      expect(OperationalStatus.fromWire('on_break'), OperationalStatus.unknown);
      expect(OperationalStatus.unknown.label, 'Unknown status');
      expect(
        OperationalStatus.choices,
        isNot(contains(OperationalStatus.unknown)),
      );
      expect(() => OperationalStatus.fromWire(null), throwsFormatException);
      expect(() => OperationalStatus.fromWire(3), throwsFormatException);
    });

    test('only Off duty carries the "not leave" hint (R-16)', () {
      expect(
        OperationalStatus.offDuty.hint,
        "Not working right now. This isn't leave.",
      );
      for (final s in OperationalStatus.values) {
        if (s != OperationalStatus.offDuty) {
          expect(s.hint, isNull, reason: s.name);
        }
      }
    });
  });

  group('StatusEntry and CheckIn', () {
    test('a status entry parses its time as UTC', () {
      final e = StatusEntry.fromJson(
        statusJson('in_field', recordedAt: '2026-10-14T09:00:00+08:00'),
      );
      expect(e.status, OperationalStatus.inField);
      expect(e.recordedAt, DateTime.utc(2026, 10, 14, 1));
      expect(e.recordedAt.isUtc, isTrue);
      expect(
        () => StatusEntry.fromJson({'status': 'busy'}),
        throwsFormatException,
      );
    });

    test('a check-in parses every field, integers and nulls included', () {
      final c = CheckIn.fromJson(checkInJson());
      expect(c.publicId, '01J0CHECKIN000000000000AAA');
      expect(c.latitude, 14.5547);
      expect(c.longitude, 121.0244);
      expect(c.accuracyMeters, 12);
      expect(c.locationLabel, 'Client site');
      expect(c.note, 'Gate B');
      expect(c.checkedInAt, DateTime.utc(2026, 10, 14, 2, 30));

      final bare = CheckIn.fromJson(
        checkInJson(
          latitude: 14,
          longitude: 121,
          accuracy: null,
          label: null,
          note: null,
        ),
      );
      expect(bare.latitude, 14.0);
      expect(bare.longitude, 121.0);
      expect(bare.accuracyMeters, isNull);
      expect(bare.locationLabel, isNull);
      expect(bare.note, isNull);
    });

    test('a check-in missing its id, coordinates or time is rejected', () {
      for (final key in [
        'public_id',
        'latitude',
        'longitude',
        'checked_in_at',
      ]) {
        expect(
          () => CheckIn.fromJson(checkInJson()..remove(key)),
          throwsFormatException,
          reason: key,
        );
      }
      expect(
        () => CheckIn.fromJson(checkInJson(latitude: '14.5')),
        throwsFormatException,
      );
    });
  });

  group('MyHistoryPage', () {
    test('reads the items, paging and company day', () {
      final page = MyHistoryPage.fromJson(
        historyBody(
          [statusJson('busy'), statusJson('available')],
          page: 1,
          lastPage: 3,
          total: 41,
        ),
        StatusEntry.fromJson,
      );
      expect(page.page.items.map((e) => e.status), [
        OperationalStatus.busy,
        OperationalStatus.available,
      ]);
      expect(page.page.hasMore, isTrue);
      expect(page.page.total, 41);
      expect(page.companyDay.date, '2026-10-14');
      expect(page.companyDay.utcOffset, const Duration(hours: 8));
    });

    test('a page without its company day is rejected', () {
      expect(
        () => MyHistoryPage.fromJson(
          historyBody([], companyDay: null),
          StatusEntry.fromJson,
        ),
        throwsFormatException,
      );
    });
  });

  group('CheckInDraft (R-19)', () {
    test('sends the fix, rounded accuracy, trimmed text and no status', () {
      final draft = CheckInDraft(
        fix: fixAt(DateTime(2026, 10, 14)),
        locationLabel: '  Client site ',
        note: ' Gate B\n',
      );
      expect(draft.toJson(), {
        'latitude': 14.5547123,
        'longitude': 121.0244456,
        'accuracy_meters': 9,
        'location_label': 'Client site',
        'note': 'Gate B',
      });
      expect(draft.toJson().containsKey('status'), isFalse);
    });

    test('blank text is sent as null; accuracy stays within the API range', () {
      final blank = CheckInDraft(
        fix: fixAt(DateTime(2026, 10, 14), accuracy: 250000),
        locationLabel: '   ',
      ).toJson();
      expect(blank['location_label'], isNull);
      expect(blank['note'], isNull);
      expect(blank['accuracy_meters'], CheckInLimits.maxAccuracyMeters);

      expect(
        CheckInDraft(fix: fixAt(DateTime(2026, 10, 14), accuracy: 0.4))
            .toJson()['accuracy_meters'],
        0,
      );
    });

    test('the limits mirror the API', () {
      expect(CheckInLimits.maxLabelLength, 100);
      expect(CheckInLimits.maxNoteLength, 500);
      expect(CheckInLimits.maxAccuracyMeters, 100000);
    });
  });

  test('only the location service imports geolocator (R-17)', () {
    final importers = [
      for (final f in Directory('lib').listSync(recursive: true))
        if (f is File &&
            f.path.endsWith('.dart') &&
            f.readAsStringSync().contains('package:geolocator'))
          f.path.replaceAll(r'\', '/'),
    ];
    expect(importers, [
      'lib/features/my_status/location/location_service.dart',
    ]);
  });
}
