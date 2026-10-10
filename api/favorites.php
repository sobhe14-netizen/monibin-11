<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/upgrade.php';

if(empty($_SESSION['account_id']))json_out(['ok'=>false,'error'=>'برای استفاده از علاقه‌مندی‌ها وارد حساب کاربری شوید.'],401);
$pdo=db();
$accountId=(int)$_SESSION['account_id'];
$method=$_SERVER['REQUEST_METHOD'];

if($method==='GET'){
    $statement=$pdo->prepare('SELECT content_type,content_id FROM account_favorites WHERE account_id=? ORDER BY created_at DESC');
    $statement->execute([$accountId]);
    json_out(['ok'=>true,'items'=>$statement->fetchAll()]);
}

require_csrf();
$data=json_input();
$type=(string)($data['content_type']??'');
$contentId=(int)($data['content_id']??0);
if(!in_array($type,['fish','lecture','article'],true)||$contentId<1)json_out(['ok'=>false,'error'=>'مورد علاقه‌مندی معتبر نیست.'],422);

$table=['fish'=>'fishes','lecture'=>'lectures','article'=>'articles'][$type];
if($method==='POST'){
    $check=$pdo->prepare("SELECT 1 FROM `$table` WHERE id=?");
    $check->execute([$contentId]);
    if(!$check->fetchColumn())json_out(['ok'=>false,'error'=>'محتوای موردنظر پیدا نشد.'],404);
    $pdo->prepare('INSERT IGNORE INTO account_favorites(account_id,content_type,content_id) VALUES(?,?,?)')->execute([$accountId,$type,$contentId]);
    json_out(['ok'=>true]);
}
if($method==='DELETE'){
    $pdo->prepare('DELETE FROM account_favorites WHERE account_id=? AND content_type=? AND content_id=?')->execute([$accountId,$type,$contentId]);
    json_out(['ok'=>true]);
}
json_out(['ok'=>false,'error'=>'روش نامعتبر است.'],405);
