import { describe, expect, test } from 'bun:test'
import { defaultParseSearch } from '@tanstack/react-router'
import { Route as JoinRoute } from '@/routes/_auth/join'
import { Route as VerifyEmailRoute } from '@/routes/_auth/verify-email'
import { Route as ResetRoute } from '@/routes/_public/reset'
import { Route as TransferRoute } from '@/routes/transfer'
import { Route as UpgradeRoute } from '@/routes/_public/upgrade'
import { Route as SitesDeployRoute } from '@/routes/_public/projects.$projectId.sites.create.deploy'
import { Route as MonitorsRoute } from '@/routes/_public/projects.$projectId.usage.monitors'
import { Route as OAuth2AppsRoute } from '@/routes/_public/projects.$projectId.auth.oauth2-server.apps'
import { Route as OAuth2DeviceRoute } from '@/routes/_auth/oauth2.device'
import { getRawSearchParam } from '@/lib/table-filters'

type Validator =
  | { parse: (input: unknown) => Record<string, unknown> }
  | ((input: Record<string, unknown>) => Record<string, unknown>)

/** Run a route's validateSearch on what the router hands it for a query string. */
function validate(route: { options: unknown }, searchStr: string) {
  const validator = (route.options as { validateSearch: Validator })
    .validateSearch
  const parsed = defaultParseSearch(searchStr)
  return typeof validator === 'function'
    ? validator(parsed)
    : validator.parse(parsed)
}

// Links built outside the Console (server emails, other sites) put raw values in the
// query string, and the router JSON-parses `2024`, `true` or `null` into non-strings.
const NUMBER_LIKE = ['2024', '123456', 'true', 'false', 'null']

const cases: Array<[string, { options: unknown }, string[]]> = [
  [
    'join (team invite email)',
    JoinRoute,
    ['teamId', 'membershipId', 'userId', 'secret', 'teamName'],
  ],
  ['verify-email', VerifyEmailRoute, ['userId', 'secret', 'expire']],
  ['reset (recovery email)', ResetRoute, ['userId', 'secret']],
  ['transfer', TransferRoute, ['transferId', 'secret']],
  [
    'upgrade (billing emails)',
    UpgradeRoute,
    ['orgId', 'plan', 'code', 'type', 'invites'],
  ],
  [
    'sites deploy link',
    SitesDeployRoute,
    [
      'repo',
      'owner',
      'framework',
      'branch',
      'root',
      'installCommand',
      'buildCommand',
      'startCommand',
      'outputDirectory',
      'envKeys',
    ],
  ],
  ['monitor link (alert email)', MonitorsRoute, ['monitor']],
  ['oauth2 app link', OAuth2AppsRoute, ['appId']],
  ['device code link', OAuth2DeviceRoute, ['user_code']],
]

describe('number-like search values', () => {
  for (const [name, route, fields] of cases) {
    test(`${name} keeps them as text`, () => {
      for (const field of fields) {
        for (const value of NUMBER_LIKE) {
          const result = validate(route, `?${field}=${value}`)
          // user_code accepts strings and numbers only: true/false/null are not codes.
          if (field === 'user_code' && !/^\d+$/.test(value)) continue
          expect(result[field]).toBe(value)
        }
      }
    })
  }

  test('a team invite for an organization named 2024 opens', () => {
    const result = validate(
      JoinRoute,
      '?teamId=6aee24e3001d4dabc2e2&membershipId=6aee2a01aa&userId=6aee2a02bb&secret=a1b2c3&teamName=2024',
    )
    expect(result).toEqual({
      teamId: '6aee24e3001d4dabc2e2',
      membershipId: '6aee2a01aa',
      userId: '6aee2a02bb',
      secret: 'a1b2c3',
      teamName: '2024',
    })
  })

  test('raw readers see the value the link carried, not the router quoting', () => {
    // After validation the router rewrites `?userId=12345` to `?userId=%2212345%22`.
    const rewritten = new URLSearchParams(
      '?userId=%2212345%22&secret=%22678%22',
    )
    expect(getRawSearchParam(rewritten, 'userId')).toBe('12345')
    expect(getRawSearchParam(rewritten, 'secret')).toBe('678')
    // Untouched links come back as they are, so long tokens keep every digit.
    const original = new URLSearchParams(
      '?userId=6aee2a02bb&secret=12345678901234567890123',
    )
    expect(getRawSearchParam(original, 'userId')).toBe('6aee2a02bb')
    expect(getRawSearchParam(original, 'secret')).toBe(
      '12345678901234567890123',
    )
    expect(getRawSearchParam(original, 'missing')).toBeNull()
    expect(getRawSearchParam(new URLSearchParams('?x=%22'), 'x')).toBe('"')
  })
})
