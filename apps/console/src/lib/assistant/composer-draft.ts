const COMPOSER_DRAFTS_STORAGE_KEY = 'ai-chat-composer-drafts'
const NEW_CONVERSATION_DRAFT_KEY = '__new__'

function draftStorageKey(conversationId: string | null | undefined): string {
  return conversationId?.trim() || NEW_CONVERSATION_DRAFT_KEY
}

function readAllComposerDrafts(): Record<string, string> {
  if (typeof window === 'undefined') return {}
  try {
    const raw = window.localStorage.getItem(COMPOSER_DRAFTS_STORAGE_KEY)
    if (!raw) return {}
    const parsed = JSON.parse(raw) as unknown
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return {}
    }
    const drafts: Record<string, string> = {}
    for (const [key, value] of Object.entries(parsed)) {
      if (typeof value === 'string' && value.length > 0) {
        drafts[key] = value
      }
    }
    return drafts
  } catch {
    return {}
  }
}

function writeAllComposerDrafts(drafts: Record<string, string>): void {
  if (typeof window === 'undefined') return
  try {
    if (Object.keys(drafts).length === 0) {
      window.localStorage.removeItem(COMPOSER_DRAFTS_STORAGE_KEY)
      return
    }
    window.localStorage.setItem(
      COMPOSER_DRAFTS_STORAGE_KEY,
      JSON.stringify(drafts),
    )
  } catch {
    // Ignore quota / private-mode failures.
  }
}

/** Read a saved composer draft for a conversation (or the new-agent draft). */
export function readComposerDraft(
  conversationId: string | null | undefined,
): string {
  return readAllComposerDrafts()[draftStorageKey(conversationId)] ?? ''
}

/** Persist (or clear) the composer draft for a conversation on each keystroke. */
export function writeComposerDraft(
  conversationId: string | null | undefined,
  value: string,
): void {
  const key = draftStorageKey(conversationId)
  const drafts = readAllComposerDrafts()
  if (!value) {
    if (!(key in drafts)) return
    delete drafts[key]
    writeAllComposerDrafts(drafts)
    return
  }
  if (drafts[key] === value) return
  drafts[key] = value
  writeAllComposerDrafts(drafts)
}

/** Remove a saved draft after send or explicit clear. */
export function clearComposerDraft(
  conversationId: string | null | undefined,
): void {
  writeComposerDraft(conversationId, '')
}
