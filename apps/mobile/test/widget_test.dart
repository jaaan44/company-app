import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:mobile/app/app.dart';
import 'package:mobile/features/auth/data/auth_api_client.dart';
import 'package:mobile/features/auth/presentation/login_page.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';

import 'features/auth/fake_token_storage.dart';

void main() {
  testWidgets('an unauthenticated app boot shows the login screen', (
    tester,
  ) async {
    final controller = AuthController(
      apiClient: AuthApiClient(
        httpClient: MockClient((request) async {
          return http.Response(
            jsonEncode({'message': 'Unauthenticated.'}),
            401,
          );
        }),
      ),
      tokenStorage: FakeTokenStorage(),
    );

    await tester.pumpWidget(CompanyApp(authController: controller));
    await tester.pumpAndSettle();

    expect(find.text('Company App'), findsWidgets);
    expect(find.byType(LoginPage), findsOneWidget);
  });
}
