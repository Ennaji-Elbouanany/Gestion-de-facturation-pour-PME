import test from 'node:test'
import assert from 'node:assert/strict'
import { allows } from '../src/services/permissions.js'

test('permissions are denied until the server profile is loaded', () => {
  assert.equal(allows(undefined, 'payments.view'), false)
  assert.equal(allows({}, 'invoices.update', { status: 'draft' }), false)
})

test('employee draft editing never grants issued or archived invoice editing', () => {
  const permissions = { 'invoices.update': false, 'invoices.updateDraft': true }
  assert.equal(allows(permissions, 'invoices.update', { status: 'draft' }), true)
  for (const status of ['sent', 'paid', 'partial', 'overdue', 'cancelled']) {
    assert.equal(allows(permissions, 'invoices.update', { status }), false)
  }
  assert.equal(allows(permissions, 'invoices.update', { status: 'draft', archived_at: '2026-11-06' }), false)
})

test('administrators and accountants also need to restore archived documents', () => {
  assert.equal(allows({ 'invoices.update': true }, 'invoices.update', { status: 'sent' }), true)
  assert.equal(allows({ 'invoices.update': true }, 'invoices.update', { status: 'draft', archived_at: '2026-11-06' }), false)
})

test('archive actions remain limited to draft documents', () => {
  const permissions = { 'invoices.archive': true, 'invoices.restore': true }
  assert.equal(allows(permissions, 'invoices.archive', { status: 'draft' }), true)
  assert.equal(allows(permissions, 'invoices.restore', { status: 'draft', archived_at: '2026-11-06' }), true)
  assert.equal(allows(permissions, 'invoices.archive', { status: 'sent' }), false)
})

test('payment and financial dashboard capabilities are independent of invoice access', () => {
  const permissions = { 'invoices.view': true, 'payments.view': false, 'dashboard.financial': false }
  assert.equal(allows(permissions, 'invoices.view'), true)
  assert.equal(allows(permissions, 'payments.view'), false)
  assert.equal(allows(permissions, 'dashboard.financial'), false)
})

test('read-only accountants can inspect quotes but cannot edit their lines', () => {
  const permissions = { 'quotes.view': true, 'quotes.update': false, 'quotes.convert': true }
  assert.equal(allows(permissions, 'quotes.view'), true)
  assert.equal(allows(permissions, 'quotes.update', { status: 'draft' }), false)
  assert.equal(allows(permissions, 'quotes.convert', { status: 'accepted' }), true)
  assert.equal(allows(permissions, 'quotes.convert', { status: 'converted' }), false)
  assert.equal(allows(permissions, 'quotes.convert', { status: 'draft', archived_at: '2026-11-06' }), false)
})
