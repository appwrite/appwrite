import { describe, expect, test } from 'bun:test'
import {
  getDiscriminatorPropertyNames,
  getOpenApiEnumInfo,
  getPolymorphicModelRefs,
  inferResumableUploadIdPropertyName,
} from '@/lib/api-explorer/openapi-schema'
import { parameterToFormField } from '@/lib/api-explorer/request-form'
import {
  formatSchemaType,
  resolveResponseModels,
} from '@/lib/docs/references/schema-utils'
import { parseModelPropertiesFromSchema } from '@/lib/docs/references/parse-model'
import type { OpenApiSchema, OpenApiSpec } from '@/lib/api-explorer/types'

const closedStatus: OpenApiSchema = {
  title: 'Status',
  type: 'string',
  oneOf: [
    { title: 'active', type: 'string', enum: ['active'] },
    { title: 'inactive', type: 'string', enum: ['inactive'] },
  ],
}

const openStatus: OpenApiSchema = {
  title: 'Status',
  type: 'string',
  anyOf: [
    { title: 'active', type: 'string', enum: ['active'] },
    { title: 'inactive', type: 'string', enum: ['inactive'] },
    { type: 'string' },
  ],
}

describe('getOpenApiEnumInfo', () => {
  test('reads classic enum metadata', () => {
    const info = getOpenApiEnumInfo({
      type: 'string',
      enum: ['301', '302'],
      'x-enum-name': 'StatusCode',
      'x-enum-keys': ['MovedPermanently', 'Found'],
    })

    expect(info).toEqual({
      name: 'StatusCode',
      open: false,
      values: ['301', '302'],
      members: [
        { key: 'MovedPermanently', value: '301' },
        { key: 'Found', value: '302' },
      ],
    })
  })

  test('reads closed annotated oneOf enums', () => {
    const info = getOpenApiEnumInfo(closedStatus)
    expect(info?.name).toBe('Status')
    expect(info?.open).toBe(false)
    expect(info?.values).toEqual(['active', 'inactive'])
    expect(info?.members).toEqual([
      { key: 'active', value: 'active' },
      { key: 'inactive', value: 'inactive' },
    ])
  })

  test('reads open annotated anyOf enums', () => {
    const info = getOpenApiEnumInfo(openStatus)
    expect(info?.open).toBe(true)
    expect(info?.values).toEqual(['active', 'inactive'])
  })

  test('reads OpenAPI 3.1 const branches', () => {
    const info = getOpenApiEnumInfo({
      title: 'Browser',
      type: 'string',
      oneOf: [
        { title: 'Chrome', type: 'string', const: 'ch' },
        { title: 'Firefox', type: 'string', const: 'ff' },
      ],
    })
    expect(info?.members).toEqual([
      { key: 'Chrome', value: 'ch' },
      { key: 'Firefox', value: 'ff' },
    ])
  })

  test('does not treat model unions as enums', () => {
    expect(
      getOpenApiEnumInfo({
        oneOf: [
          { $ref: '#/components/schemas/attributeEmail' },
          { $ref: '#/components/schemas/attributeUrl' },
        ],
      }),
    ).toBeNull()
  })
})

describe('inferResumableUploadIdPropertyName', () => {
  test('infers fileId from a single binary file property', () => {
    expect(
      inferResumableUploadIdPropertyName({
        type: 'object',
        properties: {
          fileId: { type: 'string' },
          file: { type: 'string', format: 'binary' },
        },
      }),
    ).toBe('fileId')
  })

  test('does not infer an id when the binary has no matching Id property', () => {
    expect(
      inferResumableUploadIdPropertyName({
        type: 'object',
        properties: {
          code: { type: 'string', format: 'binary' },
          activate: { type: 'boolean' },
        },
      }),
    ).toBeUndefined()
  })

  test('does not infer an id when multiple binaries are present', () => {
    expect(
      inferResumableUploadIdPropertyName({
        type: 'object',
        properties: {
          file: { type: 'string', format: 'binary' },
          fileId: { type: 'string' },
          extra: { type: 'string', format: 'binary' },
        },
      }),
    ).toBeUndefined()
  })

  test('still honors legacy x-upload-id', () => {
    expect(
      inferResumableUploadIdPropertyName({
        type: 'object',
        properties: {
          customId: { type: 'string', 'x-upload-id': true },
          file: { type: 'string', format: 'binary' },
        },
      }),
    ).toBe('customId')
  })
})

describe('discriminator helpers', () => {
  const columnSchema: OpenApiSchema = {
    anyOf: [
      { $ref: '#/components/schemas/columnEmail' },
      { $ref: '#/components/schemas/columnUrl' },
    ],
    discriminator: {
      propertyName: 'type',
      mapping: {
        email: '#/components/schemas/columnEmail',
      },
      'x-mapping': {
        '#/components/schemas/columnEmail': { type: 'string', format: 'email' },
        '#/components/schemas/columnUrl': { type: 'string', format: 'url' },
      },
    },
  }

  test('derives property names from x-mapping conditions', () => {
    expect(getDiscriminatorPropertyNames(columnSchema).sort()).toEqual([
      'format',
      'type',
    ])
  })

  test('collects model refs from anyOf and x-mapping', () => {
    expect(getPolymorphicModelRefs(columnSchema).sort()).toEqual([
      'columnEmail',
      'columnUrl',
    ])
  })
})

describe('standard compound unions', () => {
  function branch(
    name: string,
    conditions: Record<string, string | number | boolean>,
  ): OpenApiSchema {
    return {
      allOf: [
        { $ref: `#/components/schemas/${name}` },
        {
          type: 'object',
          required: Object.keys(conditions),
          properties: Object.fromEntries(
            Object.entries(conditions).map(([key, value]) => [
              key,
              { enum: [value] },
            ]),
          ),
        },
      ],
    }
  }

  const union: OpenApiSchema = {
    anyOf: [
      branch('columnString', { type: 'string' }),
      branch('columnEmail', { type: 'string', format: 'email' }),
      branch('columnUrl', { type: 'string', format: 'url' }),
    ],
  }

  test('preserves broad and specialized model identities without a discriminator', () => {
    expect(getPolymorphicModelRefs(union)).toEqual([
      'columnString',
      'columnEmail',
      'columnUrl',
    ])
    expect(getDiscriminatorPropertyNames(union)).toEqual(['type', 'format'])
    expect(getOpenApiEnumInfo(union)).toBeNull()
  })

  test('prefers standard branches over stale legacy mappings and property names', () => {
    const schema: OpenApiSchema = {
      ...union,
      discriminator: {
        propertyName: 'legacy',
        'x-propertyNames': ['legacy'],
        mapping: { legacy: '#/components/schemas/wrong' },
        'x-mapping': { '#/components/schemas/wrong': { legacy: 'wrong' } },
      },
    }
    expect(getPolymorphicModelRefs(schema)).toEqual([
      'columnString',
      'columnEmail',
      'columnUrl',
    ])
    expect(getDiscriminatorPropertyNames(schema)).toEqual(['type', 'format'])
  })

  test('reads nested conjunctions and numeric and boolean conditions', () => {
    const schema: OpenApiSchema = {
      oneOf: [{ allOf: [branch('entry', { enabled: false, version: 2 })] }],
    }
    expect(getPolymorphicModelRefs(schema)).toEqual(['entry'])
    expect(getDiscriminatorPropertyNames(schema)).toEqual([
      'enabled',
      'version',
    ])
  })

  test('reads scalar const conditions but not a const on the whole member', () => {
    const member = branch('entry', { enabled: false })
    member.allOf![1]!.properties!.enabled = { type: 'boolean', const: false }
    expect(getDiscriminatorPropertyNames({ anyOf: [member] })).toEqual([
      'enabled',
    ])
    expect(getPolymorphicModelRefs({ anyOf: [member] })).toEqual(['entry'])
    member.const = { enabled: false }
    expect(getPolymorphicModelRefs({ anyOf: [member] })).toEqual([])
  })

  test('does not advertise optional, nullable, conflicting or ambiguous conditions', () => {
    const optional = branch('entry', { kind: 'entry' })
    optional.allOf![1]!.required = []
    const nullable = branch('entry', { kind: 'entry' })
    nullable.allOf![1]!.properties!.kind!.nullable = true
    const conflicting = branch('entry', { kind: 'entry' })
    conflicting.allOf!.push(branch('other', { kind: 'other' }).allOf![1]!)
    const multiple = branch('entry', { kind: 'entry' })
    multiple.allOf!.push({ $ref: '#/components/schemas/other' })
    for (const invalid of [optional, nullable, conflicting, multiple]) {
      const schema = { anyOf: [invalid] }
      expect(getDiscriminatorPropertyNames(schema)).toEqual([])
      expect(getPolymorphicModelRefs(schema)).toEqual([])
    }
    expect(
      getPolymorphicModelRefs({
        anyOf: [union.anyOf![0]!, { type: 'object' }],
      }),
    ).toEqual([])
  })

  test('keeps response alternatives, array variants and model links', () => {
    const spec = {
      components: {
        schemas: {
          columnString: {
            description: 'String',
            properties: { type: { type: 'string' } },
          },
          columnEmail: {
            description: 'Email',
            properties: { format: { type: 'string' } },
          },
          columnUrl: {
            description: 'URL',
            properties: { format: { type: 'string' } },
          },
        },
      },
    } as OpenApiSpec
    expect(resolveResponseModels(union, spec)).toEqual([
      { id: 'columnString', name: 'String' },
      { id: 'columnEmail', name: 'Email' },
      { id: 'columnUrl', name: 'URL' },
    ])
    const properties = parseModelPropertiesFromSchema(
      {
        properties: { columns: { type: 'array', items: union } },
      },
      spec,
      { version: 'cloud' },
    )
    expect(properties[0]?.typeKind).toBe('array')
    expect(properties[0]?.variantCount).toBe(3)
    expect(properties[0]?.variants?.map((model) => model.id)).toEqual([
      'columnString',
      'columnEmail',
      'columnUrl',
    ])
    const linked = parseModelPropertiesFromSchema(
      {
        properties: { column: { anyOf: [union.anyOf![1]!] } },
      },
      spec,
      { version: 'cloud', linkRelatedModels: true },
    )
    expect(linked[0]?.relatedModels).toBe(
      '[columnEmail](/docs/references/cloud/models/columnEmail)',
    )
  })
})

describe('UI mapping', () => {
  test('parameterToFormField uses annotated enum values', () => {
    const field = parameterToFormField({
      name: 'status',
      in: 'query',
      required: true,
      schema: closedStatus,
    })
    expect(field.kind).toBe('enum')
    expect(field.enumValues).toEqual(['active', 'inactive'])
    expect(field.enumOpen).toBeUndefined()
  })

  test('parameterToFormField marks open enums as suggestions', () => {
    const field = parameterToFormField({
      name: 'status',
      in: 'query',
      schema: openStatus,
    })
    expect(field.kind).toBe('enum')
    expect(field.enumOpen).toBe(true)
    expect(field.enumValues).toEqual(['active', 'inactive'])
  })

  test('formatSchemaType prefers schema title over value unions', () => {
    const spec = { components: { schemas: {} } } as OpenApiSpec
    expect(formatSchemaType(closedStatus, spec)).toBe('Status')
    expect(
      formatSchemaType(
        { type: 'string', enum: ['a', 'b'], 'x-enum-name': 'Legacy' },
        spec,
      ),
    ).toBe('Legacy')
  })
})
