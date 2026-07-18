#!/usr/bin/env node
/*
 * Apply edited partial files from b2b-editable-sections back into B2B pages.
 *
 * This only updates editable copy/framing sections. Product sections remain
 * managed by sync-b2b-product-sections.js.
 */

const fs = require('fs');
const path = require('path');

const dir = __dirname;
const sourceDir = path.join(dir, 'b2b-editable-sections');

const fileToSection = [
  ['01-anc-hero.html', 'anc-hero'],
  ['02-anc-b2b-fit.html', 'anc-b2b-fit'],
  ['04-anc-b2b-flow.html', 'anc-b2b-flow'],
  ['05-anc-b2b-support.html', 'anc-b2b-support'],
  ['06-anc-register.html', 'anc-register'],
  ['07-anc-faq.html', 'anc-faq'],
  ['08-anc-social.html', 'anc-social'],
  ['09-anc-cta-band.html', 'anc-cta-band'],
];

function sectionPattern(id) {
  return new RegExp(`<section id="${id}"[\\s\\S]*?<\\/section>`);
}

function replaceRequired(source, pattern, replacement, label) {
  if (!pattern.test(source)) {
    throw new Error(`Cannot locate ${label}`);
  }

  return source.replace(pattern, replacement.trimEnd());
}

if (!fs.existsSync(sourceDir)) {
  throw new Error(`Missing ${sourceDir}. Run extract-b2b-editable-sections.js first.`);
}

const pageDirs = fs.readdirSync(sourceDir)
  .filter((name) => fs.statSync(path.join(sourceDir, name)).isDirectory())
  .sort();

for (const pageName of pageDirs) {
  const htmlPath = path.join(dir, `${pageName}.html`);
  const pageDir = path.join(sourceDir, pageName);

  if (!fs.existsSync(htmlPath)) {
    console.warn(`Skip ${pageName}: missing page HTML`);
    continue;
  }

  let html = fs.readFileSync(htmlPath, 'utf8');

  const qcPath = path.join(pageDir, '03-anc-qc-shortcode.html');
  if (fs.existsSync(qcPath)) {
    html = replaceRequired(
      html,
      /\[certifications id="anc-qc"\][\s\S]*?\[\/certifications\]/,
      fs.readFileSync(qcPath, 'utf8'),
      `${pageName} anc-qc shortcode`
    );
  }

  for (const [filename, id] of fileToSection) {
    const partialPath = path.join(pageDir, filename);
    if (!fs.existsSync(partialPath)) continue;

    html = replaceRequired(
      html,
      sectionPattern(id),
      fs.readFileSync(partialPath, 'utf8'),
      `${pageName} ${id}`
    );
  }

  fs.writeFileSync(htmlPath, html);
  console.log(`Applied editable sections: ${pageName}.html`);
}

console.log(`Done. Applied editable sections for ${pageDirs.length} page folders.`);
