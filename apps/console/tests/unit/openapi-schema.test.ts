import { describe, expect, test } from 'bun:test'
import {
  getDiscriminatorPropertyNames,
  getOpenApiEnumInfo,
  getPolymorphicModelRefs,
  inferResumableUploadIdPropertyName,
} from '@/lib/api-explorer/openapi-schema'
import { parameterToFormField } from '@/lib/api-explorer/request-form'
import { formatSchemaType } from '@/lib/docs/references/schema-utils'
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
