#!/usr/bin/env node
/**
 * Build static JSON artifacts for the web frontend:
 *   dist/index.json             – lightweight list of all operators (with country)
 *   dist/operators/<country>/   – one JSON per operator (full data)
 */

import { readFileSync, readdirSync, mkdirSync, writeFileSync } from "fs";
import { join, basename } from "path";
import { fileURLToPath } from "url";
import { parse as parseYaml } from "yaml";

const __dirname = fileURLToPath(new URL(".", import.meta.url));
const ROOT = join(__dirname, "..");
const OPERATORS_DIR = join(ROOT, "operators");
const DIST_DIR = join(ROOT, "dist");
const DIST_OPERATORS_DIR = join(DIST_DIR, "operators");

mkdirSync(DIST_OPERATORS_DIR, { recursive: true });

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

const files = findOperatorFiles(OPERATORS_DIR).sort((a, b) =>
  `${a.country}/${a.filename}`.localeCompare(`${b.country}/${b.filename}`)
);

const index = [];

for (const { country, filename, filePath } of files) {
  const data = parseYaml(readFileSync(filePath, "utf8"));
  const id = basename(filename, ".yaml");

  mkdirSync(join(DIST_OPERATORS_DIR, country), { recursive: true });

  writeFileSync(
    join(DIST_OPERATORS_DIR, country, `${id}.json`),
    JSON.stringify(data, null, 2)
  );

  index.push({
    id,
    country,
    name: data.name,
    bdew_code: data.bdew_code ?? null,
    website: data.website,
    regions: data.regions ?? [],
    years: Object.keys(data.tariffs ?? {}).sort(),
  });

  console.log(`Built: ${country}/${id}.json`);
}

writeFileSync(
  join(DIST_DIR, "index.json"),
  JSON.stringify(
    {
      generated_at: new Date().toISOString(),
      count: index.length,
      operators: index,
    },
    null,
    2
  )
);

console.log(`\nIndex written: ${index.length} operators → dist/index.json`);
