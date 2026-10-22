/** Offline preflight for both engines under the release container restrictions. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
const require=createRequire(new URL('../staging-rehearsal/package.json',import.meta.url));
const {chromium,webkit}=require('playwright');
assert.equal(require('playwright/package.json').version,'1.62.1');
assert.equal(process.env.PLAYWRIGHT_BROWSERS_PATH,'/ms-playwright');
for(const name of ['SSHPASS','SSH_AUTH_SOCK','GITHUB_TOKEN','GH_TOKEN','ACTIONS_RUNTIME_TOKEN'])assert.equal(process.env[name],undefined);
assert.deepEqual(fs.readdirSync(new URL('../../.git/',import.meta.url)),[]);
for(const engine of [chromium,webkit]){
 assert(fs.existsSync(engine.executablePath()));
 const browser=await engine.launch({headless:true,...(engine===chromium?{args:['--no-sandbox']}:{})});
 try{
  const context=await browser.newContext({serviceWorkers:'block'});
  await context.route('**/*',route=>route.abort('blockedbyclient'));
  const page=await context.newPage();
  await page.setContent('<!doctype html><p id="proof">Petish browser preflight</p>');
  assert.equal(await page.locator('#proof').textContent(),'Petish browser preflight');
  assert((await page.locator('#proof').boundingBox()).width>0);
  await context.close();
 }finally{await browser.close();}
}
console.log('Chromium and WebKit container preflight passed.');
