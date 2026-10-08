import type { SavedCoverGeneration } from '@/lib/cover-generator/cover-generation-prefs'
import type { SavedDiagramGeneration } from '@/lib/diagram-generator/generation-prefs'

/**
 * In-memory drafts for generator documents that have been opened from a
 * template but not yet saved to account prefs / local storage.
 *
 * Stored at module scope so drafts survive route remounts when navigating
 * from `/generator` → `/generator/$generationId` (and the diagrams equivalent).
 */
const coverDrafts = new Map<string, SavedCoverGeneration>()
const diagramDrafts = new Map<string, SavedDiagramGeneration>()

export function setCoverGenerationDraft(entry: SavedCoverGeneration): void {
  coverDrafts.set(entry.id, entry)
}

export function getCoverGenerationDraft(
  id: string,
): SavedCoverGeneration | undefined {
  return coverDrafts.get(id)
}

export function deleteCoverGenerationDraft(id: string): boolean {
  return coverDrafts.delete(id)
}

export function setDiagramGenerationDraft(entry: SavedDiagramGeneration): void {
  diagramDrafts.set(entry.id, entry)
}

export function getDiagramGenerationDraft(
  id: string,
): SavedDiagramGeneration | undefined {
  return diagramDrafts.get(id)
}

export function deleteDiagramGenerationDraft(id: string): boolean {
  return diagramDrafts.delete(id)
}
