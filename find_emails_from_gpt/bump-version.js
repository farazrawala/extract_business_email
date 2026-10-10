#!/usr/bin/env node
/*
 * bump-version.js — bump the extension version (no dependencies).
 *   node bump-version.js patch|minor|major
 * Updates manifest.json (single source of truth), prepends a dated entry header to
 * CHANGELOG.md, and prints the new version.
 */
const fs = require('fs');
const path = require('path');

const kind = process.argv[2];
if (!['patch', 'minor', 'major'].includes(kind)) {
  console.error('Usage: node bump-version.js patch|minor|major');
  process.exit(1);
}

const manifestPath = path.join(__dirname, 'manifest.json');
const changelogPath = path.join(__dirname, 'CHANGELOG.md');

const raw = fs.readFileSync(manifestPath, 'utf8');
const manifest = JSON.parse(raw);
const m = /^(\d+)\.(\d+)\.(\d+)$/.exec(manifest.version || '');
if (!m) {
  console.error(`manifest.json version "${manifest.version}" is not MAJOR.MINOR.PATCH`);
  process.exit(1);
}
let [major, minor, patch] = m.slice(1).map(Number);
if (kind === 'major') { major++; minor = 0; patch = 0; }
else if (kind === 'minor') { minor++; patch = 0; }
else { patch++; }
const oldVersion = manifest.version;
const newVersion = `${major}.${minor}.${patch}`;

// Replace only the version string so the rest of the file's formatting is untouched.
const updated = raw.replace(/("version"\s*:\s*")[^"]*(")/, `$1${newVersion}$2`);
fs.writeFileSync(manifestPath, updated);

const d = new Date();
const date = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const entry = `## ${newVersion} – ${date}\n- \n\n`;

let changelog = fs.existsSync(changelogPath) ? fs.readFileSync(changelogPath, 'utf8') : '# Changelog\n\n';
const headerMatch = /^# [^\r\n]*(\r?\n)+/.exec(changelog); // CRLF-safe
changelog = headerMatch
  ? changelog.slice(0, headerMatch[0].length) + entry + changelog.slice(headerMatch[0].length)
  : '# Changelog\n\n' + entry + changelog;
fs.writeFileSync(changelogPath, changelog);

console.log(`${oldVersion} → ${newVersion}`);
console.log(newVersion);
