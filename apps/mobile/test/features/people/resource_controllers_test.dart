import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/people/state/my_profile_controller.dart';
import 'package:mobile/features/people/state/resource_controller.dart';
import 'package:mobile/features/people/state/staff_detail_controller.dart';

import '../../support/fake_backend.dart';
import '../../support/people_fixtures.dart';

/// Phase 28 Gate 2 — [MyProfileController] and [StaffDetailController]
/// (the shared [ResourceController] lifecycle) over the real `ApiClient`
/// (spec §7–§8): loading, loaded, no-profile, error, retry, refresh, and
/// no duplicate requests.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late int requestCount;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    requestCount = 0;
  });

  void answer(Future<http.Response> Function(http.Request) respond) {
    backend.onApi = (request) {
      requestCount++;

      return respond(request);
    };
  }

  group('MyProfileController', () {
    late MyProfileController profile;

    setUp(() {
      profile = MyProfileController(peopleClientFor(backend, auth));
      answer((_) async => jsonResponse({'data': profileDataJson()}));
    });

    tearDown(() => profile.dispose());

    test('starts loading, then exposes the profile', () async {
      expect(profile.status, ResourceStatus.loading);
      expect(requestCount, 0);

      await profile.load();

      expect(profile.status, ResourceStatus.loaded);
      expect(profile.data!.staff!.fullName, 'Ada Lovelace');
      expect(requestCount, 1);
    });

    test('no linked staff record is a loaded state, not an error', () async {
      answer((_) async => jsonResponse({'data': profileDataJson(staff: null)}));

      await profile.load();

      expect(profile.status, ResourceStatus.loaded);
      expect(profile.data!.hasStaffProfile, isFalse);
      expect(auth.status, AuthStatus.authenticated);
    });

    for (final (label, respond, message)
        in <(String, Future<http.Response> Function(http.Request), String)>[
          (
            'a network failure',
            (_) async => throw const NetworkFailure(),
            "Couldn't load your profile. Check your connection.",
          ),
          (
            'a server failure',
            (_) async => jsonError(500),
            'Something went wrong loading your profile.',
          ),
          (
            'an unexpected shape',
            (_) async => jsonResponse({'data': 'nope'}),
            'Something went wrong loading your profile.',
          ),
        ]) {
      test('$label is the error state with a safe message', () async {
        answer(respond);

        await profile.load();

        expect(profile.status, ResourceStatus.error);
        expect(profile.errorMessage, message);
        expect(auth.status, AuthStatus.authenticated);
      });
    }

    test('"Try again" after an error recovers', () async {
      answer((_) async => throw const NetworkFailure());
      await profile.load();
      expect(profile.status, ResourceStatus.error);

      answer((_) async => jsonResponse({'data': profileDataJson()}));
      await profile.load();

      expect(profile.status, ResourceStatus.loaded);
      expect(profile.errorMessage, isNull);
    });

    test(
      'a failed refresh keeps the earlier profile and reports false',
      () async {
        await profile.load();
        answer((_) async => throw const NetworkFailure());

        final ok = await profile.refresh();

        expect(ok, isFalse);
        expect(profile.status, ResourceStatus.loaded);
        expect(profile.data, isNotNull);
        expect(profile.isRefreshing, isFalse);
      },
    );

    test('a request in flight is joined, never duplicated', () async {
      final gate = Completer<http.Response>();
      answer((_) => gate.future);

      final first = profile.load();
      final second = profile.load();
      final third = profile.refresh();
      gate.complete(jsonResponse({'data': profileDataJson()}));
      await Future.wait([first, second, third]);

      expect(requestCount, 1);
      expect(profile.status, ResourceStatus.loaded);
    });

    test('401 ends the session and leaves no error state to show', () async {
      answer((_) async => jsonError(401));

      await profile.load();

      expect(auth.status, AuthStatus.unauthenticated);
      expect(profile.status, isNot(ResourceStatus.error));
    });

    test('notifies nothing after dispose', () async {
      final gate = Completer<http.Response>();
      answer((_) => gate.future);
      final local = MyProfileController(peopleClientFor(backend, auth));

      final pending = local.load();
      local.dispose();
      gate.complete(jsonResponse({'data': profileDataJson()}));

      await expectLater(pending, completes);
    });
  });

  group('StaffDetailController', () {
    test('loads the requested colleague', () async {
      final paths = <String>[];
      backend.onApi = (request) async {
        paths.add(request.url.path);

        return jsonResponse({
          'data': staffMemberJson(publicId: '01J0OTHER00000000000000000'),
        });
      };
      final detail = StaffDetailController(
        peopleClientFor(backend, auth),
        '01J0OTHER00000000000000000',
      );
      addTearDown(detail.dispose);

      await detail.load();

      expect(paths.single, '/api/v1/staff/01J0OTHER00000000000000000');
      expect(detail.data!.publicId, '01J0OTHER00000000000000000');
    });

    for (final (label, respond, message)
        in <(String, Future<http.Response> Function(http.Request), String)>[
          (
            'a 404',
            (_) async => jsonError(404, 'Not found.'),
            'This person is no longer in the directory.',
          ),
          (
            'a 403 with a valid session (no staff.view)',
            (_) async => jsonError(403, 'This action is unauthorized.'),
            "You don't have access to the staff directory.",
          ),
          (
            'a network failure',
            (_) async => throw const NetworkFailure(),
            "Couldn't load this person. Check your connection.",
          ),
        ]) {
      test('$label has a specific message and keeps the session', () async {
        answer(respond);
        final detail = StaffDetailController(
          peopleClientFor(backend, auth),
          'x',
        );
        addTearDown(detail.dispose);

        await detail.load();

        expect(detail.status, ResourceStatus.error);
        expect(detail.errorMessage, message);
        expect(auth.status, AuthStatus.authenticated);
      });
    }
  });
}
