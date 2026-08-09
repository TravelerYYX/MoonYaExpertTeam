'use strict';

const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

(async () => {
    const baseUrl = process.argv[2] || 'http://127.0.0.1:8765/index.php?office_popout=1';
    const output = process.argv[3] || path.join(__dirname, '..', '..', '.test-output', 'office-1440.png');
    fs.mkdirSync(path.dirname(output), { recursive: true });
    const launchOptions = { headless: true };
    if (process.env.MOONYA_BROWSER_EXECUTABLE) {
        launchOptions.executablePath = process.env.MOONYA_BROWSER_EXECUTABLE;
    }
    const browser = await chromium.launch(launchOptions);
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
        await page.goto(baseUrl, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#officeStage .workstation');
        await page.waitForFunction(() => Array.from(document.querySelectorAll('.ws-character')).every(img => img.complete));
        await page.waitForTimeout(800);

        const expectedKeys = ['moonya', 'image', 'search', 'file', 'voice', 'app', 'browser', 'code', 'computer'];
        for (const key of expectedKeys) {
            await page.click(`[data-agent="${key}"] .ws-character-trigger`);
            await page.waitForFunction(expectedKey => {
                const card = document.getElementById('officeAgentCard');
                const avatar = document.getElementById('officeAgentCardAvatar');
                return !!card && card.dataset.agent === expectedKey && card.classList.contains('show') &&
                    !!avatar && avatar.complete && avatar.naturalWidth > 0;
            }, key);
            const profile = await page.evaluate(() => {
                const stageRect = document.getElementById('officeStage').getBoundingClientRect();
                const cardRect = document.getElementById('officeAgentCard').getBoundingClientRect();
                return {
                    name: document.getElementById('officeAgentCardName').textContent.trim(),
                    skills: document.querySelectorAll('#officeAgentCardSkills .office-agent-skill').length,
                    insideStage: cardRect.left >= stageRect.left - 1 && cardRect.right <= stageRect.right + 1 &&
                        cardRect.top >= stageRect.top - 1 && cardRect.bottom <= stageRect.bottom + 1
                };
            });
            assert(profile.name.length > 0, `profile name is missing for ${key}`);
            assert(profile.skills >= 1, `profile skills are missing for ${key}`);
            assert(profile.insideStage, `profile card escaped the office stage for ${key}`);
            await page.click('#officeAgentCard .office-agent-card-close');
            await page.waitForFunction(() => document.getElementById('officeAgentCard').hidden);
        }

        await page.click('[data-agent="moonya"] .ws-character-trigger');
        await page.waitForSelector('#officeAgentCard.show');
        await page.waitForFunction(() => {
            const avatar = document.getElementById('officeAgentCardAvatar');
            return !!avatar && avatar.complete && avatar.naturalWidth > 0;
        });
        await page.waitForTimeout(220);

        const result = await page.evaluate(() => {
            const expected = ['moonya', 'image', 'search', 'file', 'voice', 'app', 'browser', 'code', 'computer'];
            const stations = Array.from(document.querySelectorAll('#officeStage .workstation'));
            const stage = document.getElementById('officeStage').getBoundingClientRect();
            const input = document.querySelector('.input-container-wrapper');
            const profileTrigger = document.querySelector('[data-agent="moonya"] .ws-character-trigger');
            const profileCard = document.getElementById('officeAgentCard');
            const profileAvatar = document.getElementById('officeAgentCardAvatar');
            return {
                bodyOffice: document.body.classList.contains('office-active'),
                keys: stations.map(node => node.dataset.agent),
                duplicateInputCount: document.querySelectorAll('#officeMessageInput, #officeSendBtn').length,
                inputVisible: !!input && getComputedStyle(input).display !== 'none' && input.getBoundingClientRect().height > 0,
                clipped: stations.filter(node => {
                    const rect = node.getBoundingClientRect();
                    return rect.left < stage.left - 2 || rect.right > stage.right + 2 ||
                        rect.top < stage.top - 2 || rect.bottom > stage.bottom + 2;
                }).map(node => node.dataset.agent),
                moonyaPosition: stations[0] ? [stations[0].dataset.row, stations[0].dataset.col] : null,
                profileCursor: profileTrigger ? getComputedStyle(profileTrigger).cursor : '',
                profileVisible: !!profileCard && !profileCard.hidden && profileCard.classList.contains('show'),
                profileName: document.getElementById('officeAgentCardName')?.textContent || '',
                profileAvatarReady: !!profileAvatar && profileAvatar.complete && profileAvatar.naturalWidth > 0,
                profileSkills: document.querySelectorAll('#officeAgentCardSkills .office-agent-skill').length,
                expected
            };
        });

        await page.screenshot({ path: output, fullPage: true });
        assert(result.bodyOffice, 'office popout must open directly in office mode');
        assert(JSON.stringify(result.keys) === JSON.stringify(result.expected), 'office 3x3 role order drifted');
        assert(result.moonyaPosition && result.moonyaPosition[0] === '0' && result.moonyaPosition[1] === '0', 'MoonYa is not first row/first column');
        assert(result.duplicateInputCount === 0, 'legacy office composer is still present');
        assert(result.inputVisible, 'shared AI composer is not visible in office mode');
        assert(result.clipped.length === 0, 'office workstations are clipped: ' + result.clipped.join(', '));
        assert(result.profileCursor === 'pointer', 'office character cursor must be a pointing hand');
        assert(result.profileVisible, 'clicking an office character did not open its profile card');
        assert(result.profileName === 'MoonYa', 'profile card did not render the selected character');
        assert(result.profileAvatarReady, 'profile card avatar failed to load');
        assert(result.profileSkills >= 1, 'profile card skills are missing');
        console.log('office visual smoke: PASS ' + output);
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error.stack || error.message || String(error));
    process.exit(1);
});
