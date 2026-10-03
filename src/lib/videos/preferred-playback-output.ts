import type { PlaybackOutput } from '@/lib/videos/playback-output'

export type ReadyRenditionIdsByOutput = Record<'hls' | 'dash' | 'cmaf', string[]>

/** Pick the best adaptive format when the user has not chosen one explicitly. */
export function resolvePreferredPlaybackOutput(
  readyIds: ReadyRenditionIdsByOutput,
): PlaybackOutput {
  if (readyIds.hls.length > 0) return 'hls'
  if (readyIds.cmaf.length > 0) return 'cmaf'
  if (readyIds.dash.length > 0) return 'dash'
  return 'source'
}

export function groupReadyRenditionIds(
  renditions: Array<{ $id: string; output: string; status: string }>,
): ReadyRenditionIdsByOutput {
  const byOutput: ReadyRenditionIdsByOutput = { hls: [], dash: [], cmaf: [] }
  for (const rendition of renditions) {
    if (rendition.status !== 'ready') continue
    const key = rendition.output as keyof ReadyRenditionIdsByOutput
    if (key in byOutput) byOutput[key].push(rendition.$id)
  }
  for (const key of Object.keys(byOutput) as Array<keyof ReadyRenditionIdsByOutput>) {
    byOutput[key].sort()
  }
  return byOutput
}
