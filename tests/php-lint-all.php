<?php
declare(strict_types=1);
$count=0;$root=realpath(__DIR__.'/..');
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $f){
 if($f->getExtension()!=='php')continue;
 token_get_all(file_get_contents($f->getPathname()),TOKEN_PARSE);$count++;
}
echo "PASS parsed $count PHP files with PHP ".PHP_VERSION."\n";
