import 'package:flutter_test/flutter_test.dart';

import 'package:mobile/app/app.dart';

void main() {
  testWidgets('bootstrap shell renders the Company App title', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(const CompanyApp());

    expect(find.text('Company App'), findsOneWidget);
    expect(find.text('Company App — bootstrap shell'), findsOneWidget);
  });
}
