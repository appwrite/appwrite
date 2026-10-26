import { describe, expect, test } from 'bun:test'
import {
  getSmtpDnsRecords,
  getSmtpRecordName,
  getSmtpTxtHost,
  needsSmtpDnsVerification,
  proxyRuleProtocolQuery,
  type SmtpProxyRule,
} from '@/lib/function-smtp-domain'
import { ProxyRuleStatus, type Models } from '@appwrite.io/console'

function ruleStub(overrides: Partial<SmtpProxyRule> = {}): SmtpProxyRule {
  return {
    $id: 'rule1',
    $createdAt: '2026-01-01T00:00:00.000Z',
    $updatedAt: '2026-01-01T00:00:00.000Z',
    domain: 'mail.example.com',
    type: 'deployment',
    trigger: 'manual',
    status: ProxyRuleStatus.Unverified,
    redirectUrl: '',
    redirectStatusCode: 0,
    deploymentId: '',
    deploymentResourceType: 'function',
    deploymentResourceId: 'fn1',
    deploymentVcsProviderBranch: '',
    logs: '',
    protocol: 'smtp',
    ...overrides,
  } as Models.ProxyRule & SmtpProxyRule
}

describe('smtp domain DNS helpers', () => {
  test('builds ownership TXT host', () => {
    expect(getSmtpTxtHost('Mail.Example.com')).toBe(
      '_appwrite.mail.example.com',
    )
  })

  test('names records relative to the registrable domain', () => {
    expect(getSmtpRecordName('example.com')).toBe('@')
    expect(getSmtpRecordName('mail.example.com')).toBe('mail')
    expect(getSmtpRecordName('in.mail.example.co.uk')).toBe('in.mail')
  })

  test('names the TXT record for an apex domain', () => {
    expect(
      getSmtpDnsRecords({
        domain: 'example.com',
        projectId: 'project1',
        mxTarget: 'mx.example',
      }).map((r) => r.name),
    ).toEqual(['@', '_appwrite'])
  })

  test('returns MX then TXT records when inputs are complete', () => {
    const records = getSmtpDnsRecords({
      domain: 'mail.example.com',
      projectId: 'project1',
      mxTarget: 'fra.staging.appwrite.email',
    })
    expect(records).toEqual([
      {
        type: 'MX',
        name: 'mail',
        value: 'fra.staging.appwrite.email',
        ttl: 3600,
        priority: 10,
      },
      {
        type: 'TXT',
        name: '_appwrite.mail',
        value: 'project1',
        ttl: 3600,
      },
    ])
  })

  test('omits every record without a project ID', () => {
    expect(
      getSmtpDnsRecords({
        domain: 'mail.example.com',
        projectId: '',
        mxTarget: 'mx.example',
      }),
    ).toEqual([])
  })

  test('keeps the TXT record when the MX target is unknown', () => {
    expect(
      getSmtpDnsRecords({
        domain: 'mail.example.com',
        projectId: 'project1',
        mxTarget: '',
      }),
    ).toEqual([
      {
        type: 'TXT',
        name: '_appwrite.mail',
        value: 'project1',
        ttl: 3600,
      },
    ])
  })

  test('reads verification need from rule', () => {
    expect(
      needsSmtpDnsVerification(
        ruleStub({ status: ProxyRuleStatus.Unverified }),
      ),
    ).toBe(true)
    expect(
      needsSmtpDnsVerification(ruleStub({ status: ProxyRuleStatus.Verified })),
    ).toBe(false)
  })
})

describe('smtp rule filtering', () => {
  test('builds a server-side protocol query', () => {
    expect(JSON.parse(proxyRuleProtocolQuery('smtp'))).toEqual({
      method: 'equal',
      attribute: 'protocol',
      values: ['smtp'],
    })
    expect(JSON.parse(proxyRuleProtocolQuery('http'))).toEqual({
      method: 'equal',
      attribute: 'protocol',
      values: ['http'],
    })
  })
})
