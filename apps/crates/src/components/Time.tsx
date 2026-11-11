import { useEffect, useState } from 'react'
import { commitUrl } from '@/lib/docs'
import { absolute, ago, day } from '@/lib/time'

/** The current time, once hydrated (`null` on the server and the first render), refreshed every minute. */
function useNow() {
  const [now, setNow] = useState<number | null>(null)
  useEffect(() => {
    setNow(Date.now())
    const timer = setInterval(() => setNow(Date.now()), 60_000)
    return () => clearInterval(timer)
  }, [])
  return now
}

/**
 * A date as readers think of it: relative ("3 days ago") with the absolute
 * time on hover. The server and the first client render show the absolute
 * day, so hydration matches; the relative form replaces it after.
 */
export function Time({ date, className }: { date: string | null | undefined; className?: string }) {
  const now = useNow()
  const text = now === null ? day(date) : ago(date, now)
  if (!text) return null
  return (
    <time dateTime={date ?? undefined} title={absolute(date)} className={className}>
      {text}
    </time>
  )
}

/**
 * When generated data was written and from which commit: "Data generated 2 hours ago · commit 907eb5b".
 * Older data has no generation time; the commit alone is shown then.
 */
export function Generated({ at, commit, commitDate, label = 'Data generated' }: { at?: string | null; commit?: string | null; commitDate?: string | null; label?: string }) {
  if (!at && !commit) return null
  return (
    <span>
      {at ? (
        <>
          {label} <Time date={at} />
        </>
      ) : null}
      {at && commit ? ' · ' : null}
      {commit ? (
        <>
          commit{' '}
          <a href={commitUrl(commit)} target="_blank" rel="noopener" className="font-mono hover:text-foreground hover:underline" title={commitDate ? `Committed ${absolute(commitDate)}` : undefined}>
            {commit}
          </a>
        </>
      ) : null}
    </span>
  )
}
