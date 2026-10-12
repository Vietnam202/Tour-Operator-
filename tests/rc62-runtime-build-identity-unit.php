<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/lib/RuntimeBuildIdentity.php';
$tmp=tempnam(sys_get_temp_dir(),'vta-build-id-');
if($tmp===false)throw new RuntimeException('CI temp unavailable');
$manifest=[
  'schema'=>'vta.rc62.runtime.identity.v1',
  'source_commit'=>str_repeat('a',40),
  'source_inventory_sha256'=>str_repeat('b',64),
  'source_file_count'=>251,
  'release_type'=>'STAGING_CANDIDATE_NOT_PRODUCTION'
];
function yes(bool $ok,string $label):void{
    if(!$ok)throw new RuntimeException('FAIL_CI '.$label);
    echo 'PASS_CI '.$label.PHP_EOL;
}
try {
    file_put_contents($tmp,json_encode($manifest,JSON_THROW_ON_ERROR));
    $v=RuntimeBuildIdentity::read($tmp);
    yes($v!==null && $v['source_commit']===$manifest['source_commit']
       && $v['source_file_count']===251,'only structurally valid SHA-pinned identity accepted');
    foreach([
        ['source_commit','fake'],
        ['source_inventory_sha256','not-sha256'],
        ['source_file_count',0],
        ['schema','not-vta'],
        ['release_type','GO_PRODUCTION']
    ] as [$field,$wrong]){
        $bad=$manifest;$bad[$field]=$wrong;
        file_put_contents($tmp,json_encode($bad,JSON_THROW_ON_ERROR));
        yes(RuntimeBuildIdentity::read($tmp)===null,
          'invalid identity '.$field.' never becomes an accepted build');
    }
    file_put_contents($tmp,'{INVALID');
    yes(RuntimeBuildIdentity::read($tmp)===null,'malformed JSON rejected');
    file_put_contents($tmp,str_repeat('a',5000));
    yes(RuntimeBuildIdentity::read($tmp)===null,'oversized identity rejected');
    unlink($tmp);
    yes(RuntimeBuildIdentity::read($tmp)===null,'missing build file is not an attested release');
} finally {
    if(is_file($tmp)||is_link($tmp))unlink($tmp);
}
