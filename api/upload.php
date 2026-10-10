<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/upgrade.php';
require_admin_safe();
require_csrf();
if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['ok'=>false,'error'=>'روش نامعتبر است.'],405);
$type=$_POST['type']??'';
try{
    $file=$_FILES['file']??[];
    if($type==='audio')$url=safe_upload($file,'audio',['mp3','m4a','wav','ogg','aac']);
    elseif($type==='poster')$url=safe_upload($file,'posters',['jpg','jpeg','png','webp']);
    elseif($type==='gallery')$url=safe_upload($file,'gallery',['jpg','jpeg','png','webp','gif']);
    elseif($type==='profile')$url=safe_upload($file,'profile',['jpg','jpeg','png','webp'],12*1024*1024);
    elseif($type==='qr')$url=safe_upload($file,'qr',['jpg','jpeg','png','webp'],4*1024*1024);
    else throw new RuntimeException('نوع آپلود نامعتبر است.');
    json_out(['ok'=>true,'url'=>$url]);
}catch(RuntimeException $error){
    json_out(['ok'=>false,'error'=>$error->getMessage()],422);
}
