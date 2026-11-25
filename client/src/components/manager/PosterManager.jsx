import { useEffect, useState } from "react";
import { Skeleton, Switch } from "@mantine/core";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { IconChevronLeft, IconChevronRight, IconPencil, IconPhoto, IconPlus, IconRefresh, IconTrash } from "@tabler/icons-react";

import Button from "../ui/Button";
import toast from "../app/Toast";
import PosterFormModal from "./PosterFormModal";
import { responsiveImage } from "../../lib/images";
import { ADMIN_POSTERS_KEY, deletePoster, fetchAdminPosters, formatPosterDate, posterError, savePoster } from "../../lib/posters";

const FRAME = "relative aspect-[3/1] w-full overflow-hidden rounded-[16px]";

function AddPosterButton({ onClick }) {
  return (
    <Button size="sm" onClick={onClick} data-testid="poster-add">
      <IconPlus size={16} stroke={2.2} />
      Add Poster
    </Button>
  );
}

function ExpiryPill({ poster, onEdit, onDelete }) {
  const label = poster.is_expired
    ? `Expired ${formatPosterDate(poster.expires_at)}`
    : poster.expires_at
      ? `Expires at: ${formatPosterDate(poster.expires_at)}`
      : "Never expires";

  return (
    <div className="flex h-[34px] min-w-0 items-center gap-1 rounded-[9px] bg-white pl-3 pr-1 shadow-sm">
      <span
        className={`truncate font-display text-[12px] font-semibold ${poster.is_expired ? "text-[#c4302b]" : "text-ink"}`}
        data-testid="poster-expiry"
      >
        {label}
      </span>
      <button
        type="button"
        onClick={onEdit}
        aria-label={`Edit poster #${poster.id}`}
        data-testid="poster-edit"
        className="ml-1 grid h-7 w-7 shrink-0 place-items-center rounded-[7px] text-brand-600 transition-colors hover:bg-brand-50"
      >
        <IconPencil size={15} stroke={2} />
      </button>
      <button
        type="button"
        onClick={onDelete}
        aria-label={`Delete poster #${poster.id}`}
        data-testid="poster-delete"
        className="grid h-7 w-7 shrink-0 place-items-center rounded-[7px] text-[#e5322d] transition-colors hover:bg-[#fdecec]"
      >
        <IconTrash size={15} stroke={2} />
      </button>
    </div>
  );
}

/**
 * The homepage posters, managed in Store Settings (decided 2026-11-26).
 *
 * Every poster, one at a time, in the banner's own 3:1 shape, so it reads as
 * the homepage will show it. On it: the On Display switch, the poster's ID,
 * its expiry with edit and delete, and Add Poster. A poster switched off or
 * past its date is dimmed, as customers do not see it. Nothing here notifies
 * anyone.
 */
function PosterManager({ confirm }) {
  const queryClient = useQueryClient();
  const query = useQuery({ queryKey: ADMIN_POSTERS_KEY, queryFn: fetchAdminPosters });
  const posters = query.data?.data ?? [];
  const [index, setIndex] = useState(0);
  const [form, setForm] = useState({ opened: false, poster: null });

  useEffect(() => {
    if (index > posters.length - 1) setIndex(Math.max(0, posters.length - 1));
  }, [posters.length, index]);

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ADMIN_POSTERS_KEY });
    queryClient.invalidateQueries({ queryKey: ["posters"] });
  };

  const display = useMutation({
    mutationFn: ({ id, isActive }) => savePoster({ id, isActive }),
    onSuccess: (response) => {
      const poster = response.data;

      queryClient.setQueryData(ADMIN_POSTERS_KEY, (old) =>
        old ? { ...old, data: old.data.map((item) => (item.id === poster.id ? poster : item)) } : old
      );
      queryClient.invalidateQueries({ queryKey: ["posters"] });
      toast.success(
        poster.is_active ? `Poster #${poster.id} is on display.` : `Poster #${poster.id} is hidden from the homepage.`,
        "Poster updated"
      );
    },
    onError: (error) => toast.error(posterError(error)),
  });

  const remove = (poster) =>
    confirm({
      title: `Delete poster #${poster.id}?`,
      points: ["It comes off the homepage right away.", "The image is deleted and cannot be recovered."],
      confirmLabel: "Delete Poster",
      danger: true,
      run: async () => {
        await deletePoster(poster.id);
        refresh();
        toast.success(`Poster #${poster.id} was deleted.`, "Poster deleted");
      },
    });

  const saved = (poster) => {
    const adding = !form.poster;

    queryClient.setQueryData(ADMIN_POSTERS_KEY, (old) => {
      if (!old) return old;

      return adding ? { ...old, data: [...old.data, poster] } : { ...old, data: old.data.map((item) => (item.id === poster.id ? poster : item)) };
    });
    refresh();

    if (adding) setIndex(posters.length);
    toast.success(adding ? `Poster #${poster.id} was added.` : `Poster #${poster.id} was saved.`, adding ? "Poster added" : "Poster updated");
  };

  const openAdd = () => setForm({ opened: true, poster: null });

  let body;

  if (query.isLoading) {
    body = <Skeleton className="!aspect-[3/1] !h-auto" radius={16} data-testid="posters-loading" />;
  } else if (query.isError) {
    body = (
      <div className={`${FRAME} grid place-items-center bg-[#faf6f0] px-4 text-center`} data-testid="posters-error">
        <div className="flex flex-col items-center gap-2">
          <p className="m-0 font-display text-[13.5px] font-semibold text-ink">The posters could not be loaded.</p>
          <Button size="sm" variant="outline" onClick={() => query.refetch()}>
            <IconRefresh size={16} stroke={2} />
            Try again
          </Button>
        </div>
      </div>
    );
  } else if (posters.length === 0) {
    body = (
      <div className={`${FRAME} grid place-items-center border-2 border-dashed border-[#e6ded4] bg-[#faf7f3] px-4`} data-testid="posters-empty">
        <div className="flex flex-col items-center gap-2 text-center">
          <IconPhoto size={26} stroke={1.6} aria-hidden="true" className="text-[#c9c2b8]" />
          <p className="m-0 font-display text-[13px] font-semibold text-[#8d8884]">No promotions right now</p>
          <AddPosterButton onClick={openAdd} />
        </div>
      </div>
    );
  } else {
    const poster = posters[Math.min(index, posters.length - 1)];
    const hidden = !poster.is_active || poster.is_expired;
    const step = (delta) => setIndex((prev) => (prev + delta + posters.length) % posters.length);

    body = (
      <div className="flex flex-col gap-2">
        <div className={`${FRAME} bg-[#2b150a]`} data-testid="poster-slide">
          <img
            key={poster.id}
            {...responsiveImage(poster.image, { widths: [640, 960, 1280, 1920], sizes: "(min-width: 1024px) 1216px, 100vw", fallback: 1280 })}
            alt={`Poster #${poster.id}`}
            className={`h-full w-full object-cover transition-opacity ${hidden ? "opacity-45" : ""}`}
          />

          {/* Controls sit on shaded corners, as the artwork behind them is anything. */}
          <div className="pointer-events-none absolute inset-x-0 top-0 h-16 bg-gradient-to-b from-black/45 to-transparent" />
          <div className="pointer-events-none absolute inset-x-0 bottom-0 hidden h-20 bg-gradient-to-t from-black/45 to-transparent sm:block" />

          <div className="absolute left-3 top-3 rounded-full bg-black/35 py-1 pl-1.5 pr-3 backdrop-blur-sm">
            <Switch
              checked={poster.is_active}
              onChange={(event) => display.mutate({ id: poster.id, isActive: event.currentTarget.checked })}
              disabled={display.isPending}
              color="orange"
              size="sm"
              label={poster.is_active ? "On Display" : "Hidden"}
              classNames={{ label: "!pl-2 font-display !text-[11.5px] font-semibold !text-white", body: "items-center", track: "cursor-pointer" }}
              aria-label={`Show poster #${poster.id} on the homepage`}
              data-testid="poster-display"
            />
          </div>

          <span
            className="absolute right-3 top-3 rounded-full bg-black/35 px-2.5 py-1 font-display text-[13px] font-extrabold text-white backdrop-blur-sm"
            data-testid="poster-id"
          >
            #{poster.id}
          </span>

          {posters.length > 1 && (
            <>
              <button
                type="button"
                onClick={() => step(-1)}
                aria-label="Previous poster"
                className="absolute left-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-cocoa-900 transition-colors hover:bg-white"
              >
                <IconChevronLeft size={17} stroke={2.2} />
              </button>
              <button
                type="button"
                onClick={() => step(1)}
                aria-label="Next poster"
                className="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-cocoa-900 transition-colors hover:bg-white"
              >
                <IconChevronRight size={17} stroke={2.2} />
              </button>
            </>
          )}

          <div className="absolute inset-x-3 bottom-3 hidden items-end justify-between gap-3 sm:flex">
            <ExpiryPill poster={poster} onEdit={() => setForm({ opened: true, poster })} onDelete={() => remove(poster)} />
            <div className="flex flex-col items-end gap-2">
              <AddPosterButton onClick={openAdd} />
              <Dots posters={posters} index={index} onPick={setIndex} />
            </div>
          </div>
        </div>

        {/* Below the poster on narrow screens, where it is too short to carry them. */}
        <div className="flex flex-wrap items-center justify-between gap-2 sm:hidden">
          <ExpiryPill poster={poster} onEdit={() => setForm({ opened: true, poster })} onDelete={() => remove(poster)} />
          <AddPosterButton onClick={openAdd} />
          <div className="w-full">
            <Dots posters={posters} index={index} onPick={setIndex} dark />
          </div>
        </div>
      </div>
    );
  }

  return (
    <section aria-label="Homepage posters" className="mx-auto w-full max-w-[1216px] lg:col-span-2" data-testid="settings-posters">
      {body}

      <PosterFormModal
        opened={form.opened}
        poster={form.poster}
        onClose={() => setForm((prev) => ({ ...prev, opened: false }))}
        onSaved={saved}
      />
    </section>
  );
}

function Dots({ posters, index, onPick, dark = false }) {
  if (posters.length < 2) return null;

  return (
    <div className={`flex gap-1.5 ${dark ? "justify-center" : ""}`}>
      {posters.map((item, dot) => (
        <button
          key={item.id}
          type="button"
          onClick={() => onPick(dot)}
          aria-label={`Show poster #${item.id}`}
          aria-current={dot === index}
          className={`h-1.5 rounded-full transition-all duration-300 ${
            dot === index ? `w-5 ${dark ? "bg-brand-500" : "bg-white"}` : `w-1.5 ${dark ? "bg-[#e4ddd3]" : "bg-white/50 hover:bg-white/80"}`
          }`}
        />
      ))}
    </div>
  );
}

export default PosterManager;
