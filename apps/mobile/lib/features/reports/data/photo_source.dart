import 'dart:typed_data';

import 'package:image_picker/image_picker.dart';

/// Where a photo comes from (R-7).
enum PhotoOrigin { camera, gallery }

/// A chosen photo, scaled and compressed by the picker.
class PickedPhoto {
  const PickedPhoto({required this.bytes, required this.filename});

  final Uint8List bytes;
  final String filename;
}

/// The app's only way to get a photo (Phase 33, R-8). [ImagePickerPhotoSource]
/// is the **only** file that imports `image_picker` (a test enforces it):
/// one `pickImage` — the system camera app or Android's photo picker, so
/// no app permission is needed.
abstract interface class PhotoSource {
  /// Null when the person cancelled.
  Future<PickedPhoto?> pick(PhotoOrigin origin);
}

class ImagePickerPhotoSource implements PhotoSource {
  ImagePickerPhotoSource([ImagePicker? picker])
    : _picker = picker ?? ImagePicker();

  /// R-7: at most 2560 px on the long side, JPEG quality 85.
  static const maxDimension = 2560.0;
  static const quality = 85;

  final ImagePicker _picker;

  @override
  Future<PickedPhoto?> pick(PhotoOrigin origin) async {
    final file = await _picker.pickImage(
      source: origin == PhotoOrigin.camera
          ? ImageSource.camera
          : ImageSource.gallery,
      maxWidth: maxDimension,
      maxHeight: maxDimension,
      imageQuality: quality,
      requestFullMetadata: false,
    );
    if (file == null) {
      return null;
    }

    return PickedPhoto(bytes: await file.readAsBytes(), filename: file.name);
  }
}
