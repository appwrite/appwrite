import { describe, expect, it } from 'bun:test'
import {
  mergeInitPresencePrefsIntoAccountPrefs,
  parseInitPresencePrefs,
  resolveInitIdentityVisiblePreference,
  resolveInitParticipantOnlinePreference,
} from '@/lib/init/init-presence-prefs'

describe('init-presence-prefs', () => {
  it('parses stored presence prefs', () => {
    expect(
      parseInitPresencePrefs({
        identityVisible: true,
        participantOnline: false,
      }),
    ).toEqual({
      identityVisible: true,
      participantOnline: false,
    })
  })

  it('prefers account prefs over regional default for identity visibility', () => {
    const accountPrefs = mergeInitPresencePrefsIntoAccountPrefs(undefined, 'init-2026', {
      identityVisible: true,
    })

    expect(
      resolveInitIdentityVisiblePreference(
        accountPrefs,
        'init-2026',
        'user-1',
        true,
      ),
    ).toBe(true)
  })

  it('falls back to hidden-by-default when no stored preference exists', () => {
    expect(
      resolveInitIdentityVisiblePreference(undefined, 'init-2026', 'user-1', true),
    ).toBe(false)
  })

  it('defaults participant online to true when unset', () => {
    expect(
      resolveInitParticipantOnlinePreference(undefined, 'init-2026', 'user-1'),
    ).toBe(true)
  })
})
