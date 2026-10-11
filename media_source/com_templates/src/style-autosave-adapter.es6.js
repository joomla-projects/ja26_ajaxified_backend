const isPlainObject = (value) => value !== null
  && typeof value === 'object'
  && !Array.isArray(value)
  && Object.getPrototypeOf(value) === Object.prototype;
const dangerous = new Set(['__proto__', 'prototype', 'constructor']);

const validateSchema = (schema) => {
  if (
    !isPlainObject(schema)
    || !/^[a-f0-9]{64}$/.test(schema.fingerprint)
    || !Array.isArray(schema.fields)
    || schema.fields.length > 32
  ) {
    throw new TypeError('Invalid Template Style schema.');
  }

  const paths = new Set();
  const fields = schema.fields.map((field) => {
    if (
      !isPlainObject(field)
      || !Array.isArray(field.path)
      || field.path.length !== 2
      || field.path[0] !== 'params'
      || field.path.some((part) => typeof part !== 'string'
        || dangerous.has(part)
        || !/^[A-Za-z][A-Za-z0-9_-]{0,47}$/.test(part))
      || typeof field.id !== 'string'
      || !field.id
      || !['string', 'boolean', 'enum', 'strings'].includes(field.kind)
    ) {
      throw new TypeError('Invalid Template Style schema field.');
    }

    const key = field.path.join('\0');

    if (paths.has(key)) {
      throw new TypeError('Duplicate Template Style schema path.');
    }
    paths.add(key);

    if (
      field.kind === 'string'
      && (!Number.isInteger(field.maxLength) || field.maxLength < 1 || field.maxLength > 4096)
    ) {
      throw new TypeError('Invalid Template Style string bound.');
    }

    if (
      ['enum', 'strings'].includes(field.kind)
      && (!Array.isArray(field.values)
        || !field.values.length
        || field.values.length > 64
        || field.values.some((value) => typeof value !== 'string'))
    ) {
      throw new TypeError('Invalid Template Style enum.');
    }

    if (
      field.kind === 'strings'
      && (!Number.isInteger(field.maxItems) || field.maxItems < 1 || field.maxItems > 50)
    ) {
      throw new TypeError('Invalid Template Style collection bound.');
    }

    return Object.freeze({
      ...field,
      path: Object.freeze([...field.path]),
      values: field.values ? Object.freeze([...field.values]) : undefined,
    });
  });

  return Object.freeze({
    fingerprint: schema.fingerprint,
    fields: Object.freeze(fields),
  });
};

const readValue = (field, controls) => {
  if (field.kind === 'boolean') {
    return controls[0].checked;
  }

  if (field.kind === 'strings') {
    return [...controls[0].selectedOptions].map((option) => option.value);
  }

  if (field.kind === 'enum' && controls.length > 1) {
    return controls.find((control) => control.checked)?.value ?? '';
  }

  return controls[0].value;
};

const writeValue = (field, controls, value) => {
  if (field.kind === 'boolean') {
    controls[0].checked = value;
  } else if (field.kind === 'strings') {
    [...controls[0].options].forEach((option) => {
      option.selected = value.includes(option.value);
    });
  } else if (field.kind === 'enum' && controls.length > 1) {
    controls.forEach((control) => {
      control.checked = control.value === value;
    });
  } else {
    controls[0].value = value;
  }
};

const validDynamicValue = (field, value) => {
  if (field.kind === 'boolean') return typeof value === 'boolean';
  if (field.kind === 'string') return typeof value === 'string' && Array.from(value).length <= field.maxLength;
  if (field.kind === 'enum') return typeof value === 'string' && field.values.includes(value);
  return Array.isArray(value)
    && value.length <= field.maxItems
    && value.every((item) => field.values.includes(item));
};

export default class StyleAutosaveAdapter {
  constructor({ descriptor, form, fields, dynamicFields, schema }) {
    this.descriptor = Object.freeze({ ...descriptor });
    this.form = form;
    this.fields = fields;
    this.dynamicFields = dynamicFields;
    this.schema = validateSchema(schema);
    this.callback = null;
    this.listener = () => this.changed();
    this.baseline = null;
  }

  getDescriptor() {
    return this.descriptor;
  }

  controls() {
    return [this.fields.title, ...Object.values(this.dynamicFields).flat()];
  }

  snapshot() {
    const payload = {
      title: this.fields.title.value,
      schemaFingerprint: this.schema.fingerprint,
      params: {},
    };

    this.schema.fields.forEach((field) => {
      payload.params[field.path[1]] = readValue(field, this.dynamicFields[field.path[1]]);
    });

    return payload;
  }

  initializeBaseline() {
    this.baseline = this.snapshot();
    return this;
  }

  capture() {
    this.baseline = this.snapshot();
    return structuredClone(this.baseline);
  }

  subscribe(callback) {
    this.callback = callback;
    this.controls().forEach((control) => {
      control.addEventListener('input', this.listener);
      control.addEventListener('change', this.listener);
    });

    return () => this.unsubscribe();
  }

  unsubscribe() {
    this.controls().forEach((control) => {
      control.removeEventListener('input', this.listener);
      control.removeEventListener('change', this.listener);
    });
    this.callback = null;
  }

  changed() {
    if (!this.callback) {
      return;
    }

    const next = this.snapshot();

    if (JSON.stringify(next) !== JSON.stringify(this.baseline)) {
      this.baseline = next;
      this.callback();
    }
  }

  apply(payload) {
    this.assertPayload(payload);
    this.fields.title.value = payload.title;
    this.fields.title.dispatchEvent(new Event('change', { bubbles: true }));

    this.schema.fields.forEach((field) => {
      const controls = this.dynamicFields[field.path[1]];
      writeValue(field, controls, payload.params[field.path[1]]);
      controls.forEach((control) => control.dispatchEvent(new Event('change', { bubbles: true })));
    });

    this.baseline = this.snapshot();
  }

  assertPayload(payload) {
    const keys = ['title', 'schemaFingerprint', 'params'];

    if (
      !isPlainObject(payload)
      || Object.keys(payload).length !== keys.length
      || !keys.every((key) => Object.hasOwn(payload, key))
      || typeof payload.title !== 'string'
      || Array.from(payload.title).length > 255
      || payload.schemaFingerprint !== this.schema.fingerprint
      || !isPlainObject(payload.params)
    ) {
      throw new TypeError('Invalid Template Style recovery payload.');
    }

    const expected = this.schema.fields.map((field) => field.path[1]);

    if (
      Object.keys(payload.params).length !== expected.length
      || !expected.every((key) => Object.hasOwn(payload.params, key))
    ) {
      throw new TypeError('Invalid Template Style params.');
    }

    this.schema.fields.forEach((field) => {
      if (!validDynamicValue(field, payload.params[field.path[1]])) {
        throw new TypeError('Invalid Template Style param.');
      }
    });
  }

  isCurrent() {
    return this.form?.isConnected
      && this.controls().every((control) => control.isConnected && this.form.contains(control));
  }

  destroy() {
    this.unsubscribe();
    this.form = null;
  }
}

export { validateSchema };
