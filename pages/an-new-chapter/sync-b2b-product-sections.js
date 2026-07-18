#!/usr/bin/env node
/*
 * Sync full product-rendering sections from an-new-chapter-b2b.html into all
 * persona landing pages. This keeps gallery, nutrition label, pills, highlights,
 * price blocks, and product CTAs consistent without manually editing 8 files.
 *
 * Run from this directory:
 *   node sync-b2b-product-sections.js
 */

const fs = require('fs');
const path = require('path');

const dir = __dirname;
const baseFile = path.join(dir, 'an-new-chapter-b2b.html');
const base = fs.readFileSync(baseFile, 'utf8');

const ranges = [
  {
    name: 'Yeast Hero products and commitments',
    start: '<!-- ============================================================\n     NEW PRODUCTS\n     ============================================================ -->',
    end: '<!-- ============================================================\n     B2B FLOW\n     ============================================================ -->',
  },
  {
    name: 'Organic products',
    start: '<!-- ============================================================\n     ORGANIC PRODUCTS\n     ============================================================ -->',
    end: '<!-- ============================================================\n     B2B SUPPORT\n     ============================================================ -->',
  },
];

function extract(source, range) {
  const startIndex = source.indexOf(range.start);
  const endIndex = source.indexOf(range.end);

  if (startIndex === -1 || endIndex === -1 || endIndex <= startIndex) {
    throw new Error(`Cannot locate ${range.name} in source file.`);
  }

  return source.slice(startIndex, endIndex);
}

function replaceRange(source, range, replacement) {
  const startIndex = source.indexOf(range.start);
  const endIndex = source.indexOf(range.end);

  if (startIndex === -1 || endIndex === -1 || endIndex <= startIndex) {
    throw new Error(`Cannot locate ${range.name} in target file.`);
  }

  return source.slice(0, startIndex) + replacement + source.slice(endIndex);
}

const sharedSections = ranges.map((range) => [range, extract(base, range)]);
const targets = fs.readdirSync(dir)
  .filter((file) => /^an-new-chapter-b2b-.+\.html$/.test(file))
  .sort();

for (const file of targets) {
  const targetPath = path.join(dir, file);
  let html = fs.readFileSync(targetPath, 'utf8');

  for (const [range, sectionHtml] of sharedSections) {
    if (file === 'an-new-chapter-b2b-organic-store.html' && range.name === 'Yeast Hero products and commitments') {
      continue;
    }

    html = replaceRange(html, range, sectionHtml);
  }

  fs.writeFileSync(targetPath, html);
  console.log(`Synced product sections: ${file}`);
}

console.log(`Done. Updated ${targets.length} persona pages.`);
