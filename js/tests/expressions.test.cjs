const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const babel = require('@babel/core');

function load() {
  let user = 2;
  const storage = new Map();
  const source = fs.readFileSync(path.join(__dirname, '../src/forum/expressions.js'), 'utf8');
  const compiled = babel.transformSync(source, {
    configFile: false, babelrc: false,
    presets: [['@babel/preset-env', { targets: { node: 'current' } }]],
  }).code;
  const exports = {};
  const app = { forum: { attribute: () => 'https://forum.example.test/api' }, session: { user: { id: () => user } } };
  vm.runInNewContext(compiled, {
    exports,
    require: (name) => { assert.equal(name, 'flarum/forum/app'); return app; },
    localStorage: { getItem: (key) => storage.get(key) || null, setItem: (key, value) => storage.set(key, value) },
  });
  return { api: exports, storage, user: (id) => { user = id; } };
}

test('kaomoji insertion escapes the active formatter syntax and preserves Unicode', () => {
  const { api } = load();
  assert.equal(api.escapeText('(*^▽^*)'), '\\(\\*\\^▽\\^\\*\\)');
  assert.equal(api.escapeText('¯\\_(ツ)_/¯'), '¯\\\\\\_\\(ツ\\)\\_/¯');
  assert.equal(api.tokenFor({ kind: 'unicode', text: '❤️' }), '❤️');
  assert.equal(api.tokenFor({ kind: 'text', text: ':)' }), '\\:\\)');
});

test('image tokens keep a stable ID and sticker mode adds paragraph boundaries', () => {
  const { api } = load();
  const item = { kind: 'image', id: 'a'.repeat(32), defaultMode: 'inline' };
  assert.equal(api.tokenFor(item), '[memoji=' + item.id + ']');
  assert.equal(api.tokenFor(item, 'sticker'), '\n\n[msticker=' + item.id + ']\n\n');
});

test('recent expressions are capped, deduplicated and isolated by account', () => {
  const { api, storage, user } = load();
  for (let i = 0; i < 35; i++) api.remember({ key: 'image:' + i.toString(16).padStart(32, '0') });
  assert.equal(api.recentKeys().length, 30);
  const recent = api.recentKeys()[10];
  api.remember({ key: recent });
  assert.equal(api.recentKeys()[0], recent);
  assert.equal(new Set(api.recentKeys()).size, 30);
  assert.ok([...storage.values()].every((value) => !value.includes('http')));
  user(3);
  assert.equal(api.recentKeys().length, 0);
  user(2);
  assert.equal(api.recentKeys().length, 30);
});

test('damaged or unexpected local state does not prevent opening the picker', () => {
  const { api, storage } = load();
  storage.set('expression-system:https://forum.example.test/api:2', '{not-json');
  assert.equal(api.recentKeys().length, 0);
  storage.set('expression-system:https://forum.example.test/api:2', JSON.stringify(['image:../../x', 'not-an-expression']));
  assert.equal(api.recentKeys().length, 0);
});
