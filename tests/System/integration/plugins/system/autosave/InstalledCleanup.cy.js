/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// Run against the disposable System-test installation, with external CLI runners stopped.
describe('Installed autosave cleanup through Scheduled Tasks', () => {
  let owners;
  let taskId;
  let assetId;
  let originalRules;
  let originalParams;
  let extensionId;
  let suffix;
  const quote = (value) => `'${value.replaceAll("'", "''")}'`;
  const taskStatus = ({ response }) => {
    expect(response.statusCode).to.equal(200);
    expect(response.body.success).to.equal(true);
    expect(response.body.data).to.have.property('status');
    expect(response.body.data.status).to.be.oneOf([0, 123]);
    return response.body.data.status;
  };

  beforeEach(() => {
    owners = [];
    originalRules = null;
    originalParams = null;
    extensionId = null;
    suffix = `${Date.now()}-${Cypress._.random(10000, 99999)}`;
    cy.task('queryDB', "SELECT extension_id, params FROM #__extensions WHERE type = 'component' AND element = 'com_scheduler'")
      .then((rows) => {
        expect(rows).to.have.length(1);
        extensionId = Number(rows[0].extension_id);
        originalParams = rows[0].params;
        const params = JSON.parse(originalParams || '{}');
        params.lazy_scheduler = { ...params.lazy_scheduler, enabled: 0 };
        params.webcron = { ...params.webcron, enabled: 0 };
        return cy.task('queryDB', `UPDATE #__extensions SET params = ${quote(JSON.stringify(params))} WHERE extension_id = ${extensionId}`);
      });
    cy.task('queryDB', "SELECT t.id, t.asset_id, a.name, a.rules, p.name AS parent FROM #__scheduler_tasks t JOIN #__assets a ON a.id = t.asset_id JOIN #__assets p ON p.id = a.parent_id WHERE t.type = 'autosave.cleanup'")
      .then((rows) => {
        expect(rows).to.have.length(1);
        taskId = Number(rows[0].id);
        assetId = Number(rows[0].asset_id);
        expect(rows[0].name).to.equal(`com_scheduler.task.${taskId}`);
        expect(rows[0].parent).to.equal('com_scheduler');
        originalRules = rows[0].rules;
      });
  });

  afterEach(() => {
    if (originalRules !== null) cy.task('queryDB', `UPDATE #__assets SET rules = ${quote(originalRules)} WHERE id = ${assetId}`);
    owners.forEach((owner) => cy.task('queryDB', `DELETE FROM #__autosave_drafts WHERE owner_id = ${owner}`));
    if (originalParams !== null) cy.task('queryDB', `UPDATE #__extensions SET params = ${quote(originalParams)} WHERE extension_id = ${extensionId}`);
  });

  it('continues a bounded backlog and leaves live empty and payload rows intact', () => {
    const username = `autosave-cleanup-runner-${suffix}`;
    cy.db_createUser({ username, email: `${username}@example.com`, group_id: 8, params: '{"allowTourAutoStart":0}' });
    cy.doAdministratorLogin(username, 'test', false);
    cy.task('queryDB', 'SELECT COUNT(*) AS n FROM #__autosave_drafts WHERE expires_at <= CURRENT_TIMESTAMP')
      .then((rows) => expect(Number(rows[0].n), 'clean disposable cleanup baseline').to.equal(0));
    for (let bucket = 0; bucket < 18; bucket += 1) {
      const ownerName = `autosave-cleanup-${suffix}-${bucket}`;
      cy.db_createUser({ username: ownerName, email: `${ownerName}@example.com` }).then((id) => {
        const owner = Number(id);
        owners.push(owner);
        const count = Math.min(64, 1101 - bucket * 64);
        const values = Array.from({ length: count }, (_, slot) => {
          const index = bucket * 64 + slot;
          const draftId = (index + 1).toString(16).padStart(32, '0');
          const group = index % 4;
          const payload = group === 0 || group === 3 ? 'NULL' : quote('{"title":"expired"}');
          const created = ['1999-12-31 00:00:00', '1999-12-20 00:00:00', '1999-12-02 00:00:00', '1999-12-20 00:00:00'][group];
          const updated = group === 1 ? '1999-12-25 00:00:00' : '1999-12-31 00:00:00';
          return `('${draftId}', ${owner}, ${slot}, 'com_content.article', '42', '[]', 1, '{"title":{"control":"text"}}', ${group === 0 ? 0 : 1}, ${group === 3 ? 1 : 0}, ${payload}, '${created}', '${updated}', '2000-01-01 00:00:00')`;
        });
        return cy.task('queryDB', `INSERT INTO #__autosave_drafts (draft_id, owner_id, owner_slot, context, target_id, scope, schema_version, fields, client_revision, retired_revision, payload, created_at, updated_at, expires_at) VALUES ${values.join(',')}`);
      });
    }
    cy.then(() => {
      const owner = owners[17];
      return cy.task('queryDB', `INSERT INTO #__autosave_drafts (draft_id, owner_id, owner_slot, context, scope, schema_version, fields, payload, created_at, updated_at, expires_at) VALUES ('fffffffffffffffffffffffffffffff1', ${owner}, 62, 'com_content.article', '[]', 1, '{}', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, '2099-01-01 00:00:00'), ('fffffffffffffffffffffffffffffff2', ${owner}, 63, 'com_content.article', '[]', 1, '{}', '{"title":"live"}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, '2099-01-01 00:00:00')`);
    });
    cy.intercept('GET', '**/administrator/index.php*plugin=RunSchedulerTest*').as('runCleanup');
    cy.visit('/administrator/index.php?option=com_scheduler&view=tasks&filter=');
    cy.location('search').should('include', 'option=com_scheduler');
    cy.get('#filter_search').should('be.visible');
    cy.searchForItem('Autosave Retention Cleanup');
    cy.then(() => cy.get(`button[data-scheduler-run][data-id="${taskId}"]`).should('be.enabled').click());
    cy.wait('@runCleanup', { timeout: 30000 }).then(taskStatus).should('equal', 123);
    cy.then(() => cy.task('queryDB', `SELECT COUNT(*) AS n FROM #__autosave_drafts WHERE owner_id IN (${owners.join(',')}) AND expires_at <= CURRENT_TIMESTAMP`))
      .then((rows) => {
        // A slow database can hit the soft time budget before the row cap.
        expect(Number(rows[0].n)).to.be.within(101, 1100);
      });
    const finish = (attempt = 0) => {
      expect(attempt, 'bounded native continuation attempts').to.be.lessThan(12);
      cy.document().then((previousDocument) => {
        cy.get('header.joomla-dialog-header button.button-close').click();
        cy.document().should((document) => expect(document, 'native dialog reload').to.not.equal(previousDocument));
      });
      cy.location('search').should('include', 'option=com_scheduler');
      cy.then(() => cy.get(`button[data-scheduler-run][data-id="${taskId}"]`).should('be.enabled').click());
      return cy.wait('@runCleanup', { timeout: 30000 }).then(taskStatus).then((status) => {
        if (status === 123) return finish(attempt + 1);
        expect(status).to.equal(0);
        return undefined;
      });
    };
    finish();
    cy.then(() => cy.task('queryDB', `SELECT draft_id, payload FROM #__autosave_drafts WHERE owner_id IN (${owners.join(',')}) ORDER BY draft_id`))
      .then((rows) => {
        expect(rows).to.have.length(2);
        expect(rows[0].draft_id).to.equal('fffffffffffffffffffffffffffffff1');
        expect(rows[0].payload).to.equal(null);
        expect(JSON.parse(rows[1].payload)).to.deep.equal({ title: 'live' });
      });
  });

  it('honours an explicit task permission denial for an administrator who did not create it', () => {
    const username = `autosave-denied-runner-${suffix}`;
    cy.db_createUser({ username, email: `${username}@example.com`, group_id: 7, params: '{"allowTourAutoStart":0}' }).then((id) => {
      owners.push(Number(id));
    });
    cy.then(() => cy.task('queryDB', `UPDATE #__assets SET rules = '{"core.testrun":{"7":false}}' WHERE id = ${assetId}`));
    cy.doAdministratorLogin(username, 'test', false);
    cy.visit('/administrator/index.php?option=com_scheduler&view=tasks&filter=');
    cy.location('search').should('include', 'option=com_scheduler');
    cy.get('#filter_search').should('be.visible');
    cy.searchForItem('Autosave Retention Cleanup');
    cy.then(() => cy.get(`button[data-scheduler-run][data-id="${taskId}"]`).should('be.disabled'));
    cy.window().then((win) => cy.request({
      url: `/administrator/index.php?option=com_ajax&format=json&plugin=RunSchedulerTest&group=system&id=${taskId}`,
      headers: { 'X-CSRF-Token': win.Joomla.getOptions('csrf.token') },
      failOnStatusCode: false,
    })).its('body.success').should('equal', false);
  });
});
