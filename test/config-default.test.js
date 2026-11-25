'use strict';
// Valida la configuración por defecto del CRM FS (config/config-crm.default.json).
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const file = path.join(__dirname, '..', 'config', 'config-crm.default.json');
const cfg = JSON.parse(fs.readFileSync(file, 'utf8'));

const isPosInt = (n) => Number.isInteger(n) && n > 0;

test('valores monetarios de referencia', () => {
  assert.equal(cfg.uf, 41200);
  assert.equal(cfg.usd, 950);
});

test('umbrales de inactividad y vencimiento', () => {
  assert.equal(cfg.clientNoAct, 30);
  assert.equal(cfg.leadNoAct, 14);
  assert.equal(cfg.stalledDays, 30);
  assert.equal(cfg.offerExpiry, 15);
  assert.deepEqual(cfg.oppNoAct, { warn: 10, risk: 20 });
  assert.ok(cfg.oppNoAct.warn < cfg.oppNoAct.risk);
});

test('seguimiento: recordar < amarillo < rojo', () => {
  assert.deepEqual(cfg.followup, { remind: 3, yellow: 7, red: 14 });
  const f = cfg.followup;
  assert.ok(f.remind < f.yellow && f.yellow < f.red);
});

test('plazos por tipo de oportunidad son enteros positivos y ordenados', () => {
  assert.ok(cfg.plazos['One Shot'], 'falta el tipo "One Shot"');
  for (const [tipo, p] of Object.entries(cfg.plazos)) {
    for (const k of ['warn', 'risk', 'remind', 'yellow', 'red', 'stalled']) {
      assert.ok(isPosInt(p[k]), `${tipo}.${k} debe ser entero positivo`);
    }
    assert.ok(p.warn < p.risk, `${tipo}: warn < risk`);
    assert.ok(p.remind < p.yellow && p.yellow <= p.red, `${tipo}: remind < yellow <= red`);
    assert.ok(p.red <= p.stalled, `${tipo}: red <= stalled`);
  }
});

test('automatizaciones activas y metaBase válida', () => {
  for (const k of ['on', 'noTask', 'idle', 'follow', 'stage']) {
    assert.ok(cfg.auto[k] === 0 || cfg.auto[k] === 1, `auto.${k} debe ser 0 o 1`);
  }
  assert.equal(cfg.metaBase, 'factura');
});

test('sin claves ni secretos en la configuración por defecto', () => {
  const raw = fs.readFileSync(file, 'utf8').toLowerCase();
  for (const w of ['apikey', 'api_key', 'password', 'secret', 'token', 'sk-ant']) {
    assert.ok(!raw.includes(w), `no debe contener "${w}"`);
  }
});
