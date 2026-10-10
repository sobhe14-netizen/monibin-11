<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/upgrade.php';

if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['ok'=>false,'error'=>'روش نامعتبر است.'],405);
require_admin_safe('owner');
require_csrf();
$data=json_input();
$limits=[
    'site_title'=>200,'tagline'=>500,'canonical_domain'=>255,'footer_text'=>2000,
    'eitaa_url'=>500,'telegram_url'=>500,'teacher_name'=>200,'teacher_bio'=>20000,
    'teacher_image_1'=>255,'teacher_image_2'=>255,'teacher_image_3'=>255,'footer_qr_url'=>255,
];
$allowed=[];
foreach($limits as $key=>$max){
    if(!array_key_exists($key,$data))continue;
    $value=trim((string)$data[$key]);
    if(strlen($value)>$max)json_out(['ok'=>false,'error'=>"مقدار «$key» بیش از حد مجاز است."],422);
    $allowed[$key]=$value;
}
foreach(['eitaa_url','telegram_url','canonical_domain'] as $key){
    if(isset($allowed[$key])&&$allowed[$key]!==''&&(!filter_var($allowed[$key],FILTER_VALIDATE_URL)||parse_url($allowed[$key],PHP_URL_SCHEME)!=='https'))json_out(['ok'=>false,'error'=>'نشانی‌ها باید URL معتبر HTTPS باشند.'],422);
}
foreach(['teacher_image_1','teacher_image_2','teacher_image_3'] as $key){
    if(isset($allowed[$key])&&$allowed[$key]!==''&&!preg_match('#^uploads/profile/[a-f0-9]{28}\.(jpg|jpeg|png|webp)$#',$allowed[$key]))json_out(['ok'=>false,'error'=>'مسیر تصویر استاد معتبر نیست.'],422);
}
if(isset($allowed['footer_qr_url'])&&$allowed['footer_qr_url']!==''&&!preg_match('#^(assets/monibin-eitaa-qr\.svg|uploads/qr/[a-f0-9]{28}\.(jpg|jpeg|png|webp))$#',$allowed['footer_qr_url']))json_out(['ok'=>false,'error'=>'مسیر QR معتبر نیست.'],422);

$pdo=db();
$statement=$pdo->prepare('INSERT INTO site_settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
$pdo->beginTransaction();
try{
    foreach($allowed as $key=>$value)$statement->execute([$key,$value]);
    audit_admin_event('site_settings_updated','site_settings',null,['keys'=>array_keys($allowed)]);
    $pdo->commit();
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('Site settings update failed: '.$error->getMessage());
    json_out(['ok'=>false,'error'=>'ذخیره تنظیمات انجام نشد.'],500);
}
json_out(['ok'=>true,'settings'=>$allowed]);
