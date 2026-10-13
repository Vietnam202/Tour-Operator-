#!/usr/bin/env python3
"""Wrap verified runtime with fresh-install guide, config template and migration plan."""
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('runtime_builder', ROOT/'deploy/build-integrated-package.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
out = Path(sys.argv[1]).resolve() if len(sys.argv)>1 else ROOT/'dist'
runtime, manifest_file = builder.make(out)
builder.verify(runtime, manifest_file)
manifest = json.loads(manifest_file.read_text(encoding='utf-8'))
sha = manifest['source_commit']
# Match Migrations::orderedFiles: core, then exact deferred Marketing names.
source = (ROOT/'api/lib/Migrations.php').read_text(encoding='utf-8')
import re
block = source.split('private const MARKETING_DEFERRED=[',1)[1].split('];',1)[0]
deferred = re.findall(r"'([^']+)'",block)
sql = sorted(k for k in manifest['sha256'] if k.startswith('api/migrations/') and k.endswith('.sql'))
order = [k for k in sql if Path(k).stem not in deferred]
order += ['api/migrations/'+name+'.sql' for name in deferred]
assert len(order)==len(sql) and set(order)==set(sql)
plan = {'source_commit':sha,'execution':'NOT_EXECUTED_ON_TARGET','migrations':[
 {'order':i+1,'version':Path(k).stem,'sha256':manifest['sha256'][k]} for i,k in enumerate(order)]}
payload = {}
with zipfile.ZipFile(runtime) as z:
    payload.update({name:z.read(name) for name in z.namelist() if name.startswith('runtime/')})
payload['manifest.json'] = manifest_file.read_bytes()
payload['migration-plan.json'] = (json.dumps(plan,ensure_ascii=False,indent=2)+'\n').encode()
payload['INSTALL_VI.md'] = (ROOT/'docs/RC62_FRESH_INSTALL_VI.md').read_bytes()
payload['configuration/config.example.php'] = (ROOT/'api/config.example.php').read_bytes()
payload['BUILD_STATUS.json'] = (json.dumps({'source_commit':sha,'release_type':'ACCEPTANCE_CANDIDATE',
 'target':'https://v2quote.vietnamtraveladvisor.com.vn','deployment':'NOT_PERFORMED',
 'live_acceptance':'PENDING_USER_DEPLOYMENT_AND_ACCESS','ci':'CHECK_EXACT_SHA_IN_GITHUB'},indent=2)+'\n').encode()
payload['SHA256SUMS.txt'] = ''.join(hashlib.sha256(payload[k]).hexdigest()+'  '+k+'\n' for k in sorted(payload)).encode()
target = out/('VTA_RC62_FULL_INSTALL_'+sha+'.zip')
with zipfile.ZipFile(target,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for name in sorted(payload):
        info=zipfile.ZipInfo(name,(2026,10,13,0,0,0));info.external_attr=0o100644<<16
        z.writestr(info,payload[name],compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
with zipfile.ZipFile(target) as z:
    assert set(z.namelist())==set(payload)
    for name,data in payload.items():assert z.read(name)==data
(out/(target.name+'.sha256')).write_text(hashlib.sha256(target.read_bytes()).hexdigest()+'  '+target.name+'\n')
print('PASS complete install archive, payload hashes, migration plan and exact build SHA')
print(target)
