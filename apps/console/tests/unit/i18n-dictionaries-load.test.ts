import { describe, expect, test } from 'bun:test'
import {
  ensureLanguageDictionary,
  getLanguageDictionary,
} from '@/lib/i18n/dictionaries/load'
import { translateText } from '@/lib/i18n/translate'

describe('language dictionaries', () => {
  test('english never loads a dictionary and passes strings through', async () => {
    expect(await ensureLanguageDictionary('en')).toBeUndefined()
    expect(getLanguageDictionary('en')).toBeUndefined()
    expect(translateText('Error', 'en')).toBe('Error')
  })

  test('hebrew translates after the dictionary chunk loads', async () => {
    const dictionary = await ensureLanguageDictionary('he')
    expect(dictionary?.Error).toBe('שגיאה')
    expect(translateText('Error', 'he')).toBe('שגיאה')
    expect(translateText('Error', 'en')).toBe('Error')
  })
})
