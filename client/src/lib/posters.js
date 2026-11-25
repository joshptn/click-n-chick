import { api } from "./api";

/**
 * Homepage posters (decided 2026-11-26).
 *
 * The banner is one shape everywhere, 3:1, scaling with the screen, so a
 * poster is cropped once when it is added and shows exactly as cropped on the
 * web and in the app. The server checks the same rules again.
 */
export const POSTER_RATIO = 3;
export const POSTER_MIN_WIDTH = 1200;
export const POSTER_MIN_HEIGHT = 400;
export const POSTER_MAX_BYTES = 2 * 1024 * 1024;
export const POSTER_ACCEPT = "image/jpeg,image/png,image/webp";

// Twice the widest banner (about 1216px), for sharp screens; bigger crops are
// scaled down to this.
const OUTPUT_MAX_WIDTH = 2400;

export const ADMIN_POSTERS_KEY = ["admin-posters"];

export function fetchAdminPosters({ signal } = {}) {
  return api.get("/api/admin/posters", { signal });
}

/**
 * Add a poster (no `id`) or edit one. Only what is passed is sent: `expiresAt`
 * null clears the date, so the poster never expires.
 */
export function savePoster({ id, image, expiresAt, isActive }) {
  const form = new FormData();

  if (image) form.append("image", image);
  if (expiresAt !== undefined) form.append("expires_at", expiresAt ?? "");
  if (isActive !== undefined) form.append("is_active", isActive ? "1" : "0");

  return api.post(id ? `/api/admin/posters/${id}` : "/api/admin/posters", form);
}

export function deletePoster(id) {
  return api.delete(`/api/admin/posters/${id}`);
}

/** The first validation message from the server, or its general one. */
export function posterError(error) {
  const errors = error?.payload?.errors ?? {};
  const first = Object.values(errors)[0];

  return (Array.isArray(first) ? first[0] : null) ?? error?.message ?? "Something went wrong. Please try again.";
}

/** "2027-12-24" as the screen shows it: 12/24/2027. */
export function formatPosterDate(date) {
  if (!date) return "";

  const [year, month, day] = date.split("-");

  return `${month}/${day}/${year}`;
}

function loadImage(src) {
  return new Promise((resolve, reject) => {
    const image = new Image();

    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error("This file could not be read as an image."));
    image.src = src;
  });
}

function readAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();

    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error("This file could not be read."));
    reader.readAsDataURL(file);
  });
}

/**
 * Checks a chosen file before it is cropped: type, 2 MB, and big enough to
 * fill the banner. Resolves to the data: URL the cropper shows (a data: URL,
 * as the CSP's img-src allows it and has no reason to open up to blob:).
 */
export async function readPosterFile(file) {
  if (!POSTER_ACCEPT.split(",").includes(file.type)) {
    throw new Error("Choose a JPG, PNG or WebP image.");
  }

  if (file.size > POSTER_MAX_BYTES) {
    throw new Error("The image must be 2 MB or smaller.");
  }

  const url = await readAsDataUrl(file);
  const image = await loadImage(url);

  if (image.naturalWidth < POSTER_MIN_WIDTH || image.naturalHeight < POSTER_MIN_HEIGHT) {
    throw new Error(
      `The image is ${image.naturalWidth} × ${image.naturalHeight} pixels. It must be at least ${POSTER_MIN_WIDTH} × ${POSTER_MIN_HEIGHT} to fill the banner.`
    );
  }

  return { url, width: image.naturalWidth, height: image.naturalHeight };
}

/** The most the cropper may zoom in before the crop drops below the minimum size. */
export function posterMaxZoom(width, height) {
  return Math.max(1, Math.min(width, height * POSTER_RATIO) / POSTER_MIN_WIDTH);
}

function toBlob(canvas, quality) {
  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error("The poster could not be cropped."))), "image/jpeg", quality);
  });
}

/**
 * Cuts the chosen area out as a JPEG exactly 3:1 (a width divisible by 3),
 * between the minimum size and OUTPUT_MAX_WIDTH, kept under 2 MB.
 */
export async function cropPoster(src, area) {
  const image = await loadImage(src);
  const width = Math.min(OUTPUT_MAX_WIDTH, Math.max(POSTER_MIN_WIDTH, Math.floor(area.width / POSTER_RATIO) * POSTER_RATIO));
  const height = width / POSTER_RATIO;

  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;

  const context = canvas.getContext("2d");
  // A transparent PNG would turn black as a JPEG.
  context.fillStyle = "#ffffff";
  context.fillRect(0, 0, width, height);
  context.imageSmoothingQuality = "high";
  context.drawImage(image, area.x, area.y, area.width, area.height, 0, 0, width, height);

  let quality = 0.9;
  let blob = await toBlob(canvas, quality);

  while (blob.size > POSTER_MAX_BYTES && quality > 0.5) {
    quality -= 0.1;
    blob = await toBlob(canvas, quality);
  }

  return new File([blob], "poster.jpg", { type: "image/jpeg" });
}
