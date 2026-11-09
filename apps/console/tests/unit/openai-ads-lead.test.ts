import { afterEach, describe, expect, it } from 'bun:test'
import { isOpenAiAdsLead } from '@/lib/openai-ads'

const registration = '2026-11-01T10:00:00.000+00:00'
const timeZone = process.env.TZ

/** A completed stage, stamped the way the server stores it: UTC, no zone. */
function completed(at = '2026-11-01 12:00:00.000', actorType = 'admin') {
  return { status: 'completed', at, actorType }
}

describe('isOpenAiAdsLead', () => {
  afterEach(() => {
    if (timeZone === undefined) delete process.env.TZ
    else process.env.TZ = timeZone
  })

  it('converts once three product areas are completed', () => {
    const project = {
      onboarding: {
        'project.createWebPlatform': completed(),
        'tablesDB.createRow': completed(),
        'storage.createFile': completed(),
      },
    }

    expect(isOpenAiAdsLead([project], registration)).toBe(true)
  })

  it('counts several stages in one area once', () => {
    const project = {
      onboarding: {
        'tablesDB.create': completed(),
        'tablesDB.createTable': completed(),
        'tablesDB.createRow': completed(),
        'organization.createProjectKey': completed(),
      },
    }

    expect(isOpenAiAdsLead([project], registration)).toBe(false)
  })

  it('combines areas across projects', () => {
    const first = { onboarding: { 'project.createKey': completed() } }
    const second = {
      onboarding: {
        'sites.createDeployment': completed(),
        'messaging.createTopic': completed(),
      },
    }

    expect(isOpenAiAdsLead([first, second], registration)).toBe(true)
  })

  it('counts the CLI, the MCP server and dedicated databases', () => {
    const project = {
      onboarding: {
        'cli.install': completed(),
        'mcp.install': completed(),
        'postgresql.create': completed(),
      },
    }

    expect(isOpenAiAdsLead([project], registration)).toBe(true)
  })

  it('ignores skipped, backfilled and pre-registration stages', () => {
    const project = {
      onboarding: {
        'project.createWebPlatform': completed(),
        'tablesDB.create': completed(),
        'storage.createBucket': {
          status: 'skipped',
          at: '2026-11-01 12:00:00.000',
        },
        'functions.create': completed('2026-11-01 12:00:00.000', 'hidden'),
        'sites.create': completed('2026-10-20 12:00:00.000'),
        'users.create': { status: 'completed' },
      },
    }

    expect(isOpenAiAdsLead([project], registration)).toBe(false)
  })

  it('reads stage times as UTC in any browser time zone', () => {
    const minutesAfter = {
      onboarding: {
        'project.createWebPlatform': completed('2026-11-01 10:30:00.000'),
        'tablesDB.create': completed('2026-11-01 10:30:00.000'),
        'storage.createBucket': completed('2026-11-01 10:30:00.000'),
      },
    }
    const hoursBefore = {
      onboarding: {
        'project.createWebPlatform': completed('2026-11-01 07:00:00.000'),
        'tablesDB.create': completed('2026-11-01 07:00:00.000'),
        'storage.createBucket': completed('2026-11-01 07:00:00.000'),
      },
    }
    const zoned = {
      onboarding: {
        'project.createWebPlatform': completed('2026-11-01T15:30:00.000+05:00'),
        'tablesDB.create': completed('2026-11-01T15:30:00.000+05:00'),
        'storage.createBucket': completed('2026-11-01T15:30:00.000+05:00'),
      },
    }

    process.env.TZ = 'Asia/Karachi'
    expect(isOpenAiAdsLead([minutesAfter], registration)).toBe(true)
    expect(isOpenAiAdsLead([zoned], registration)).toBe(true)
    process.env.TZ = 'America/New_York'
    expect(isOpenAiAdsLead([hoursBefore], registration)).toBe(false)
  })

  it('needs a valid registration date', () => {
    const project = {
      onboarding: {
        'project.createWebPlatform': completed(),
        'tablesDB.create': completed(),
        'storage.createBucket': completed(),
      },
    }

    expect(isOpenAiAdsLead([project], '')).toBe(false)
    expect(isOpenAiAdsLead([{}], registration)).toBe(false)
  })
})
