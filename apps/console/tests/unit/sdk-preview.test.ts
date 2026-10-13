import { describe, expect, test } from 'bun:test'
import { merge, mergeSpecs } from '../../scripts/sdk-preview'

describe('preview SDK spec merge', () => {
  test('keeps base-only paths and adds the branch paths', () => {
    const spec = mergeSpecs(
      { paths: { '/cloud': { get: {} } } },
      { paths: { '/branch': { post: {} } } },
    )

    expect(Object.keys(spec.paths ?? {})).toEqual(['/cloud', '/branch'])
  })

  test('keeps the base info and servers', () => {
    const spec = mergeSpecs(
      { info: { version: '19.0.0' }, servers: [{ url: 'https://cloud' }] },
      { info: { version: 'latest' }, servers: [{ url: 'http://localhost' }] },
    )

    expect(spec.info).toEqual({ version: '19.0.0' })
    expect(spec.servers).toEqual([{ url: 'https://cloud' }])
  })

  test('the branch wins on values both define', () => {
    expect(
      merge({ description: 'old', deprecated: true }, { description: 'new' }),
    ).toEqual({
      description: 'new',
      deprecated: true,
    })
  })

  test('merges parameters by name and location', () => {
    const merged = merge(
      [
        { name: 'id', in: 'path', description: 'old' },
        { name: 'id', in: 'query' },
        { name: 'region', in: 'query' },
      ],
      [
        { name: 'id', in: 'path', description: 'new' },
        { name: 'providers', in: 'query' },
      ],
    )

    expect(merged).toEqual([
      { name: 'id', in: 'path', description: 'new' },
      { name: 'id', in: 'query' },
      { name: 'region', in: 'query' },
      { name: 'providers', in: 'query' },
    ])
  })

  test('keeps base-only enum values', () => {
    const merged = merge(
      [
        { type: 'string', enum: ['github'], title: 'github' },
        { type: 'string', enum: ['cloudOnly'], title: 'cloudOnly' },
      ],
      [
        { type: 'string', enum: ['github'], title: 'github' },
        { type: 'string', enum: ['microsoft'], title: 'microsoft' },
      ],
    )

    expect(merged).toEqual([
      { type: 'string', enum: ['github'], title: 'github' },
      { type: 'string', enum: ['cloudOnly'], title: 'cloudOnly' },
      { type: 'string', enum: ['microsoft'], title: 'microsoft' },
    ])
  })

  test('pairs same-named method variants in order instead of duplicating them', () => {
    const merged = merge(
      [
        { name: 'upsert', parameters: ['a'] },
        { name: 'upsert', parameters: ['b'] },
      ],
      [
        { name: 'upsert', parameters: ['a'] },
        { name: 'upsert', parameters: ['b', 'c'] },
      ],
    )

    expect(merged).toEqual([
      { name: 'upsert', parameters: ['a'] },
      { name: 'upsert', parameters: ['b', 'c'] },
    ])
  })
})
