const ADJECTIVES = [
  'Swift',
  'Calm',
  'Bold',
  'Quiet',
  'Bright',
  'Clever',
  'Cosmic',
  'Daring',
  'Eager',
  'Gentle',
  'Happy',
  'Jolly',
  'Keen',
  'Lucky',
  'Merry',
  'Noble',
  'Quick',
  'Radiant',
  'Silent',
  'Steady',
  'Sunny',
  'Vivid',
  'Warm',
  'Wild',
  'Wise',
  'Zesty',
  'Amber',
  'Crimson',
  'Golden',
  'Silver',
] as const

const NOUNS = [
  'Fox',
  'River',
  'Oak',
  'Comet',
  'Pine',
  'Falcon',
  'Harbor',
  'Maple',
  'Nova',
  'Otter',
  'Panda',
  'Quartz',
  'Robin',
  'Spruce',
  'Tiger',
  'Willow',
  'Badger',
  'Cedar',
  'Drift',
  'Ember',
  'Finch',
  'Grove',
  'Heron',
  'Ivory',
  'Jade',
  'Kite',
  'Lynx',
  'Mist',
  'Pearl',
  'Reef',
] as const

function hashSeed(seed: string): number {
  let hash = 2166136261
  for (const char of seed) {
    hash ^= char.codePointAt(0) ?? 0
    hash = Math.imul(hash, 16777619)
  }
  return hash >>> 0
}

/** Stable guest-style display name derived from an anonymous presence ID. */
export function buildInitRandomPresenceName(seed: string): string {
  const normalized = seed.trim()
  if (!normalized) return 'Guest User'

  const hash = hashSeed(normalized)
  const adjective = ADJECTIVES[hash % ADJECTIVES.length]!
  const noun = NOUNS[(hash >>> 8) % NOUNS.length]!
  return `${adjective} ${noun}`
}
