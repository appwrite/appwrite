# Appwrite Translations

This directory contains translation files for all supported locales in Appwrite.

## Structure

- `en.json` - English translation (reference file, contains all keys)
- All other `.json` files - Translations for specific locales

## Translation Guidelines

### Key Coverage

All translation files **must** contain all the keys present in `en.json`. If a translation is not available for a specific key, the English text should be used as a fallback.

### File Format

- Files are JSON formatted with 4-space indentation
- Keys are sorted alphabetically to match the order in `en.json`
- Each file must end with a newline character

### Validation

To check if all translation files are complete and have all required keys:

```bash
cd app/config/locale
node validate-translations.js
```

This script will:
- ✅ Validate that all translation files contain all keys from `en.json`
- ✅ Report any missing keys per file
- ✅ Exit with code 0 if all files are complete, 1 if there are missing keys

## Adding New Translation Keys

When adding new features that require translations:

1. Add the new key(s) to `en.json` with the English text
2. Run the validation script to identify which files are missing the new key(s)
3. Add the key(s) to all other translation files:
   - If you have the proper translation, use it
   - If not, use the English text as a fallback

### Quick Fix for Missing Keys

You can use this Node.js script to automatically add missing keys with English fallbacks:

```javascript
const fs = require('fs');
const path = require('path');

const translationsDir = './translations';
const enContent = JSON.parse(fs.readFileSync(path.join(translationsDir, 'en.json'), 'utf8'));
const enKeys = Object.keys(enContent);

const files = fs.readdirSync(translationsDir)
    .filter(file => file.endsWith('.json') && file !== 'en.json');

for (const file of files) {
    const filePath = path.join(translationsDir, file);
    const content = JSON.parse(fs.readFileSync(filePath, 'utf8'));
    
    // Add missing keys
    const missingKeys = enKeys.filter(key => !content.hasOwnProperty(key));
    for (const key of missingKeys) {
        content[key] = enContent[key];
    }
    
    // Sort keys to match en.json order
    const sortedContent = {};
    enKeys.forEach(key => {
        if (content[key] !== undefined) {
            sortedContent[key] = content[key];
        }
    });
    
    fs.writeFileSync(filePath, JSON.stringify(sortedContent, null, 4) + '\n', 'utf8');
}
```

## Translation Keys by Category

Translation keys are organized by category using dot notation:

- `settings.*` - General settings (locale, direction, quotes)
- `emails.*` - Email templates (verification, recovery, invitations, etc.)
  - `emails.verification.*` - Account verification emails
  - `emails.magicSession.*` - Magic link login emails
  - `emails.sessionAlert.*` - Security alert emails
  - `emails.otpSession.*` - OTP login emails
  - `emails.mfaChallenge.*` - Multi-factor authentication emails
  - `emails.recovery.*` - Password recovery emails
  - `emails.csvExport.*` - CSV export notifications
  - `emails.invitation.*` - Team invitation emails
  - `emails.certificate.*` - SSL certificate alerts
- `sms.*` - SMS templates
- `locale.*` - Locale-specific strings
- `countries.*` - Country names
- `continents.*` - Continent names
- `mock` - Testing placeholder

## Supported Locales

This directory includes translations for 72 languages:

Afrikaans (af), Arabic-Morocco (ar-ma), Arabic (ar), Assamese (as), Azerbaijani (az), Belarusian (be), Bulgarian (bg), Bihari (bh), Bengali (bn), Bosnian (bs), Catalan (ca), Czech (cs), Danish (da), German (de), Greek (el), English (en), Esperanto (eo), Spanish (es), Persian (fa), Finnish (fi), Faroese (fo), French (fr), Irish (ga), Gujarati (gu), Hebrew (he), Hindi (hi), Croatian (hr), Hungarian (hu), Armenian (hy), Indonesian (id), Icelandic (is), Italian (it), Japanese (ja), Javanese (jv), Khmer (km), Kannada (kn), Korean (ko), Latin (la), Luxembourgish (lb), Lithuanian (lt), Latvian (lv), Malayalam (ml), Marathi (mr), Malay (ms), Norwegian Bokmål (nb), Nepali (ne), Dutch (nl), Norwegian Nynorsk (nn), Odia (or), Punjabi (pa), Polish (pl), Portuguese-Brazil (pt-br), Portuguese-Portugal (pt-pt), Romanian (ro), Russian (ru), Sanskrit (sa), Sindhi (sd), Sinhala (si), Slovak (sk), Slovenian (sl), Shona (sn), Albanian (sq), Swedish (sv), Tamil (ta), Telugu (te), Thai (th), Tagalog (tl), Turkish (tr), Ukrainian (uk), Urdu (ur), Vietnamese (vi), Chinese-China (zh-cn), Chinese-Taiwan (zh-tw).

## Contributing

When contributing translations:

1. Ensure all required keys are present
2. Use appropriate locale-specific formatting
3. Maintain consistency with existing translations
4. Test with the validation script before submitting
5. Keep translations culturally appropriate and professional
