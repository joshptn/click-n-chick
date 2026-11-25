import { useEffect, useRef, useState } from "react";
import { Modal, Slider } from "@mantine/core";
import Cropper from "react-easy-crop";
import { IconCalendarEvent, IconPhotoUp, IconZoomIn, IconZoomOut } from "@tabler/icons-react";

import Button from "../ui/Button";
import CalendarPicker from "../ui/CalendarPicker";
import Field from "../ui/Field";
import PickerField from "../ui/PickerField";
import { storeDate } from "../../lib/records";
import { formatCalendarDate } from "../../lib/timeWindow";
import {
  POSTER_ACCEPT,
  POSTER_MIN_HEIGHT,
  POSTER_MIN_WIDTH,
  POSTER_RATIO,
  cropPoster,
  posterError,
  posterMaxZoom,
  readPosterFile,
  savePoster,
} from "../../lib/posters";

/** "Thu, Dec 24, 2027": the year too, as an expiry can be any year ahead. */
const formatExpiry = (date) => `${formatCalendarDate(date)}, ${date.slice(0, 4)}`;

/**
 * Add a poster, or edit one: its image and its expiry date.
 *
 * A new image is cropped here to the banner's 3:1 shape, by dragging and
 * zooming; zooming stops before the crop would drop below the banner's size.
 * Only the cropped image is uploaded. The expiry date is optional (empty: it
 * never expires), picked on the app's CalendarPicker, where days before today
 * are greyed out.
 */
function PosterFormModal({ opened, onClose, poster = null, onSaved }) {
  const editing = Boolean(poster);
  const inputRef = useRef(null);
  const [source, setSource] = useState(null);
  const [crop, setCrop] = useState({ x: 0, y: 0 });
  const [zoom, setZoom] = useState(1);
  const [area, setArea] = useState(null);
  const [expiresAt, setExpiresAt] = useState("");
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [calendarOpen, setCalendarOpen] = useState(false);
  const today = storeDate();

  useEffect(() => {
    if (!opened) return;

    setSource(null);
    setCrop({ x: 0, y: 0 });
    setZoom(1);
    setArea(null);
    setExpiresAt(poster?.expires_at ?? "");
    setError(null);
    setCalendarOpen(false);
  }, [opened, poster]);

  const choose = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = "";

    if (!file) return;

    try {
      const read = await readPosterFile(file);

      setSource(read);
      setCrop({ x: 0, y: 0 });
      setZoom(1);
      setError(null);
    } catch (failure) {
      setError(failure.message);
    }
  };

  const dateChanged = (expiresAt || null) !== (poster?.expires_at ?? null);
  const canSave = editing ? Boolean(source) || dateChanged : Boolean(source);
  const maxZoom = source ? posterMaxZoom(source.width, source.height) : 1;

  const submit = async (event) => {
    event.preventDefault();

    if (!canSave || busy) return;

    if (dateChanged && expiresAt && expiresAt < today) {
      setError("The expiry date cannot be in the past.");

      return;
    }

    setBusy(true);
    setError(null);

    try {
      const image = source && area ? await cropPoster(source.url, area) : undefined;
      const response = await savePoster({
        id: poster?.id,
        image,
        expiresAt: !editing || dateChanged ? expiresAt || null : undefined,
      });

      onSaved?.(response.data);
      onClose();
    } catch (failure) {
      setError(posterError(failure));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      opened={opened}
      onClose={busy ? () => {} : onClose}
      centered
      size="lg"
      radius={16}
      title={
        <span className="block">
          <span className="block font-display text-[17px] font-bold text-cocoa-900">{editing ? `Edit Poster #${poster.id}` : "Add Poster"}</span>
          <span className="mt-0.5 block font-display text-[12px] font-normal leading-snug text-[#8d8884]">
            The homepage banner is 3:1 on every screen. Crop the image to the part customers should see.
          </span>
        </span>
      }
      styles={{ header: { borderBottom: "1px solid #f3ede4", paddingBottom: 12 } }}
    >
      <form onSubmit={submit} noValidate className="flex flex-col gap-4 pt-3" data-testid="poster-form">
        <input ref={inputRef} type="file" accept={POSTER_ACCEPT} onChange={choose} className="hidden" data-testid="poster-file" />

        {source ? (
          <div className="flex flex-col gap-2.5">
            <div className="relative h-[240px] w-full overflow-hidden rounded-[12px] bg-[#2b150a] sm:h-[300px]" data-testid="poster-cropper">
              <Cropper
                image={source.url}
                crop={crop}
                zoom={zoom}
                minZoom={1}
                maxZoom={maxZoom}
                aspect={POSTER_RATIO}
                onCropChange={setCrop}
                onZoomChange={setZoom}
                onCropComplete={(_, pixels) => setArea(pixels)}
                showGrid
              />
            </div>

            <div className="flex items-center gap-3">
              <IconZoomOut size={17} stroke={1.8} className="shrink-0 text-[#a39f9b]" aria-hidden="true" />
              <Slider
                value={zoom}
                onChange={setZoom}
                min={1}
                max={maxZoom}
                step={0.01}
                disabled={maxZoom <= 1}
                label={null}
                color="orange"
                className="flex-1"
                aria-label="Zoom"
              />
              <IconZoomIn size={17} stroke={1.8} className="shrink-0 text-[#a39f9b]" aria-hidden="true" />
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="m-0 font-display text-[11.5px] text-[#8d8884]">
                Drag to move the image. {maxZoom <= 1 ? "This image is too small to zoom in." : "Zoom stops before it would turn blurry."}
              </p>
              <Button variant="ghost" size="sm" onClick={() => inputRef.current?.click()} disabled={busy}>
                Choose another image
              </Button>
            </div>
          </div>
        ) : editing ? (
          <div className="flex flex-col gap-2.5">
            <img src={poster.image} alt={`Poster #${poster.id}`} className="aspect-[3/1] w-full rounded-[12px] object-cover" />
            <div className="flex justify-end">
              <Button variant="outline" size="sm" onClick={() => inputRef.current?.click()} data-testid="poster-replace">
                <IconPhotoUp size={16} stroke={2} />
                Replace Image
              </Button>
            </div>
          </div>
        ) : (
          <button
            type="button"
            onClick={() => inputRef.current?.click()}
            className="flex aspect-[3/1] w-full flex-col items-center justify-center gap-1.5 rounded-[12px] border-2 border-dashed border-[#e4ddd3] bg-[#faf7f3] px-4 text-center transition-colors hover:border-brand-400"
            data-testid="poster-choose"
          >
            <IconPhotoUp size={28} stroke={1.6} className="text-brand-500" aria-hidden="true" />
            <span className="font-display text-[13.5px] font-semibold text-ink">Choose an image</span>
            <span className="font-display text-[11.5px] text-[#8d8884]">
              JPG, PNG or WebP, up to 2 MB, at least {POSTER_MIN_WIDTH} × {POSTER_MIN_HEIGHT} pixels
            </span>
          </button>
        )}

        <Field
          label="Expiry date (optional)"
          htmlFor="poster-expires"
          hint={expiresAt ? "It shows until the end of this day." : "No date: the poster never expires."}
        >
          <div className="flex items-center gap-2" data-testid="poster-expires">
            <PickerField
              id="poster-expires"
              icon={IconCalendarEvent}
              value={expiresAt ? formatExpiry(expiresAt) : ""}
              placeholder="Never expires"
              onOpen={() => setCalendarOpen(true)}
              ariaLabel={expiresAt ? `Expiry date ${formatExpiry(expiresAt)}. Change` : "Choose an expiry date"}
            />
            {expiresAt && (
              <Button variant="ghost" size="sm" onClick={() => setExpiresAt("")} data-testid="poster-expires-clear">
                Clear
              </Button>
            )}
          </div>
        </Field>

        {error && (
          <p className="m-0 rounded-[10px] bg-[#fdecec] px-3 py-2 font-display text-[12px] text-[#c4302b]" role="alert" data-testid="poster-error">
            {error}
          </p>
        )}

        <CalendarPicker
          opened={calendarOpen}
          onClose={() => setCalendarOpen(false)}
          value={expiresAt}
          min={today}
          title="Select expiry date"
          earlyMessage={(first) => `An expiry date cannot be in the past. The earliest is ${first}.`}
          onConfirm={(date) => {
            setExpiresAt(date);
            setError(null);
            setCalendarOpen(false);
          }}
        />

        <div className="grid grid-cols-2 gap-2.5">
          <Button variant="outline" onClick={onClose} disabled={busy} className="!border-[#e4ddd3] !text-[#5f5b58]">
            Cancel
          </Button>
          <Button type="submit" loading={busy} disabled={!canSave} data-testid="poster-submit">
            {editing ? "Save Poster" : "Add Poster"}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

export default PosterFormModal;
