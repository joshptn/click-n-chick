import { Alert } from "react-native";
import * as ImagePicker from "expo-image-picker";

import type { PickedFile } from "../api/account";

const MAX_BYTES = 5 * 1024 * 1024;

export class PickError extends Error {}

type PickOptions = {
  /** The server's accepted types, e.g. jpeg and png for a discount ID. */
  accept: string[];
  /** A square crop, for the profile photo. */
  square?: boolean;
};

const EXTENSION: Record<string, string> = { "image/jpeg": "jpg", "image/png": "png", "image/webp": "webp" };

/** "Take a photo" or "Choose from gallery", asked the way Android apps usually do. */
function askSource(title: string): Promise<"camera" | "library" | null> {
  return new Promise((resolve) => {
    Alert.alert(
      title,
      undefined,
      [
        { text: "Take a photo", onPress: () => resolve("camera") },
        { text: "Choose from gallery", onPress: () => resolve("library") },
        { text: "Cancel", style: "cancel", onPress: () => resolve(null) },
      ],
      { cancelable: true, onDismiss: () => resolve(null) }
    );
  });
}

/**
 * A photo from the camera or the gallery, checked before it is uploaded: the
 * type and the 5 MB limit mirror the API's rules (which stay the authority),
 * so a wrong file fails on the phone rather than after a slow upload.
 * Resolves null when the customer backs out.
 */
export async function pickImage(title: string, { accept, square = false }: PickOptions): Promise<PickedFile | null> {
  const source = await askSource(title);

  if (!source) return null;

  const permission =
    source === "camera"
      ? await ImagePicker.requestCameraPermissionsAsync()
      : await ImagePicker.requestMediaLibraryPermissionsAsync();

  if (!permission.granted) {
    throw new PickError(
      source === "camera"
        ? "Allow Click n Chick to use the camera in your phone's settings, or choose a photo from your gallery."
        : "Allow Click n Chick to see your photos in your phone's settings, or take a photo instead."
    );
  }

  const options: ImagePicker.ImagePickerOptions = {
    mediaTypes: ["images"],
    quality: 0.7,
    allowsEditing: square,
    aspect: square ? [1, 1] : undefined,
  };

  const result =
    source === "camera" ? await ImagePicker.launchCameraAsync(options) : await ImagePicker.launchImageLibraryAsync(options);

  const asset = result.canceled ? null : result.assets?.[0];

  if (!asset) return null;

  const type = asset.mimeType ?? guessType(asset.fileName ?? asset.uri);

  if (!type || !accept.includes(type)) {
    const names = accept.map((item) => `.${EXTENSION[item] ?? item.split("/")[1]}`).join(" or ");
    throw new PickError(`That file type is not supported. Use a ${names} photo.`);
  }

  if (asset.fileSize && asset.fileSize > MAX_BYTES) {
    throw new PickError(`That photo is ${(asset.fileSize / (1024 * 1024)).toFixed(1)} MB. The limit is 5 MB.`);
  }

  return { uri: asset.uri, type, name: asset.fileName ?? `photo.${EXTENSION[type] ?? "jpg"}` };
}

function guessType(name: string): string | null {
  const extension = name.split("?")[0].split(".").pop()?.toLowerCase();

  if (extension === "jpg" || extension === "jpeg") return "image/jpeg";
  if (extension === "png") return "image/png";
  if (extension === "webp") return "image/webp";

  return null;
}
