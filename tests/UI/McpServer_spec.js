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
    const maximumMcpAccessLevelSelector = 'select[name="maximum_mcp_access_level"]';
    const rawApiAccessScopeSelector = 'select[name="raw_api_access_scope"]';
    const settingsSaveButtonSelector = `${settingsSelector} .pluginsSettingsSubmit`;
    const connectSelector = '.mcpServerConnect';

    function resetUserToSuperUser()
    {
        delete testEnvironment.idSitesViewAccess;
        delete testEnvironment.idSitesWriteAccess;
        delete testEnvironment.idSitesAdminAccess;
        delete testEnvironment.idSitesCapabilities;
        delete testEnvironment.mockOAuth2PluginEnabled;
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

    async function isRawApiAccessScopeVisible()
    {
        return page.evaluate((selector) => {
            const element = document.querySelector(selector);

            if (!element) {
                return false;
            }

            return !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
        }, rawApiAccessScopeSelector);
    }

    async function configureMcp(
        enabled,
        maximumMcpAccessLevel = 'string:unlimited',
        rawApiAccessScope = 'string:partial',
        rawApiAccessLevels = []
    )
    {
        resetUserToSuperUser();
        await page.goto(settingsUrl);
        await waitForSettingsSection();

        const isEnabled = await page.$eval(enabledCheckboxSelector, (el) => !!el.checked);

        if (isEnabled !== enabled) {
            await page.click(`${enabledCheckboxSelector} + span`);
            await page.waitForTimeout(250);
            await saveSettings();

            await page.goto(settingsUrl);
            await waitForSettingsSection();
        }

        if (enabled) {
            await page.waitForSelector(maximumMcpAccessLevelSelector, { visible: true });
            await page.waitForSelector(rawApiAccessScopeSelector, { visible: true });
            const currentMaximumMcpAccessLevel = await page.$eval(maximumMcpAccessLevelSelector, (el) => el.value);
            const currentRawApiAccessScope = await page.$eval(rawApiAccessScopeSelector, (el) => el.value);
            let didChangeSetting = false;

            if (currentMaximumMcpAccessLevel !== maximumMcpAccessLevel) {
                await page.select(maximumMcpAccessLevelSelector, maximumMcpAccessLevel);
                didChangeSetting = true;
            }

            if (currentRawApiAccessScope !== rawApiAccessScope) {
                await page.select(rawApiAccessScopeSelector, rawApiAccessScope);
                didChangeSetting = true;
            }

            if (didChangeSetting) {
                await page.waitForTimeout(250);
                await saveSettings();
            }

            if (rawApiAccessScope === 'string:partial') {
                for (const level of ['read', 'create', 'update', 'delete']) {
                    const selector = `input[name="raw_api_access_${level}"]`;
                    const shouldBeEnabled = rawApiAccessLevels.includes(level);

                    await page.waitForSelector(selector, { visible: true });
                    const isEnabled = await page.$eval(selector, (el) => !!el.checked);

                    if (isEnabled !== shouldBeEnabled) {
                        await page.click(`${selector} + span`);
                        await page.waitForTimeout(250);
                        await saveSettings();
                    }
                }
            }
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

    it('should only show the enable checkbox when MCP is disabled', async function () {
        await configureMcp(false);

        expect(await page.$eval(enabledCheckboxSelector, (el) => !!el.checked)).to.equal(false);
        expect(await isRawApiAccessScopeVisible()).to.equal(false);
    });

    it('should display the plugin settings when MCP is enabled with partial API access', async function () {
        await configureMcp(true, 'string:view', 'string:partial', ['read']);

        expect(await isRawApiAccessScopeVisible()).to.equal(true);
        expect(await page.$eval(maximumMcpAccessLevelSelector, (el) => el.value)).to.equal('string:view');
        expect(await page.$eval(rawApiAccessScopeSelector, (el) => el.value)).to.equal('string:partial');
        expect(await page.$eval('input[name="raw_api_access_read"]', (el) => !!el.checked)).to.equal(true);
        expect(await page.$eval(`${settingsSelector} a[href*="module=McpServer"][href*="action=connect"]`, (el) => el.getAttribute('href')))
            .to.contain('idSite=1')
            .and.to.contain('period=day')
            .and.to.contain('date=yesterday');
        expect(await page.screenshotSelector(settingsSelector)).to.matchImage('settings');
    });

    it('should show connect guidance for superusers when MCP is disabled', async function () {
        await configureMcp(false);
        await page.goto(connectUrl);
        await page.waitForNetworkIdle();

        const text = await getConnectText();

        expect(text).to.contain('How to Connect to the MCP Server');
        expect(text).to.contain('Use this guide to connect any MCP client to your Matomo MCP Server.');
        expect(text).to.contain('MCP Server is currently disabled.');
        expect(text).to.contain('Enable it in General Settings');
        expect(await page.$eval(`${connectSelector} a[href*="module=CoreAdminHome"][href*="action=generalSettings"]`, (el) => el.getAttribute('href')))
            .to.contain('idSite=1')
            .and.to.contain('period=day')
            .and.to.contain('date=yesterday');
        expect(text).to.not.contain('Use this endpoint for MCP over HTTP:');
        expect(text).to.not.contain('Connect Your MCP Client');
        expect(text).to.not.contain('Troubleshooting');
    });

    it('should show contact-admin guidance for view users when MCP is disabled', async function () {
        await configureMcp(false);
        setViewUser();

        await page.goto(connectUrl);
        await page.waitForNetworkIdle();

        const text = await getConnectText();

        expect(text).to.contain('How to Connect to the MCP Server');
        expect(text).to.contain('Use this guide to connect any MCP client to your Matomo MCP Server.');
        expect(text).to.contain('MCP Server is currently disabled.');
        expect(text).to.contain('Please contact your Matomo administrator');
        expect(text).to.not.contain('Enable it in General Settings');
        expect(text).to.not.contain('Use this endpoint for MCP over HTTP:');
        expect(text).to.not.contain('Connect Your MCP Client');
        expect(text).to.not.contain('Troubleshooting');
    });

    it('should display the connect page when MCP is enabled', async function () {
        await configureMcp(true);
        await page.goto(connectUrl);
        await page.waitForNetworkIdle();
        await page.waitForSelector(connectSelector, { visible: true });
        await page.mouse.move(-10, -10);

        const text = await getConnectText();

        expect(text).to.contain('Use this guide to connect any MCP client to your Matomo MCP Server.');
        expect(text).to.contain('Manage MCP Server settings in General Settings.');
        expect(text).to.contain('A Matomo token_auth (used as a Bearer token)');
        expect(text).to.not.contain('OAuth2 is available for this MCP Server and is the recommended way to connect.');
        expect(text).to.not.contain('Recommended: Connect Using OAuth2');
        expect(text).to.not.contain('Alternative: Connect Using token_auth');
        expect(await page.$eval(`${connectSelector} a[href*="module=CoreAdminHome"][href*="action=generalSettings"]`, (el) => el.getAttribute('href')))
            .to.contain('idSite=1')
            .and.to.contain('period=day')
            .and.to.contain('date=yesterday');

        expect(await page.screenshotSelector(connectSelector)).to.matchImage('connect_enabled');
    });

    it('should hide the General Settings link for view users when MCP is enabled', async function () {
        await configureMcp(true);
        setViewUser();

        await page.goto(connectUrl);
        await page.waitForNetworkIdle();
        await page.waitForSelector(connectSelector, { visible: true });

        const text = await getConnectText();

        expect(text).to.contain('Use this guide to connect any MCP client to your Matomo MCP Server.');
        expect(text).to.not.contain('Manage MCP Server settings in General Settings.');
        expect(text).to.contain('A Matomo token_auth (used as a Bearer token)');
        expect(await page.$(`${connectSelector} a[href*="module=CoreAdminHome"][href*="action=generalSettings"]`)).to.equal(null);
    });

    it('should display OAuth2 client management guidance for superusers when the OAuth2 plugin is enabled', async function () {
        await configureMcp(true);
        testEnvironment.mockOAuth2PluginEnabled = 1;
        testEnvironment.save();

        await page.goto(connectUrl);
        await page.waitForNetworkIdle();
        await page.waitForSelector(connectSelector, { visible: true });
        await page.mouse.move(-10, -10);

        const text = await getConnectText();

        expect(text).to.contain('OAuth2 is available for this MCP Server and is the recommended way to connect.');
        expect(text).to.contain('Recommended: Connect Using OAuth2');
        expect(text).to.contain('Alternative: Connect Using token_auth');
        expect(text).to.contain('Choose an authentication method.');
        expect(text).to.contain('If your MCP client supports OAuth2, choose OAuth2 and complete the authorization flow using an OAuth2 client configured for your Matomo.');
        expect(text).to.contain('If you do not already have an OAuth2 client for your MCP client, create one in Administration -> Platform -> OAuth2 Clients first.');
        expect(text).to.contain('Administration -> Platform -> OAuth2 Clients');
        expect(text).to.contain('If using OAuth2, verify the client completed authorization successfully');
        expect(text).to.not.contain('Get a Matomo Token');
        expect(await page.$eval(`${connectSelector} a[href*="module=OAuth2"][href*="action=index"]`, (el) => el.getAttribute('href')))
            .to.contain('idSite=1')
            .and.to.contain('period=day')
            .and.to.contain('date=yesterday');
        expect(await page.$eval(`${connectSelector} a[href*="module=UsersManager"][href*="action=userSecurity"]`, (el) => el.getAttribute('href')))
            .to.contain('idSite=1')
            .and.to.contain('period=day')
            .and.to.contain('date=yesterday');

        expect(await page.screenshotSelector(connectSelector)).to.matchImage('connect_oauth2');
    });

    it('should display contact-superuser OAuth2 guidance for view users when the OAuth2 plugin is enabled', async function () {
        await configureMcp(true);
        testEnvironment.mockOAuth2PluginEnabled = 1;
        testEnvironment.save();
        setViewUser();

        await page.goto(connectUrl);
        await page.waitForNetworkIdle();
        await page.waitForSelector(connectSelector, { visible: true });

        const text = await getConnectText();

        expect(text).to.contain('OAuth2 is available for this MCP Server and is the recommended way to connect.');
        expect(text).to.contain('Recommended: Connect Using OAuth2');
        expect(text).to.contain('An OAuth2 client configured by a Matomo superuser if you want to connect using OAuth2');
        expect(text).to.contain('If you do not already have an OAuth2 client for your MCP client, ask a Matomo superuser to create one for you.');
        expect(text).to.not.contain('If you do not already have an OAuth2 client for your MCP client, create one in Administration -> Platform -> OAuth2 Clients first.');
        expect(await page.$(`${connectSelector} a[href*="module=OAuth2"][href*="action=index"]`)).to.equal(null);
    });
});
