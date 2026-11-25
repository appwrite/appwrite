import { describe, expect, test } from 'bun:test'
import {
  EVERY_ORGANIZATION,
  mergeBeepPrefs,
  readBeepPrefs,
  snoozeOf,
  withMutedOrganization,
  withMutedProject,
  withoutSnooze,
  withSnooze,
} from '../../src/lib/notifications/beep-prefs'
import { sanitizeAccountPrefsForWrite } from '../../src/lib/user-prefs-keys'

const now = new Date('2026-11-23T12:00:00Z')
const later = (minutes: number) => new Date(now.getTime() + minutes * 60_000)

describe('readBeepPrefs', () => {
  test('defaults when beep is missing or malformed', () => {
    for (const prefs of [undefined, {}, { beep: 'x' }, { beep: [] }]) {
      expect(readBeepPrefs(prefs)).toEqual({
        mutedOrganizationIds: [],
        mutedProjectIds: [],
        resolved: true,
        snoozes: {},
      })
    }
  })

  test('keeps valid fields and drops invalid ones, as the server does', () => {
    const beep = readBeepPrefs({
      beep: {
        mutedOrganizationIds: ['org1', 3, ''],
        mutedProjectIds: ['p1'],
        resolved: false,
        snoozes: {
          org1: '2026-11-23T13:00:00.000Z',
          '*': '2026-11-23T14:00:00+02:00',
          org2: 'tomorrow',
          org3: '2026-11-23',
          '': '2026-11-23T13:00:00Z',
        },
      },
    })
    expect(beep.mutedOrganizationIds).toEqual(['org1'])
    expect(beep.mutedProjectIds).toEqual(['p1'])
    expect(beep.resolved).toBe(false)
    expect(Object.keys(beep.snoozes)).toEqual(['org1', '*'])
  })
})

describe('snoozes', () => {
  test('an organization takes the later of its own and the global snooze', () => {
    let beep = readBeepPrefs({})
    beep = withSnooze(beep, 'org1', later(30), now)
    beep = withSnooze(beep, EVERY_ORGANIZATION, later(60), now)

    expect(snoozeOf(beep, 'org1', now)).toEqual({
      until: later(60),
      everywhere: true,
    })
    expect(snoozeOf(beep, null, now)?.until).toEqual(later(60))
    expect(snoozeOf(beep, 'org1', later(90))).toBeNull()
  })

  test('without an organization only the global snooze counts', () => {
    const beep = withSnooze(readBeepPrefs({}), 'org1', later(30), now)
    expect(snoozeOf(beep, null, now)).toBeNull()
  })

  test('snoozing drops snoozes that already ended', () => {
    let beep = withSnooze(readBeepPrefs({}), 'old', later(5), now)
    beep = withSnooze(beep, 'org1', later(60), later(10))
    expect(Object.keys(beep.snoozes)).toEqual(['org1'])
  })

  test('resuming an organization ends its own and the global snooze', () => {
    let beep = readBeepPrefs({})
    beep = withSnooze(beep, 'org1', later(30), now)
    beep = withSnooze(beep, 'org2', later(30), now)
    beep = withSnooze(beep, EVERY_ORGANIZATION, later(30), now)

    expect(Object.keys(withoutSnooze(beep, 'org1', now).snoozes)).toEqual([
      'org2',
    ])
    expect(withoutSnooze(beep, EVERY_ORGANIZATION, now).snoozes).toEqual({})
  })
})

describe('mutes', () => {
  test('mute and unmute without duplicates', () => {
    let beep = readBeepPrefs({})
    beep = withMutedProject(beep, 'p1', true)
    beep = withMutedProject(beep, 'p1', true)
    beep = withMutedOrganization(beep, 'org1', true)
    expect(beep.mutedProjectIds).toEqual(['p1'])
    expect(beep.mutedOrganizationIds).toEqual(['org1'])

    beep = withMutedProject(beep, 'p1', false)
    expect(beep.mutedProjectIds).toEqual([])
  })
})

describe('account prefs', () => {
  test('merging keeps every other key', () => {
    const beep = withMutedProject(readBeepPrefs({}), 'p1', true)
    expect(mergeBeepPrefs({ theme: 'dark' }, beep)).toEqual({
      theme: 'dark',
      beep,
    })
  })

  test('the write sanitizer keeps beep, so Console writes do not clear it', () => {
    const beep = withMutedProject(readBeepPrefs({}), 'p1', true)
    expect(
      sanitizeAccountPrefsForWrite({
        theme: 'dark',
        beep,
        legacy: { nested: true },
      }),
    ).toEqual({ theme: 'dark', beep })
    expect(sanitizeAccountPrefsForWrite({ beep: ['x'] })).toEqual({})
  })
})
