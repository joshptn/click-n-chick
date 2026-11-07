/**
 * Cloudinary delivery at the size an image is shown.
 *
 * Dish photos and posters are uploaded at full resolution (1200px and up) but
 * shown at 44-420px. Cloudinary resizes on its CDN when the URL asks it to:
 * `w_` caps the width (`c_limit` never enlarges), `f_auto` serves WebP or AVIF
 * to browsers that take them, `q_auto` picks the quality.
 *
 * Every component picks from the same widths, so the same photo at the same
 * size is the same URL everywhere and comes out of the browser cache. A URL
 * that is not a Cloudinary upload (a seeded placeholder, a data: preview) is
 * returned as it is.
 */
const CLOUDINARY_UPLOAD = /^(https:\/\/res\.cloudinary\.com\/[^/]+\/image\/upload\/)(.+)$/;

export const IMAGE_WIDTHS = [96, 160, 320, 480, 640, 960, 1280, 1920];

export function isCloudinary(url) {
  return typeof url === "string" && CLOUDINARY_UPLOAD.test(url);
}

/** The image at `width` pixels wide, or the URL unchanged when it cannot be resized. */
export function cloudinaryUrl(url, width) {
  const match = typeof url === "string" ? url.match(CLOUDINARY_UPLOAD) : null;

  if (!match) return url;

  return `${match[1]}f_auto,q_auto,c_limit,w_${width}/${match[2]}`;
}

/** A small square thumbnail shown `px` wide (cart lines, staff lists). */
export function thumbnailImage(url, px) {
  return responsiveImage(url, { widths: px > 64 ? [160, 320] : [96, 160], sizes: `${px}px`, fallback: px > 64 ? 160 : 96 });
}

/**
 * `src`, `srcSet` and `sizes` for an <img>: the browser picks the smallest
 * listed width that covers `sizes` at the screen's pixel density. `fallback`
 * is the width for browsers without srcset.
 */
export function responsiveImage(url, { widths, sizes, fallback }) {
  if (!isCloudinary(url)) return { src: url };

  return {
    src: cloudinaryUrl(url, fallback ?? widths[Math.floor(widths.length / 2)]),
    srcSet: widths.map((width) => `${cloudinaryUrl(url, width)} ${width}w`).join(", "),
    sizes,
  };
}
