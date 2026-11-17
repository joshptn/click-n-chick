import { PixelRatio } from "react-native";

/**
 * Cloudinary delivery at the size an image is shown, as on the web
 * (client/src/lib/images.js).
 *
 * Dish photos and posters are uploaded at full resolution but shown at
 * 44–400 points. Cloudinary resizes on its CDN when the URL asks it to: `w_`
 * caps the width (`c_limit` never enlarges), `f_auto` serves WebP or AVIF,
 * `q_auto` picks the quality. Widths come from the web's list, so the same
 * photo at the same size is the same URL and comes out of the image cache.
 * Any other URL is returned unchanged.
 */
const CLOUDINARY_UPLOAD = /^(https:\/\/res\.cloudinary\.com\/[^/]+\/image\/upload\/)(.+)$/;

const IMAGE_WIDTHS = [96, 160, 320, 480, 640, 960, 1280, 1920];

export function cloudinaryUrl(url: string, width: number): string {
  const match = url.match(CLOUDINARY_UPLOAD);

  return match ? `${match[1]}f_auto,q_auto,c_limit,w_${width}/${match[2]}` : url;
}

/** The URL for an image drawn `points` wide on this screen, or null when there is none. */
export function sizedImage(url: string | null | undefined, points: number): string | null {
  if (!url) return null;

  const pixels = Math.ceil(points * PixelRatio.get());
  const width = IMAGE_WIDTHS.find((candidate) => candidate >= pixels) ?? IMAGE_WIDTHS[IMAGE_WIDTHS.length - 1];

  return cloudinaryUrl(url, width);
}
