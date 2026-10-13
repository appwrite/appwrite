import { AudioLines } from 'lucide-react'
import { cn } from '@/lib/utils'

/** Visual layer for audio-only sources (no video frame size after probe). */
export function AudioOnlyPlayerBackdrop({
  title,
  className,
}: {
  title?: string
  className?: string
}) {
  return (
    <div
      aria-hidden
      className={cn(
        'pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-4 bg-gradient-to-b from-zinc-900 to-black px-6',
        className,
      )}
    >
      <div className="flex size-20 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-white/90 shadow-lg backdrop-blur-sm sm:size-24">
        <AudioLines className="size-10 sm:size-12" />
      </div>
      {title ? (
        <p className="max-w-md truncate text-center text-[13px] font-medium text-white/80">
          {title}
        </p>
      ) : null}
      <div className="flex h-8 items-end justify-center gap-1">
        {[0, 1, 2, 3, 4].map((bar) => (
          <span
            key={bar}
            className="w-1 animate-pulse rounded-full bg-white/40"
            style={{
              height: `${12 + (bar % 3) * 10}px`,
              animationDelay: `${bar * 120}ms`,
            }}
          />
        ))}
      </div>
    </div>
  )
}
