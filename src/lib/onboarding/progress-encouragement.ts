/**
 * Encouragement lines for the onboarding progress card: one random line per progress band.
 * Picks anew when the band changes (or when viewing a different project); not stored per project.
 */

export type EncouragementBand =
  | 'early'
  | 'momentum'
  | 'half'
  | 'almost'
  | 'done'

const EARLY: string[] = [
  'Great start - every big app begins with step one.',
  'You’re on your way - small steps add up fast.',
  'Solid beginning. Keep the momentum going.',
  'Nice - you’re already moving.',
  'This is how shipping starts - one checkbox at a time.',
  'Love the energy - keep stacking those wins.',
]

const MOMENTUM: string[] = [
  'You’re gaining steam - keep going.',
  'Nice progress - the foundation is taking shape.',
  'Momentum looks good from here.',
  'Keep at it - you’re building something real.',
  'You’re past the awkward early bit - nice.',
  'Every checkbox is a vote for shipping - keep it up.',
]

const HALF: string[] = [
  'More than halfway - you’re in the zone.',
  'Strong progress - the finish line is in sight.',
  'You’re past the halfway mark. Don’t stop now.',
  'This is where projects start to feel real.',
  'Huge progress - a few more wins to go.',
  'Halfway there - and looking sharp.',
]

const ALMOST: string[] = [
  'So close - you’re almost there.',
  'Final stretch - finish strong.',
  'Almost done - one last push.',
  'You’re inches from the finish line.',
  'The hard part’s behind you - wrap it up.',
  'Last lap - you’ve got this.',
]

const DONE: string[] = [
  'Nice work - you’re all set to build.',
  'Everything’s wired. Time to ship something great.',
  'Checklist complete. You’ve got this.',
  'That’s the full tour. Go build.',
  'You did it - your stack is ready when you are.',
  'All green - now go make something people love.',
  'You\u2019re ready - and the community helps Appwrite grow.',
]

const POOLS: Record<EncouragementBand, string[]> = {
  early: EARLY,
  momentum: MOMENTUM,
  half: HALF,
  almost: ALMOST,
  done: DONE,
}

export function getEncouragementBand(progress: number): EncouragementBand {
  if (progress >= 100) return 'done'
  if (progress >= 75) return 'almost'
  if (progress >= 50) return 'half'
  if (progress >= 25) return 'momentum'
  return 'early'
}

/** Random line for this band (call from `useMemo` keyed by band + projectId). */
export function pickEncouragementForBand(band: EncouragementBand): string {
  const pool = POOLS[band]
  return pool[Math.floor(Math.random() * pool.length)]!
}
