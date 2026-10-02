<?php
declare(strict_types=1);

final class Storage {
    public static function store(array $config, string $tmpPath, string $filename, string $mime): array {
        $driver = strtolower($config['storage']['driver'] ?? 'local');
        if ($driver === 'google_drive') {
            return GoogleDriveStorage::upload($config['storage'], $tmpPath, $filename, $mime);
        }
        return LocalStorage::upload($config['storage'], $tmpPath, $filename);
    }

    public static function stream(array $config, array $doc): never {
        if (($doc['storage_driver'] ?? '') === 'GOOGLE_DRIVE') {
            GoogleDriveStorage::stream($config['storage'], (string)$doc['storage_file_id'], (string)$doc['mime_type'], (string)$doc['original_filename']);
        }
        $base=realpath((string)($config['storage']['local_path']??''));$path=realpath((string)$doc['storage_path']);
        if(!$base||!$path||!RuntimeGuard::inside($path,$base))Http::json(['ok'=>false,'error'=>'FILE_NOT_FOUND'],404);
        RuntimeGuard::privatePath($base);LocalStorage::stream($path, (string)$doc['mime_type'], (string)$doc['original_filename']);
    }
}

final class LocalStorage {
    public static function upload(array $cfg, string $tmpPath, string $filename): array {
        $base = rtrim((string)($cfg['local_path'] ?? ''), '/');
        if (!$base) throw new RuntimeException('Local storage path is not configured.');
        if (!is_dir($base) && !mkdir($base, 0770, true) && !is_dir($base)) throw new RuntimeException('Cannot create private storage directory.');
        RuntimeGuard::privatePath($base);
        $safe = bin2hex(random_bytes(12)) . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($filename));
        $dest = $base . '/' . $safe;
        if (!move_uploaded_file($tmpPath, $dest) && !rename($tmpPath, $dest)) throw new RuntimeException('Unable to store file.');
        return ['driver'=>'LOCAL','file_id'=>null,'path'=>$dest];
    }

    public static function stream(string $path, string $mime, string $filename): never {
        if (!$path || !is_file($path)) Http::json(['ok'=>false,'error'=>'FILE_NOT_FOUND'],404);
        header('Content-Type: '.$mime);
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: inline; filename="'.str_replace('"','',basename($filename)).'"');
        header('Cache-Control: private, no-store');
        readfile($path); exit;
    }
}

final class GoogleDriveStorage {
    private static function credentials(array $cfg): array {
        $path = (string)($cfg['google_service_account_json'] ?? '');
        if (!$path || !is_file($path)) throw new RuntimeException('Google Drive service account JSON is missing.');
        RuntimeGuard::privatePath($path);
        $json = json_decode((string)file_get_contents($path), true);
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) throw new RuntimeException('Invalid Google service account JSON.');
        return $json;
    }

    private static function b64url(string $data): string { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }

    private static function http(string $url, string $method='GET', array $headers=[], ?string $body=null, int $timeout=90): array {
        if(function_exists('curl_init')) {
            $ch=curl_init($url);
            $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers];
            if($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;
            curl_setopt_array($ch,$opts);
            $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
            if($raw===false)throw new RuntimeException('HTTP request failed: '.$err);
            return [$code,(string)$raw];
        }
        $headerText=implode("\r\n",$headers);
        $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>$headerText,'content'=>$body??'','timeout'=>$timeout,'ignore_errors'=>true]]);
        $raw=@file_get_contents($url,false,$ctx);
        if($raw===false)throw new RuntimeException('HTTP request failed using PHP stream transport.');
        $code=0;
        foreach($http_response_header??[] as $line){if(preg_match('#^HTTP/\S+\s+(\d{3})#',$line,$m)){$code=(int)$m[1];break;}}
        return [$code,(string)$raw];
    }

    private static function token(array $cfg): string {
        if (!empty($cfg['google_oauth_refresh_token'])) {
            $payload=http_build_query([
                'client_id'=>$cfg['google_oauth_client_id']??'',
                'client_secret'=>$cfg['google_oauth_client_secret']??'',
                'refresh_token'=>$cfg['google_oauth_refresh_token'],
                'grant_type'=>'refresh_token'
            ]);
            [$code,$raw]=self::http('https://oauth2.googleapis.com/token','POST',['Content-Type: application/x-www-form-urlencoded'],$payload,30);
            if($code>=300)throw new RuntimeException('Google OAuth refresh failed: '.$raw);
            $out=json_decode($raw,true);if(empty($out['access_token']))throw new RuntimeException('Google OAuth access token missing.');
            return $out['access_token'];
        }
        $c=self::credentials($cfg);$now=time();
        $header=self::b64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));
        $claim=self::b64url(json_encode(['iss'=>$c['client_email'],'scope'=>'https://www.googleapis.com/auth/drive','aud'=>'https://oauth2.googleapis.com/token','exp'=>$now+3500,'iat'=>$now]));
        $unsigned=$header.'.'.$claim;$sig='';
        if(!openssl_sign($unsigned,$sig,$c['private_key'],OPENSSL_ALGO_SHA256))throw new RuntimeException('Unable to sign Google JWT.');
        $jwt=$unsigned.'.'.self::b64url($sig);
        // RFC 7523 JWT bearer grant; credentials remain server-side.
        $payload=http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]);
        [$code,$raw]=self::http('https://oauth2.googleapis.com/token','POST',['Content-Type: application/x-www-form-urlencoded'],$payload,30);
        if($code>=300)throw new RuntimeException('Google service-account token request failed: '.$raw);
        $out=json_decode($raw,true);if(empty($out['access_token']))throw new RuntimeException('Google access token missing.');
        return $out['access_token'];
    }

    public static function upload(array $cfg, string $tmpPath, string $filename, string $mime): array {
        $folder=(string)($cfg['google_drive_folder_id'] ?? '');
        if(!$folder || $folder==='CHANGE_ME')throw new RuntimeException('Google Drive folder ID is not configured.');
        $token=self::token($cfg);$boundary='vta_'.bin2hex(random_bytes(12));
        $meta=json_encode(['name'=>$filename,'parents'=>[$folder]],JSON_UNESCAPED_UNICODE);
        $file=file_get_contents($tmpPath);if($file===false)throw new RuntimeException('Cannot read uploaded file.');
        $body="--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n".
              "--$boundary\r\nContent-Type: $mime\r\n\r\n$file\r\n--$boundary--\r\n";
        [$code,$raw]=self::http('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,size,mimeType','POST',['Authorization: Bearer '.$token,'Content-Type: multipart/related; boundary='.$boundary],$body,90);
        if($code>=300)throw new RuntimeException('Google Drive upload failed: '.$raw);
        $out=json_decode($raw,true);if(empty($out['id']))throw new RuntimeException('Google Drive upload returned no file ID.');
        return ['driver'=>'GOOGLE_DRIVE','file_id'=>$out['id'],'path'=>null];
    }

    public static function stream(array $cfg, string $fileId, string $mime, string $filename): never {
        if(!$fileId)Http::json(['ok'=>false,'error'=>'FILE_NOT_FOUND'],404);
        $token=self::token($cfg);
        [$code,$raw]=self::http('https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId).'?alt=media','GET',['Authorization: Bearer '.$token],null,90);
        if($code>=300)Http::json(['ok'=>false,'error'=>'DRIVE_DOWNLOAD_FAILED'],502);
        header('Content-Type: '.$mime);header('Content-Disposition: inline; filename="'.str_replace('"','',basename($filename)).'"');header('Cache-Control: private, no-store');echo $raw;exit;
    }
}
