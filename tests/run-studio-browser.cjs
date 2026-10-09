'use strict';
const {spawn}=require('node:child_process'),path=require('node:path');
const root=path.join(__dirname,'..');
if(!process.env.FREEFORM_FIXTURE_DIR)throw Error('Set a private FREEFORM_FIXTURE_DIR outside the application');
const server=spawn(process.env.PHP_CLI||'php',['-S','127.0.0.1:18876','tests/http/studio-router.php'],{cwd:root,env:process.env,stdio:['ignore','ignore','pipe']});
const ready=new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('Local PHP server did not start')),15000);server.once('error',reject);server.stderr.on('data',chunk=>{if(String(chunk).includes('started')){clearTimeout(timer);resolve();}if(String(chunk).includes('Fatal error'))process.stderr.write(chunk);});server.once('exit',code=>{clearTimeout(timer);if(code)reject(Error('PHP fixture exited: '+code));});});
const run=file=>new Promise((resolve,reject)=>{const child=spawn(process.execPath,[file],{cwd:__dirname,env:process.env,stdio:'inherit'});child.once('error',reject);child.once('exit',code=>code===0?resolve():reject(Error(file+' exited '+code)));});
(async()=>{try{await ready;await run('document-freeform-browser.cjs');await run('program-studio-browser.cjs');}finally{server.kill();}})().catch(error=>{console.error(error);process.exitCode=1;});
