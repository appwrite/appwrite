// Each section of the migration dashboard (`/migration`) as one share done,
// for its tab and the progress bar at its top.

import type { Hook, Migration } from './appwrite'
import type { LibrarySummary } from './docs'
import type { Board } from './tasks'

export type Tone = 'ok' | 'progress' | 'started' | 'missing'

/** A section's status: `done` of `total`, drawn as `parts` in order; `rest` names what is left, in the legend only. */
export interface Progress {
  done: number
  total: number
  parts: { label: string; value: number; tone: Tone }[]
  rest?: { label: string; value: number }
}

const count = <T>(xs: T[], f: (x: T) => boolean) => xs.filter(f).length

/** Libraries converted, of those to convert (the ones not converted by design left out). */
export function utopia(libraries: Pick<LibrarySummary, 'status'>[]): Progress {
  const libs = libraries.filter((l) => l.status !== 'retired')
  const done = count(libs, (l) => l.status === 'complete')
  return {
    done,
    total: libs.length,
    parts: [
      { label: 'Converted', value: done, tone: 'ok' },
      { label: 'In progress', value: count(libs, (l) => l.status === 'progress'), tone: 'progress' },
      { label: 'Started', value: count(libs, (l) => l.status === 'started'), tone: 'started' },
    ],
    rest: { label: 'Planned', value: count(libs, (l) => l.status === 'planned') },
  }
}

/** API endpoints served by Rust. */
export function services(s: Migration['summary']): Progress {
  return {
    done: s.served,
    total: s.endpoints,
    parts: [
      { label: 'Served by Rust', value: s.served, tone: 'ok' },
      { label: 'Implemented, not routed', value: s.ready, tone: 'started' },
      { label: 'Routed, missing in Rust', value: s.missing, tone: 'missing' },
    ],
    rest: { label: 'PHP', value: s.endpoints - s.served - s.ready - s.missing },
  }
}

/** Hooks and request resources appwrite-core reproduces. */
export function hooks(list: Pick<Hook, 'rust'>[]): Progress {
  const done = count(list, (h) => h.rust.status === 'done')
  return {
    done,
    total: list.length,
    parts: [
      { label: 'Done', value: done, tone: 'ok' },
      { label: 'Partly', value: count(list, (h) => h.rust.status === 'partial'), tone: 'progress' },
    ],
    rest: { label: 'Not started or undescribed', value: count(list, (h) => h.rust.status === 'missing' || h.rust.status === 'unknown') },
  }
}

/** Tasks done on the board. */
export function tasks(board: Board): Progress {
  const t = board.tasks
  const done = count(t, (x) => x.status === 'done')
  return {
    done,
    total: t.length,
    parts: [
      { label: 'Done', value: done, tone: 'ok' },
      { label: 'In progress', value: count(t, (x) => x.status === 'progress'), tone: 'started' },
      { label: 'Waiting', value: count(t, (x) => x.status === 'blocked'), tone: 'progress' },
    ],
    rest: { label: 'Up next', value: count(t, (x) => x.status === 'todo') },
  }
}

/** The whole migration: the mean of its sections' shares, each weighed the same. */
export function overall(sections: Progress[]): Progress {
  const done = Math.round(sections.reduce((a, p) => a + (p.total ? p.done / p.total : 0), 0) * (100 / Math.max(sections.length, 1)))
  return { done, total: 100, parts: [{ label: 'Done', value: done, tone: 'ok' }] }
}
