import 'package:flutter/widgets.dart';

import 'package:mobile/features/auth/state/auth_controller.dart';

/// Exposes the app's single [AuthController] instance (owned by
/// [CompanyApp]) to every widget the router builds beneath it — replacing
/// [AuthGate]'s former constructor-parameter coupling (`HomePage`'s old
/// `userName`/`onLogout` params) now that the router, not a single parent
/// widget, decides what's on screen. An [InheritedNotifier] rebuilds any
/// dependent automatically on every [AuthController] change, so no separate
/// `ListenableBuilder` is needed at each call site.
class AuthScope extends InheritedNotifier<AuthController> {
  const AuthScope({
    super.key,
    required AuthController controller,
    required super.child,
  }) : super(notifier: controller);

  static AuthController of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<AuthScope>();
    assert(scope != null, 'No AuthScope found in context');

    return scope!.notifier!;
  }
}
