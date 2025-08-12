/*
 * Look into all local files, and collect unique keys.
 * Ensure fallback locale (English) has translation for all keys.
 */

import { readdir, readFile } from "fs/promises";
import { join } from "path";

const translationsPath = join(
  __dirname,
  "../../../app/config/locale/translations",
);
const fallbackLocale = "en.json";

async () => {
  try {
    const files = await readdir(translationsPath).filter((file) =>
      file.endsWith(".json"),
    );

    if (files.length === 0) {
      console.error("No translation files found in ", translationsPath);
      process.exit(1);
    }

    // Check if fallback locale exists
    if (!files.includes(fallbackLocale)) {
      console.error(`Fallback locale file ${fallbackLocale} not found`);
      process.exit(1);
    }

    // Collect all unique keys from all translation files
    const allKeys = new Set();

    for (const file of files) {
      const filePath = join(translationsPath, file);
      const content = await readFile(filePath, "utf8");
      const translations = JSON.parse(content);

      // Add all keys from this file
      Object.keys(translations).forEach((key) => allKeys.add(key));
    }

    // Read fallback locale
    const fallbackPath = join(translationsPath, fallbackLocale);
    const fallbackContent = await readFile(fallbackPath, "utf8");
    const fallbackTranslations = JSON.parse(fallbackContent);

    // Check for missing keys in fallback locale
    const missingKeys = [];
    const fallbackKeys = new Set(Object.keys(fallbackTranslations));

    for (const key of allKeys) {
      if (!fallbackKeys.has(key)) {
        missingKeys.push(key);
      }
    }

    // Report results
    console.log(
      `Found ${files.length} translation files in ${translationsPath}`,
    );
    console.log(`Total unique keys found across all locales: ${allKeys.size}`);
    console.log(
      `Keys in fallback locale (${fallbackLocale}): ${fallbackKeys.size}`,
    );

    if (missingKeys.length > 0) {
      console.error(
        `\nERROR: Fallback locale (${fallbackLocale}) is missing ${missingKeys.length} key(s):`,
      );
      missingKeys.sort().forEach((key) => {
        console.error(`  - ${key}`);
      });
      console.error(
        `\nTo fix this issue, add the missing keys to ${translationsPath}/${fallbackLocale}`,
      );
      process.exit(1);
    } else {
      console.log(
        `\nSUCCESS: Fallback locale (${fallbackLocale}) contains all required keys.`,
      );
      console.log(
        `All ${allKeys.size} translation keys are present in the fallback locale.`,
      );
      process.exit(0);
    }
  } catch (error) {
    console.error("Unexpected error: ", error.message);
    process.exit(1);
  }
};
