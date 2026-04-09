/*!
 * Matomo - free/libre analytics platform
 *
 * McpServer UI tests.
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

describe('McpServer', function () {
    this.fixture = 'Piwik\\Tests\\Fixtures\\EmptySite';

    const settingsUrl = '?module=CoreAdminHome&action=generalSettings&idSite=1&period=day&date=yesterday#/McpServer';
    const connectUrl = '?module=McpServer&action=connect&idSite=1&period=day&date=yesterday';
    const settingsSelector = '#McpServerPluginSettings';
    const enabledCheckboxSelector = 'input[name="enable_mcp"]';
    const settingsSaveButtonSelector = `${settingsSelector} .pluginsSettingsSubmit`;
    const connectSelector = '.mcpServerConnect';

    function resetUserToSuperUser()
    {
        delete testEnvironment.idSitesViewAccess;
        delete testEnvironment.idSitesWriteAccess;
        delete testEnvironment.idSitesAdminAccess;
        delete testEnvironment.idSitesCapabilities;
        testEnvironment.testUseMockAuth = 1;
        testEnvironment.save();
    }

    function setViewUser()
    {
        testEnvironment.idSitesViewAccess = [1];
        delete testEnvironment.idSitesWriteAccess;
        delete testEnvironment.idSitesAdminAccess;
        delete testEnvironment.idSitesCapabilities;
        testEnvironment.testUseMockAuth = 1;
        testEnvironment.save();
    }

    async function waitForSettingsSection()
    {
        await page.waitForSelector(settingsSelector, { visible: true });
        await page.waitForSelector(enabledCheckboxSelector, { visible: true });
        await page.waitForNetworkIdle();
    }

    async function saveSettings()
    {
        await page.click(settingsSaveButtonSelector);
        await page.waitForSelector('.confirm-password-modal.open', { visible: true });
        await page.type('.confirm-password-modal input[name=currentUserPassword]', superUserPassword);
        await (await page.jQuery('.confirm-password-modal.open .modal-close:not(.modal-no):visible')).click();
        await page.waitForSelector('.confirm-password-modal.open', { hidden: true });
        await page.waitForNetworkIdle();
    }

    async function setMcpEnabled(enabled)
    {
        resetUserToSuperUser();
        await page.goto(settingsUrl);
        await waitForSettingsSection();

        const isChecked = await page.$eval(enabledCheckboxSelector, (el) => !!el.checked);

        if (isChecked !== enabled) {
            await page.click(`${enabledCheckboxSelector} + span`);
            await page.waitForTimeout(250);
            await saveSettings();
        }

        await page.goto(settingsUrl);
        await waitForSettingsSection();
        await page.mouse.move(-10, -10);
    }

    async function getConnectText()
    {
        await page.waitForSelector(connectSelector, { visible: true });
        return page.$eval(connectSelector, (el) => el.textContent.replace(/\s+/g, ' ').trim());
    }

    before(function () {
        testEnvironment.pluginsToLoad = ['McpServer'];
        resetUserToSuperUser();
    });

    after(function () {
        resetUserToSuperUser();
    });

    afterEach(function () {
        resetUserToSuperUser();
    });

    it('should display the plugin settings', async function () {
        await setMcpEnabled(false);

        expect(await page.screenshotSelector(settingsSelector)).to.matchImage('settings');
    });

    it('should show connect guidance for superusers when MCP is disabled', async function () {
        await setMcpEnabled(false);
        await page.goto(connectUrl);
        await page.waitForNetworkIdle();

        const text = await getConnectText();

        expect(text).to.contain('How to Connect to the MCP Server');
        expect(text).to.contain('Use this guide to connect any MCP client to your Matomo MCP Server.');
        expect(text).to.contain('MCP Server is currently disabled.');
        expect(text).to.contain('Enable it in Plugin Settings');
        expect(text).to.not.contain('Use this endpoint for MCP over HTTP:');
        expect(text).to.not.contain('Connect Your MCP Client');
        expect(text).to.not.contain('Troubleshooting');
    });

    it('should show contact-admin guidance for view users when MCP is disabled', async function () {
        await setMcpEnabled(false);
        setViewUser();

        await page.goto(connectUrl);
        await page.waitForNetworkIdle();

        const text = await getConnectText();

        expect(text).to.contain('How to Connect to the MCP Server');
        expect(text).to.contain('Use this guide to connect any MCP client to your Matomo MCP Server.');
        expect(text).to.contain('MCP Server is currently disabled.');
        expect(text).to.contain('Please contact your Matomo administrator');
        expect(text).to.not.contain('Enable it in Plugin Settings');
        expect(text).to.not.contain('Use this endpoint for MCP over HTTP:');
        expect(text).to.not.contain('Connect Your MCP Client');
        expect(text).to.not.contain('Troubleshooting');
    });

    it('should display the connect page when MCP is enabled', async function () {
        await setMcpEnabled(true);
        await page.goto(connectUrl);
        await page.waitForNetworkIdle();
        await page.waitForSelector(`${connectSelector} .card-action`, { visible: true });
        await page.mouse.move(-10, -10);

        expect(await page.screenshotSelector(connectSelector)).to.matchImage('connect_enabled');
    });
});
