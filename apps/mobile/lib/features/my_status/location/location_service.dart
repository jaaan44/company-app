import 'dart:async';

import 'package:geolocator/geolocator.dart' as geo;

import 'package:mobile/features/my_status/domain/my_status.dart';

/// Whether the app may read the device location (R-18: while in use only;
/// the app never asks for background access).
enum LocationPermissionState {
  granted,

  /// Not granted; asking again shows Android's prompt.
  denied,

  /// Not granted, and Android won't ask again: only the app's settings
  /// can change it.
  deniedForever,
}

/// Location services are turned off on the device.
class LocationServiceOffException implements Exception {
  const LocationServiceOffException();
}

/// The permission was withdrawn between the check and the read.
class LocationPermissionException implements Exception {
  const LocationPermissionException();
}

/// No position arrived in time.
class LocationTimeoutException implements Exception {
  const LocationTimeoutException();
}

/// Everything the check-in flow needs from the device (Phase 30B,
/// docs/phases/V1_PHASE_30_DEFINITION.md §6.4, R-17…R-20). One position,
/// on request, while the app is in use — **no stream, no background
/// service, nothing periodic** (DEC-005). Tests use a fake; only
/// [GeolocatorLocationService] touches the plugin.
abstract interface class LocationService {
  Future<bool> serviceEnabled();

  Future<LocationPermissionState> permission();

  /// Shows Android's prompt when it still can.
  Future<LocationPermissionState> requestPermission();

  /// One position within [timeout]. Throws [LocationServiceOffException],
  /// [LocationPermissionException] or [LocationTimeoutException].
  Future<LocationFix> currentPosition({required Duration timeout});

  Future<bool> openAppSettings();

  Future<bool> openLocationSettings();
}

/// [LocationService] on `geolocator` (R-17). The only file in the app that
/// imports it.
class GeolocatorLocationService implements LocationService {
  GeolocatorLocationService({DateTime Function()? clock})
    : _clock = clock ?? DateTime.now;

  final DateTime Function() _clock;

  @override
  Future<bool> serviceEnabled() => geo.Geolocator.isLocationServiceEnabled();

  @override
  Future<LocationPermissionState> permission() async =>
      _map(await geo.Geolocator.checkPermission());

  @override
  Future<LocationPermissionState> requestPermission() async =>
      _map(await geo.Geolocator.requestPermission());

  @override
  Future<LocationFix> currentPosition({required Duration timeout}) async {
    try {
      final position = await geo.Geolocator.getCurrentPosition(
        locationSettings: geo.LocationSettings(
          accuracy: geo.LocationAccuracy.high,
          timeLimit: timeout,
        ),
      );
      final accuracy = await geo.Geolocator.getLocationAccuracy();

      return LocationFix(
        latitude: position.latitude,
        longitude: position.longitude,
        accuracyMeters: position.accuracy,
        isApproximate: accuracy == geo.LocationAccuracyStatus.reduced,
        obtainedAt: _clock(),
      );
    } on TimeoutException {
      throw const LocationTimeoutException();
    } on geo.LocationServiceDisabledException {
      throw const LocationServiceOffException();
    } on geo.PermissionDeniedException {
      throw const LocationPermissionException();
    }
  }

  @override
  Future<bool> openAppSettings() => geo.Geolocator.openAppSettings();

  @override
  Future<bool> openLocationSettings() => geo.Geolocator.openLocationSettings();

  static LocationPermissionState _map(geo.LocationPermission permission) =>
      switch (permission) {
        geo.LocationPermission.whileInUse ||
        geo.LocationPermission.always => LocationPermissionState.granted,
        geo.LocationPermission.deniedForever =>
          LocationPermissionState.deniedForever,
        _ => LocationPermissionState.denied,
      };
}
