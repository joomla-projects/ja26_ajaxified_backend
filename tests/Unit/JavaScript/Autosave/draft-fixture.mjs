/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
import { readFile } from 'node:fs/promises';

const layout = await readFile('layouts/joomla/autosave/draft.php', 'utf8');

export const fixture = () => {
  const nodes = new Map();
  const element = (dataset = {}) => Object.assign(new EventTarget(), {
    dataset,
    hidden: true,
    children: [],
    attributes: {},
    options: [],
    setAttribute(name, value) { this.attributes[name] = value; },
    contains: () => false,
    replaceChildren() { this.options = []; },
  });
  // Use the current native layout's attributes; obsolete selectors deliberately have no element.
  for (const match of layout.matchAll(/data-draft-([a-z-]+)(?:="([^"]*)")?/g)) {
    if (['state', 'error'].includes(match[1])) continue;
    const selector = `[data-draft-${match[1]}${match[2] ? `="${match[2]}"` : ''}]`;
    if (!nodes.has(selector)) nodes.set(selector, [element({ description: 'Protected values:' })]);
  }
  nodes.set('[data-draft-state]', ['clean', 'detecting', 'offline', 'unavailable', 'dirty', 'preserving', 'preserved', 'conflict', 'discarded'].map((state) => element({ draftState: state })));
  nodes.set('[data-draft-error]', [element({ draftError: 'transport_failure service_unavailable' })]);
  const querySelectorAll = (selector) => nodes.get(selector) || [];
  const status = querySelectorAll('[data-draft-status]')[0];
  const control = Object.assign(element(), { id: 'title', value: 'Canonical title', type: 'text', tagName: 'INPUT', name: 'jform[title]' });
  const controls = [control];
  const metadata = [];
  const ui = Object.assign(new EventTarget(), { matches: () => true, querySelectorAll });
  const document = {
    readyState: 'complete',
    getElementById: (id) => ({ form, status: ui })[id] || controls.find((candidate) => candidate.id === id),
    createElement: (name) => Object.assign(element(), { tagName: name.toUpperCase(), remove() { metadata.splice(metadata.indexOf(this), 1); } }),
  };
  const form = Object.assign(new EventTarget(), {
    ownerDocument: document,
    isConnected: true,
    contains: (candidate) => controls.includes(candidate) && candidate.form === form,
    querySelectorAll: (selector) => {
      if (selector === '[id]') return controls;
      if (selector === '[data-draft-metadata]') return [...metadata];
      return querySelectorAll(selector);
    },
    appendChild: (child) => {
      child.form = form;
      metadata.push(child);
    },
    elements: { namedItem: () => ({ value: 'article.save' }) },
  });
  const configuration = {
    available: true,
    creating: false,
    formId: 'form',
    statusId: 'status',
    fields: { title: { id: 'title', name: 'jform[title]', control: 'text', label: 'Title' } },
    draftId: 'a'.repeat(32),
    version: 1,
    endpoint: 'http://localhost/administrator/index.php?option=com_autosave&format=json',
  };
  control.form = form;
  return { nodes, status, control, controls, form, element, document, configuration, window: new EventTarget() };
};
