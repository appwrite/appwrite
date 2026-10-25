import { describe, expect, test } from 'bun:test'
import {
  acquisitionSourceFromStorage,
  addPendingSourceId,
  parseAcquisitionSource,
  persistAcquisitionSource,
  readPendingSourceIds,
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

describe('pending source ids', () => {
  test('keeps the latest ten unique ids', () => {
    const storage = memoryStorage()
    for (let i = 0; i < 12; i++) addPendingSourceId(`id${i}`, storage)
    addPendingSourceId('id5', storage)

    const ids = readPendingSourceIds(storage)
    expect(ids).toHaveLength(10)
    expect(ids[0]).toBe('id2')
    expect(ids.at(-1)).toBe('id5')
  })

  test('ignores malformed storage', () => {
    const storage = memoryStorage()
    storage.setItem('utmSourceIds', '{not json')
    expect(readPendingSourceIds(storage)).toEqual([])
    storage.setItem('utmSourceIds', JSON.stringify(['a', 1, '']))
    expect(readPendingSourceIds(storage)).toEqual(['a'])
  })
})
