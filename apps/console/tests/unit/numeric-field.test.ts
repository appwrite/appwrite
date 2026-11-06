import { describe, expect, test } from 'bun:test'
import { parseNumericField, settleNumericField } from '@/lib/numeric-field'

describe('parseNumericField', () => {
  test('reports an empty field as nothing typed yet', () => {
    // The firewall rate limit fields rebuilt their value from
    // `Number(event.target.value) || 1`, so clearing the field wrote a 1 straight back and
    // the last digit could never be deleted.
    expect(parseNumericField('')).toBeUndefined()
    expect(parseNumericField('   ')).toBeUndefined()
    expect(parseNumericField('abc')).toBeUndefined()
  })

  test('keeps a typed zero', () => {
    expect(parseNumericField('0')).toBe(0)
  })

  test('reads the number the user typed', () => {
    expect(parseNumericField('2')).toBe(2)
    expect(parseNumericField(' 250 ')).toBe(250)
    expect(parseNumericField('-5')).toBe(-5)
  })
})

describe('settleNumericField', () => {
  test('keeps the previous value when the field is left empty', () => {
    expect(settleNumericField({ field: '', previous: 100, min: 1 })).toBe(100)
    expect(settleNumericField({ field: 'abc', previous: 100, min: 1 })).toBe(
      100,
    )
  })

  test('takes the typed value, including one below the old value', () => {
    // 1 -> (cleared) -> 2 is the sequence that used to be impossible.
    expect(settleNumericField({ field: '2', previous: 1, min: 1 })).toBe(2)
  })

  test('pulls a value outside the range back into it', () => {
    expect(settleNumericField({ field: '0', previous: 100, min: 1 })).toBe(1)
    expect(
      settleNumericField({ field: '9000', previous: 5, min: 1, max: 5 }),
    ).toBe(5)
  })

  test('reads min and max given as attribute strings', () => {
    expect(settleNumericField({ field: '0', previous: 100, min: '1' })).toBe(1)
    expect(
      settleNumericField({
        field: '400',
        previous: 302,
        min: '300',
        max: '399',
      }),
    ).toBe(399)
  })
})
