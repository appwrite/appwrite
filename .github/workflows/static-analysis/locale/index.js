/*
 * Look into all local files, and collect unique keys.
 * Ensure fallback locale (English) has translation for all keys.
 * If configured as `const strict = true`, all locales will be checked to include all keys.
 */

import { readdir, readFile } from "fs/promises";
import { join, dirname } from "path";
import { fileURLToPath } from "url";

const config = {
  strict: false,
  fallbackLocale: "en.json",
};

(async () => {
  try {
    // Prepare current directory equivalent in ES modules
    const __filename = fileURLToPath(import.meta.url);
    const __dirname = dirname(__filename);

    const translationsPath = join(
      __dirname,
      "../../../../app/config/locale/translations",
    );

    const files = (await readdir(translationsPath)).filter((file) =>
      file.endsWith(".json"),
    );

    if (files.length === 0) {
      console.error("No translation files found in ", translationsPath);
      process.exit(1);
    }

    // Check if fallback locale exists
    if (!files.includes(config.fallbackLocale)) {
      console.error(`Fallback locale file ${config.fallbackLocale} not found`);
      process.exit(1);
    }

    console.log(
      `Found ${files.length} translation files in ${translationsPath}`,
    );

    // Collect all unique keys from all translation files
    const allKeys = new Set();

    for (const file of files) {
      const filePath = join(translationsPath, file);
      const content = await readFile(filePath, "utf8");
      const translations = JSON.parse(content);

      // Add all keys from this file
      Object.keys(translations).forEach((key) => allKeys.add(key));
    }

    console.log(`Total unique keys found across all locales: ${allKeys.size}`);

    const localesToCheck = [];
    if (config.strict) {
      localesToCheck.push(...files);
    } else {
      localesToCheck.push(config.fallbackLocale);
    }

    let errorsCount = 0;
    let missingLocaleCount = 0;

    for (const localeToCheck of localesToCheck) {
      // Read locale
      const path = join(translationsPath, localeToCheck);
      const content = await readFile(path, "utf8");
      const translations = JSON.parse(content);

      // Check for missing keys in the locale
      const keys = new Set(Object.keys(translations));
      console.log(`Keys in locale (${localeToCheck}): ${keys.size}`);

      const missingKeys = [];
      for (const key of allKeys) {
        if (!keys.has(key)) {
          missingKeys.push(key);
        }
      }

      if (missingKeys.length > 0) {
        console.error(
          `\nERROR: Fallback locale (${localeToCheck}) is missing ${missingKeys.length} key(s):`,
        );
        missingKeys.sort().forEach((key) => {
          console.error(`  - ${key}`);
        });
        console.error(
          `\nTo fix this issue, add the missing keys to ${translationsPath}/${localeToCheck}`,
        );
        errorsCount++;
        missingLocaleCount += missingKeys.length;
      } else {
        console.log(
          `\nSUCCESS: Fallback locale (${localeToCheck}) contains all ${allKeys.size} keys.`,
        );
      }
    }

    if (errorsCount > 0) {
      console.log(`\n${missingLocaleCount} locales missing found across ${errorsCount} locales.`);
      process.exit(1);
    }
  } catch (error) {
    console.error("Unexpected error.");
    console.error(error);
    process.exit(1);
  }
})();
