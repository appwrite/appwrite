import { describe, expect, it } from 'bun:test'
import { isOpenAiAdsLead } from '@/lib/openai-ads'

const registration = '2026-11-01T10:00:00.000+00:00'

function completed(at = '2026-11-01T12:00:00.000+00:00', actorType = 'admin') {
  return { status: 'completed', at, actorType }
}

describe('isOpenAiAdsLead', () => {
  it('converts once four product areas are completed', () => {
    const project = {
      onboarding: {
        'project.createWebPlatform': completed(),
        'tablesDB.createRow': completed(),
        'storage.createFile': completed(),
        'functions.createDeployment': completed(),
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
        'users.create': completed(),
      },
    }

    expect(isOpenAiAdsLead([project], registration)).toBe(false)
  })

  it('combines areas across projects', () => {
    const first = {
      onboarding: {
        'project.createKey': completed(),
        'account.create': completed(),
      },
    }
    const second = {
      onboarding: {
        'sites.createDeployment': completed(),
        'messaging.createTopic': completed(),
      },
    }

    expect(isOpenAiAdsLead([first, second], registration)).toBe(true)
  })

  it('ignores skipped, backfilled, pre-registration and uncounted stages', () => {
    const project = {
      onboarding: {
        'project.createWebPlatform': completed(),
        'tablesDB.create': completed(),
        'storage.createBucket': {
          status: 'skipped',
          at: '2026-11-01T12:00:00Z',
        },
        'functions.create': completed('2026-11-01T12:00:00Z', 'hidden'),
        'sites.create': completed('2026-10-20T12:00:00Z'),
        'cli.install': completed(),
        'mcp.install': completed(),
        'postgresql.create': completed(),
        'users.create': { status: 'completed' },
      },
    }

    expect(isOpenAiAdsLead([project], registration)).toBe(false)
  })

  it('needs a valid registration date', () => {
    const project = {
      onboarding: {
        'project.createWebPlatform': completed(),
        'tablesDB.create': completed(),
        'storage.createBucket': completed(),
        'functions.create': completed(),
      },
    }

    expect(isOpenAiAdsLead([project], '')).toBe(false)
    expect(isOpenAiAdsLead([{}], registration)).toBe(false)
  })
})
