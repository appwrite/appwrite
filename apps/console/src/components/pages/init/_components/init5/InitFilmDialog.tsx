import { lazy, Suspense, useState } from 'react'
import type { QueryClient } from '@tanstack/react-query'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from '@/components/ui/dialog'
import type { LaunchEventDayFilm } from '@/lib/init/types'
import { publicHlsPlaybackQueryOptions } from '@/lib/react-query/hooks/videos'
import { cn } from '@/lib/utils'
import { Init5Storm } from './Init5Storm'

const loadPlayer = () => import('./InitFilmPlayer')
const InitFilmPlayer = lazy(loadPlayer)

/**
 * Warm a day's films before the dialog opens: the player's code and each
 * film's playlist, so a click plays at once.
 */
export function prefetchInitFilms(
  queryClient: QueryClient,
  films: LaunchEventDayFilm[],
) {
  void loadPlayer()
  for (const film of films) {
    void queryClient
      .prefetchQuery(
        publicHlsPlaybackQueryOptions(film.projectId, film.videoId),
      )
      .catch(() => {})
  }
}

/**
 * A day's launch films on a stage of the Init 5 storm: fog and lightning at
 * its sides and the hero's comet circling the picture. A day with several
 * films (Monitors and Beep) switches between them with tabs under the picture.
 */
export function InitFilmDialog({
  title,
  films,
  open,
  onOpenChange,
}: {
  title: string
  films: LaunchEventDayFilm[]
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const [active, setActive] = useState(0)
  const film = films[active] ?? films[0]

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) setActive(0)
        onOpenChange(next)
      }}
    >
      <DialogContent
        overlayClassName="bg-[#09090b]/95 backdrop-blur-md"
        className={cn(
          'dark max-w-none gap-4 border-0 bg-transparent p-0 text-white shadow-none sm:max-w-none [&>button[data-slot=dialog-close]]:end-0 [&>button[data-slot=dialog-close]]:top-1.5 [&>button[data-slot=dialog-close]]:bg-transparent [&>button[data-slot=dialog-close]]:text-white',
          // Leave room for the film tabs under the picture on a day with several films.
          films.length > 1
            ? 'w-[min(88vw,calc((100dvh-15rem)*16/9),1600px)]'
            : 'w-[min(88vw,calc((100dvh-12rem)*16/9),1600px)]',
        )}
      >
        <div className="pointer-events-none absolute -inset-x-[14%] -inset-y-[18%] -z-10">
          <Init5Storm edges="sides" dark />
        </div>
        <div className="pe-10">
          <DialogTitle className="font-aeonik-pro text-[clamp(20px,2.2vw,28px)] font-normal leading-tight tracking-tight text-white">
            {title}
            <span className="text-[var(--brand-cta)]">_</span>
          </DialogTitle>
          <DialogDescription className="sr-only">
            The launch film, played with the Appwrite player.
          </DialogDescription>
        </div>
        <div
          className="init5-light-frame aspect-video w-full rounded-2xl shadow-[0_30px_120px_-40px_color-mix(in_oklch,var(--brand-cta)_55%,transparent)]"
          style={{
            ['--init5-trail-duration' as string]: '9s',
            ['--init5-trail-glow' as string]: 1,
          }}
        >
          <span aria-hidden className="init5-light-halo" />
          <div className="absolute inset-px overflow-hidden rounded-[15px] bg-black">
            {open && film ? (
              <Suspense fallback={null}>
                <InitFilmPlayer key={film.videoId} film={film} />
              </Suspense>
            ) : null}
          </div>
        </div>
        {films.length > 1 ? (
          <div
            className="mx-auto flex max-w-full flex-wrap justify-center gap-1 rounded-3xl border border-white/10 bg-white/5 p-1"
            role="tablist"
            aria-label="Launch films"
          >
            {films.map((entry, index) => (
              <button
                key={entry.videoId}
                type="button"
                role="tab"
                aria-selected={index === active}
                onClick={() => setActive(index)}
                className={cn(
                  'cursor-pointer rounded-full px-4 py-1.5 text-[13px] font-medium transition-colors',
                  index === active
                    ? 'bg-white/12 text-white'
                    : 'text-white/55 hover:text-white',
                )}
              >
                {entry.label}
              </button>
            ))}
          </div>
        ) : null}
      </DialogContent>
    </Dialog>
  )
}
