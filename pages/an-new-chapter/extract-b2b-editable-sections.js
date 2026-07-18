#!/usr/bin/env node
/*
 * Extract editable B2B copy sections into partial files for review.
 *
 * Output:
 *   b2b-editable-sections/<page-slug>/<order>-<section>.html
 *
 * These partials intentionally exclude product sections so product shortcode
 * rendering stays synced by sync-b2b-product-sections.js.
 */

const fs = require('fs');
const path = require('path');

const dir = __dirname;
const outputDir = path.join(dir, 'b2b-editable-sections');

const sectionIds = [
  'anc-hero',
  'anc-b2b-fit',
  'anc-b2b-flow',
  'anc-b2b-support',
  'anc-register',
  'anc-faq',
  'anc-social',
  'anc-cta-band',
];

const shortcodeSections = [
  {
    id: 'anc-qc',
    filename: '03-anc-qc-shortcode.html',
    pattern: /\[certifications id="anc-qc"\][\s\S]*?\[\/certifications\]/,
  },
];

function sectionPattern(id) {
  return new RegExp(`<section id="${id}"[\\s\\S]*?<\\/section>`);
}

function pageSlug(file) {
  return file.replace(/\.html$/, '');
}

function writePartial(pageDir, filename, content) {
  fs.writeFileSync(path.join(pageDir, filename), content.trimEnd() + '\n');
}

const pages = fs.readdirSync(dir)
  .filter((file) => /^an-new-chapter-b2b(?:-.+)?\.html$/.test(file))
  .sort();

fs.mkdirSync(outputDir, { recursive: true });

for (const file of pages) {
  const html = fs.readFileSync(path.join(dir, file), 'utf8');
  const pageDir = path.join(outputDir, pageSlug(file));
  fs.mkdirSync(pageDir, { recursive: true });

  for (const existing of fs.readdirSync(pageDir)) {
    if (existing.endsWith('.html')) {
      fs.unlinkSync(path.join(pageDir, existing));
    }
  }

  let order = 1;
  for (const id of sectionIds) {
    if (id === 'anc-b2b-flow') {
      for (const item of shortcodeSections) {
        const match = html.match(item.pattern);
        if (match) {
          writePartial(pageDir, item.filename, match[0]);
          order += 1;
        }
      }
    }

    const match = html.match(sectionPattern(id));
    if (!match) continue;
    const filename = `${String(order).padStart(2, '0')}-${id}.html`;
    writePartial(pageDir, filename, match[0]);
    order += 1;
  }
}

fs.writeFileSync(path.join(outputDir, 'README.md'), [
  '# B2B Editable Sections',
  '',
  'Edit the partial HTML files in each page folder, then run:',
  '',
  '```bash',
  'node apply-b2b-editable-sections.js',
  '```',
  '',
  'Product sections are intentionally not extracted here. Keep product rendering synced with:',
  '',
  '```bash',
  'node sync-b2b-product-sections.js',
  '```',
  '',
  'The `03-anc-qc-shortcode.html` file maps to `[certifications id="anc-qc"]...[/certifications]` in the page.',
  '',
].join('\n'));

console.log(`Extracted editable sections for ${pages.length} pages into ${outputDir}`);
