#!/usr/bin/env node
/**
 * Validate all operator YAML files against:
 *  1. JSON Schema (structure)
 *  2. Business rules from §14a EnWG BK8-22/010-A:
 *     - Time slots must be contiguous and cover exactly 00:00–24:00
 *     - HT must be active ≥ 2 hours/day
 *     - NT must be 10–40% of ST
 *     - HT must be ≤ 2× ST
 *     - id must match filename
 */

import { readFileSync, readdirSync } from "fs";
import { join, basename } from "path";
import { fileURLToPath } from "url";
import { parse as parseYaml } from "yaml";
import Ajv from "ajv";
import addFormats from "ajv-formats";

const __dirname = fileURLToPath(new URL(".", import.meta.url));
const ROOT = join(__dirname, "..");
const OPERATORS_DIR = join(ROOT, "operators");
const SCHEMA_PATH = join(ROOT, "schema", "operator.schema.json");

// ── helpers ──────────────────────────────────────────────────────────────────

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

function timeToMinutes(t) {
  const [h, m] = t.split(":").map(Number);
  return h * 60 + m;
}

function validateQuarter(slots, label, errors, country) {
  const sorted = [...slots].sort(
    (a, b) => timeToMinutes(a.from) - timeToMinutes(b.from)
  );

  // Coverage check: applies to all countries
  if (sorted[0].from !== "00:00") {
    errors.push(`${label}: first slot must start at 00:00 (got ${sorted[0].from})`);
  }
  if (sorted[sorted.length - 1].to !== "24:00") {
    errors.push(`${label}: last slot must end at 24:00 (got ${sorted[sorted.length - 1].to})`);
  }
  for (let i = 1; i < sorted.length; i++) {
    if (sorted[i].from !== sorted[i - 1].to) {
      errors.push(
        `${label}: gap between slot ${i - 1} (ends ${sorted[i - 1].to}) and slot ${i} (starts ${sorted[i].from})`
      );
    }
  }

  // §14a BK8-22/010-A rules — DE only
  if (country === "de") {
    const htMinutes = sorted
      .filter((s) => s.tariff === "HT")
      .reduce((acc, s) => acc + timeToMinutes(s.to) - timeToMinutes(s.from), 0);
    if (htMinutes > 0 && htMinutes < 120) {
      errors.push(`${label}: HT active only ${htMinutes} min/day, minimum is 120 min (2 h) per §14a BK8-22/010-A`);
    }

    const prices = { HT: [], ST: [], NT: [] };
    for (const s of sorted) {
      prices[s.tariff].push(s.price_ct_kwh_net);
    }

    if (prices.NT.length > 0 && prices.ST.length > 0) {
      const maxNT = Math.max(...prices.NT);
      const maxST = Math.max(...prices.ST);
      const ratio = maxNT / maxST;
      if (ratio < 0.10 - 0.001 || ratio > 0.40 + 0.001) {
        errors.push(
          `${label}: NT/ST ratio ${(ratio * 100).toFixed(1)}% must be 10–40% per §14a BK8-22/010-A`
        );
      }
    }

    if (prices.HT.length > 0 && prices.ST.length > 0) {
      const maxHT = Math.max(...prices.HT);
      const maxST = Math.max(...prices.ST);
      if (maxHT > maxST * 2 + 0.001) {
        errors.push(
          `${label}: HT (${maxHT} ct/kWh) exceeds 2× ST (${maxST} ct/kWh) per §14a BK8-22/010-A`
        );
      }
    }
  }
}

// ── main ─────────────────────────────────────────────────────────────────────

const schema = JSON.parse(readFileSync(SCHEMA_PATH, "utf8"));
const ajv = new Ajv({ allErrors: true });
addFormats(ajv);
const validate = ajv.compile(schema);

const files = findOperatorFiles(OPERATORS_DIR);
if (files.length === 0) {
  console.error("No operator YAML files found in operators/<country>/");
  process.exit(1);
}

let hasErrors = false;

// Naming convention: operator filenames must use hyphens, not underscores
for (const { country, filename } of files) {
  if (filename.includes("_")) {
    console.error(`❌ ${country}/${filename}: filename must use hyphens, not underscores (rename to ${filename.replaceAll("_", "-")})`);
    hasErrors = true;
  }
}

for (const { country, filename, filePath } of files) {
  const label = `${country}/${filename}`;
  const expectedId = basename(filename, ".yaml");
  const errors = [];

  let data;
  try {
    data = parseYaml(readFileSync(filePath, "utf8"));
  } catch (e) {
    console.error(`❌ ${label}: YAML parse error – ${e.message}`);
    hasErrors = true;
    continue;
  }

  if (!validate(data)) {
    for (const err of validate.errors) {
      errors.push(`Schema: ${err.instancePath} ${err.message}`);
    }
  }

  if (data.id && data.id !== expectedId) {
    errors.push(`id "${data.id}" does not match filename "${expectedId}.yaml"`);
  }

  if (data.tariffs) {
    for (const [year, quarters] of Object.entries(data.tariffs)) {
      for (const [q, slots] of Object.entries(quarters)) {
        if (Array.isArray(slots)) {
          validateQuarter(slots, `${label}/${year}/${q}`, errors, country);
        }
      }
    }
  }

  if (errors.length > 0) {
    console.error(`\n❌ ${label}:`);
    for (const e of errors) console.error(`   • ${e}`);
    hasErrors = true;
  } else {
    console.log(`✅ ${label}`);
  }
}

if (hasErrors) {
  console.error("\nValidation failed.");
  process.exit(1);
} else {
  console.log("\nAll operator files valid.");
}
