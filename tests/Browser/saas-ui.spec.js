import {test,expect} from '@playwright/test';

test('white default, persistent charcoal theme, responsive layout and reduced motion',async({page})=>{
    await page.goto('/');
    await expect(page.locator('.olt-card').first()).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-theme','light');
    await page.getByRole('button',{name:'Tema Dark',exact:true}).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme','dark');
    expect(await page.locator('body').evaluate(e=>getComputedStyle(e).backgroundColor)).toBe('rgb(20, 21, 22)');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme','dark');
    await page.setViewportSize({width:375,height:812});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await page.emulateMedia({reducedMotion:'reduce'});
    expect(await page.locator('.olt-card').first().evaluate(e=>getComputedStyle(e).animationName)).toBe('none');
});

test('ONU skeleton follows pending detail request and close remains usable',async({page})=>{
    let resolveRead;
    const pending=new Promise(resolve=>{resolveRead=resolve;});
    await page.route('**/onus/*/detail',async r=>{await pending;await r.fulfill({json:{sections:{},source:'real',position_history:[]}});});
    await page.route('**/onus/*/detail/refresh',r=>r.fulfill({json:{data:{reported_type:'F609'},observed_at:new Date().toISOString()}}));
    await page.goto('/');
    await page.locator('.olt-card').first().getByRole('button',{name:'ONU',exact:true}).click();
    await page.locator('.onu-name-link').first().click();
    const dialog=page.locator('.onu-inspector');
    await expect(dialog.locator('.skeleton-surface')).toBeVisible();
    resolveRead();
    await expect(dialog.locator('.skeleton-surface')).toBeHidden();
    await dialog.getByRole('button',{name:'Tutup detail ONU',exact:true}).click();
    await expect(dialog).toBeHidden();
});
