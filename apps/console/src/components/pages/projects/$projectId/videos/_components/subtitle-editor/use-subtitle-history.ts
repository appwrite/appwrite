import { useCallback, useMemo, useReducer } from 'react'
import type { SubtitleCue } from '@/lib/videos/subtitle-cues'

const HISTORY_LIMIT = 200
const COALESCE_MS = 1000

type HistoryState = {
  cues: SubtitleCue[]
  /** Snapshot that matches what is stored, used to compute `dirty`. */
  saved: SubtitleCue[]
  past: SubtitleCue[][]
  future: SubtitleCue[][]
  coalesceKey: string | null
  coalesceAt: number
}

type HistoryAction =
  | {
      type: 'commit'
      update: (cues: SubtitleCue[]) => SubtitleCue[]
      coalesceKey?: string
      at: number
    }
  | { type: 'undo' }
  | { type: 'redo' }
  | { type: 'reset'; cues: SubtitleCue[] }
  | { type: 'markSaved' }

function reducer(state: HistoryState, action: HistoryAction): HistoryState {
  switch (action.type) {
    case 'commit': {
      const next = action.update(state.cues)
      if (next === state.cues) return state
      const coalesce =
        !!action.coalesceKey &&
        action.coalesceKey === state.coalesceKey &&
        action.at - state.coalesceAt < COALESCE_MS
      return {
        ...state,
        cues: next,
        past: coalesce
          ? state.past
          : [...state.past, state.cues].slice(-HISTORY_LIMIT),
        future: [],
        coalesceKey: action.coalesceKey ?? null,
        coalesceAt: action.at,
      }
    }
    case 'undo': {
      const previous = state.past[state.past.length - 1]
      if (!previous) return state
      return {
        ...state,
        cues: previous,
        past: state.past.slice(0, -1),
        future: [state.cues, ...state.future],
        coalesceKey: null,
      }
    }
    case 'redo': {
      const [next, ...future] = state.future
      if (!next) return state
      return {
        ...state,
        cues: next,
        past: [...state.past, state.cues],
        future,
        coalesceKey: null,
      }
    }
    case 'reset':
      return {
        cues: action.cues,
        saved: action.cues,
        past: [],
        future: [],
        coalesceKey: null,
        coalesceAt: 0,
      }
    case 'markSaved':
      return { ...state, saved: state.cues, coalesceKey: null }
  }
}

export function useSubtitleHistory(initialCues: SubtitleCue[]) {
  const [state, dispatch] = useReducer(reducer, initialCues, (cues) => ({
    cues,
    saved: cues,
    past: [],
    future: [],
    coalesceKey: null,
    coalesceAt: 0,
  }))

  const commit = useCallback(
    (
      update: (cues: SubtitleCue[]) => SubtitleCue[],
      coalesceKey?: string,
    ) => dispatch({ type: 'commit', update, coalesceKey, at: Date.now() }),
    [],
  )
  const undo = useCallback(() => dispatch({ type: 'undo' }), [])
  const redo = useCallback(() => dispatch({ type: 'redo' }), [])
  const reset = useCallback(
    (cues: SubtitleCue[]) => dispatch({ type: 'reset', cues }),
    [],
  )
  const markSaved = useCallback(() => dispatch({ type: 'markSaved' }), [])

  return useMemo(
    () => ({
      cues: state.cues,
      dirty: state.cues !== state.saved,
      canUndo: state.past.length > 0,
      canRedo: state.future.length > 0,
      commit,
      undo,
      redo,
      reset,
      markSaved,
    }),
    [state, commit, undo, redo, reset, markSaved],
  )
}
