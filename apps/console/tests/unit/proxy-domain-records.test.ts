import { describe, expect, test } from 'bun:test'
import { getClaimRecord, getRecordName } from '@/lib/utils/proxy-domains'

describe('getRecordName', () => {
  test('names an apex domain @', () => {
    expect(getRecordName('example.com')).toBe('@')
    expect(getRecordName('example.co.uk')).toBe('@')
    expect(getRecordName(' Example.COM ')).toBe('@')
  })

  test('keeps every label in front of the registrable domain', () => {
    expect(getRecordName('app.example.com')).toBe('app')
    expect(getRecordName('a.b.example.com')).toBe('a.b')
    expect(getRecordName('in.mail.example.co.uk')).toBe('in.mail')
  })
})

describe('getClaimRecord', () => {
  test('puts the project ID at _appwrite relative to the zone', () => {
    expect(getClaimRecord('example.com', 'project1')).toEqual({
      type: 'TXT',
      name: '_appwrite',
      value: 'project1',
      ttl: 3600,
    })
    expect(getClaimRecord('app.example.com', 'project1').name).toBe(
      '_appwrite.app',
    )
    expect(getClaimRecord('in.mail.example.co.uk', 'project1').name).toBe(
      '_appwrite.in.mail',
    )
  })
})
