/** Appwrite team names are short; cap URL values so a query param cannot flood the UI. */
export const MAX_INVITE_TEAM_NAME_LENGTH = 128

const CONTROL_AND_BIDI =
  /[\u0000-\u001F\u007F-\u009F\u202A-\u202E\u2066-\u2069]/g

/**
 * Decode a team name from the invite URL for plain-text display only.
 * Never pass the result to `dangerouslySetInnerHTML` or into URLs/HTML attributes
 * that interpret markup. React text nodes already HTML-escape the string.
 */
export function unescapeInviteTeamName(raw: string | undefined): string | null {
  if (raw == null || raw === '') return null

  let value = raw.replace(/\+/g, ' ')
  try {
    for (let i = 0; i < 2; i++) {
      if (!/%[0-9A-Fa-f]{2}/.test(value)) break
      value = decodeURIComponent(value)
    }
  } catch {
    // Malformed percent-encoding: keep the last successfully decoded value.
  }

  value = value.normalize('NFC').replace(CONTROL_AND_BIDI, '').trim()
  if (!value) return null
  if (value.length > MAX_INVITE_TEAM_NAME_LENGTH) {
    value = value.slice(0, MAX_INVITE_TEAM_NAME_LENGTH)
  }
  return value
}
