import { afterEach, beforeEach, describe, expect, spyOn, test } from 'bun:test'
import { sdk } from '@/lib/appwrite/sdk'
import {
  acquisitionSourceFromStorage,
  addPendingSourceClaim,
  linkSignUpSources,
  linkSourcesBeforeUpgrade,
  parseAcquisitionSource,
  persistAcquisitionSource,
  readPendingSourceClaims,
} from '@/lib/marketing/utm'

function memoryStorage() {
  const values = new Map<string, string>()
  return {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => void values.set(key, value),
    removeItem: (key: string) => void values.delete(key),
  }
}

function land(storage: ReturnType<typeof memoryStorage>, search: string) {
  persistAcquisitionSource(
    parseAcquisitionSource(new URL(`https://appwrite.io/${search}`), ''),
    storage,
  )
}

describe('parseAcquisitionSource click ids', () => {
  test('derives source and cpc medium when utm_source is missing', () => {
    const parse = (search: string) =>
      parseAcquisitionSource(new URL(`https://appwrite.io/${search}`), '')

    expect(parse('?gclid=abc')).toMatchObject({
      utmSource: 'google',
      utmMedium: 'cpc',
    })
    expect(parse('?msclkid=abc')).toMatchObject({
      utmSource: 'bing',
      utmMedium: 'cpc',
    })
    expect(parse('?fbclid=abc&utm_medium=paid_social')).toMatchObject({
      utmSource: 'facebook',
      utmMedium: 'paid_social',
    })
  })

  test('keeps explicit utm_source over a click id', () => {
    expect(
      parseAcquisitionSource(
        new URL('https://appwrite.io/?gclid=abc&utm_source=newsletter'),
        '',
      ),
    ).toMatchObject({ utmSource: 'newsletter', utmMedium: null })
  })
})

describe('persistAcquisitionSource', () => {
  test('a new tagged landing replaces the stored tuple', () => {
    const storage = memoryStorage()
    land(storage, '?utm_source=a&utm_campaign=A')
    land(storage, '?utm_source=b')

    expect(acquisitionSourceFromStorage(storage)).toMatchObject({
      utmSource: 'b',
      utmCampaign: null,
    })
  })

  test('a click-id-only landing replaces the stored tuple', () => {
    const storage = memoryStorage()
    land(storage, '?utm_source=a&utm_campaign=A')
    land(storage, '?gclid=x')

    expect(acquisitionSourceFromStorage(storage)).toMatchObject({
      utmSource: 'google',
      utmMedium: 'cpc',
      utmCampaign: null,
    })
  })

  test('keys we do not store leave the stored tuple untouched', () => {
    const storage = memoryStorage()
    land(storage, '?utm_source=a&utm_campaign=A')
    land(storage, '?utm_term=t&utm_content=c&utm_id=i')

    expect(acquisitionSourceFromStorage(storage)).toMatchObject({
      utmSource: 'a',
      utmCampaign: 'A',
    })
  })

  test('an untagged referrer-only landing keeps the campaign', () => {
    const storage = memoryStorage()
    land(storage, '?utm_source=a&utm_campaign=A')
    persistAcquisitionSource(
      parseAcquisitionSource(
        new URL('https://appwrite.io/'),
        'https://news.ycombinator.com/',
      ),
      storage,
    )

    expect(acquisitionSourceFromStorage(storage)).toMatchObject({
      utmSource: 'a',
      utmCampaign: 'A',
      referrer: 'https://news.ycombinator.com/',
    })
  })
})

describe('pending source claims', () => {
  test('keeps the latest ten unique claims', () => {
    const storage = memoryStorage()
    for (let i = 0; i < 12; i++) addPendingSourceClaim(`id${i}.sig`, storage)
    addPendingSourceClaim('id5.sig', storage)

    const claims = readPendingSourceClaims(storage)
    expect(claims).toHaveLength(10)
    expect(claims[0]).toBe('id2.sig')
    expect(claims.at(-1)).toBe('id5.sig')
  })

  test('ignores malformed storage and the legacy id list', () => {
    const storage = memoryStorage()
    storage.setItem('utmSourceIds', JSON.stringify(['legacy']))
    expect(readPendingSourceClaims(storage)).toEqual([])
    storage.setItem('utmSourceClaims', '{not json')
    expect(readPendingSourceClaims(storage)).toEqual([])
    storage.setItem('utmSourceClaims', JSON.stringify(['a.sig', 1, '']))
    expect(readPendingSourceClaims(storage)).toEqual(['a.sig'])
  })
})

describe('linking pending source claims', () => {
  const HOUR = 60 * 60 * 1000
  let storage: ReturnType<typeof memoryStorage>
  let call: ReturnType<typeof spyOn>

  beforeEach(() => {
    storage = memoryStorage()
    addPendingSourceClaim('a.sig', storage)
    Object.assign(globalThis, { window: globalThis, localStorage: storage })
    call = spyOn(sdk.forConsole.client, 'call').mockResolvedValue({})
  })

  afterEach(() => {
    call.mockRestore()
    Reflect.deleteProperty(globalThis, 'window')
    Reflect.deleteProperty(globalThis, 'localStorage')
  })

  test('an existing account keeps its claims on sign-in', async () => {
    await linkSignUpSources(new Date(Date.now() - 48 * HOUR).toISOString())

    expect(call).not.toHaveBeenCalled()
    expect(readPendingSourceClaims(storage)).toEqual(['a.sig'])
  })

  test('a new account links its claims on sign-in', async () => {
    await linkSignUpSources(new Date(Date.now() - HOUR).toISOString())

    expect(call).toHaveBeenCalledTimes(1)
    expect(call.mock.calls[0]?.[3]).toEqual({ claims: ['a.sig'] })
    expect(readPendingSourceClaims(storage)).toEqual([])
  })

  test('a paid upgrade links the claims', async () => {
    await linkSourcesBeforeUpgrade()

    expect(call.mock.calls[0]?.[3]).toEqual({ claims: ['a.sig'] })
    expect(readPendingSourceClaims(storage)).toEqual([])
  })

  test('a slow link does not hold up the upgrade', async () => {
    let finish = () => {}
    call.mockReturnValue(new Promise((resolve) => (finish = () => resolve({}))))

    await linkSourcesBeforeUpgrade(10)

    expect(readPendingSourceClaims(storage)).toEqual(['a.sig'])
    finish()
    await linkSourcesBeforeUpgrade()
  })
})
