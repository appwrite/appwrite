#!/usr/bin/env node

/**
 * Translation Validation Script
 * 
 * This script validates that all translation files in app/config/locale/translations
 * contain all the keys present in en.json (the reference file).
 * 
 * Usage: node validate-translations.js
 * 
 * Exit codes:
 *   0 - All translation files are complete
 *   1 - One or more translation files are missing keys
 */

const fs = require('fs');
const path = require('path');

const translationsDir = path.join(__dirname, 'translations');
const enFile = path.join(translationsDir, 'en.json');

// Load the English reference file
let enContent;
try {
    enContent = JSON.parse(fs.readFileSync(enFile, 'utf8'));
} catch (error) {
    console.error(`❌ Error reading reference file ${enFile}: ${error.message}`);
    process.exit(1);
}

const enKeys = Object.keys(enContent);
console.log(`✓ Reference file (en.json) has ${enKeys.length} keys\n`);

// Get all translation files
const files = fs.readdirSync(translationsDir)
    .filter(file => file.endsWith('.json') && file !== 'en.json')
    .sort();

console.log(`Validating ${files.length} translation files...\n`);

let hasErrors = false;
const filesWithMissingKeys = [];

// Validate each file
for (const file of files) {
    const filePath = path.join(translationsDir, file);
    
    try {
        const content = JSON.parse(fs.readFileSync(filePath, 'utf8'));
        const keys = Object.keys(content);
        
        // Find missing keys
        const missingKeys = enKeys.filter(key => !content.hasOwnProperty(key));
        
        if (missingKeys.length > 0) {
            hasErrors = true;
            filesWithMissingKeys.push({
                file,
                missingKeys,
                totalKeys: keys.length,
                missingCount: missingKeys.length
            });
        }
    } catch (error) {
        console.error(`❌ Error parsing ${file}: ${error.message}`);
        hasErrors = true;
    }
}

// Report results
if (hasErrors) {
    console.log(`\n❌ Found ${filesWithMissingKeys.length} file(s) with missing keys:\n`);
    
    for (const fileInfo of filesWithMissingKeys) {
        console.log(`📄 ${fileInfo.file}`);
        console.log(`   Has ${fileInfo.totalKeys} keys, missing ${fileInfo.missingCount} keys`);
        console.log(`   Missing keys:`);
        for (const key of fileInfo.missingKeys.slice(0, 10)) {
            console.log(`   - ${key}`);
        }
        if (fileInfo.missingKeys.length > 10) {
            console.log(`   ... and ${fileInfo.missingKeys.length - 10} more`);
        }
        console.log('');
    }
    
    console.log(`\nTotal missing keys: ${filesWithMissingKeys.reduce((sum, f) => sum + f.missingCount, 0)}\n`);
    process.exit(1);
} else {
    console.log('✅ All translation files are complete! No missing keys found.\n');
    process.exit(0);
}
