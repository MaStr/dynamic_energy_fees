#!/usr/bin/env node
/**
 * Find operators missing tariff data for the next calendar year.
 * Outputs JSON to stdout: { nextYear, total, missing[] }
 *
 * Called by .github/workflows/missing-tariffs.yml
 */

import { readFileSync, readdirSync } from "fs";
import { join, basename } from "path";
import { fileURLToPath } from "url";
import { parse as parseYaml } from "yaml";

const __dirname = fileURLToPath(new URL(".", import.meta.url));
const ROOT = join(__dirname, "..");
const OPERATORS_DIR = join(ROOT, "operators");
const nextYear = String(new Date().getFullYear() + 1);

function findOperatorFiles(dir, country = null) {
  const results = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const fullPath = join(dir, entry.name);
    if (entry.isDirectory()) {
      results.push(...findOperatorFiles(fullPath, entry.name));
    } else if (entry.name.endsWith(".yaml") && country) {
      results.push({ country, filename: entry.name, filePath: fullPath });
    }
  }
  return results;
}

const files = findOperatorFiles(OPERATORS_DIR);
const missing = [];

for (const { country, filename, filePath } of files) {
  const data = parseYaml(readFileSync(filePath, "utf8"));
  const years = Object.keys(data.tariffs ?? {});
  if (!years.includes(nextYear)) {
    missing.push({
      id: data.id,
      name: data.name,
      country,
      file: `operators/${country}/${basename(filename)}`,
      website: data.website,
    });
  }
}

console.log(JSON.stringify({ nextYear, total: files.length, missing }));
