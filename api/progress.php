<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/upgrade.php';

if(empty($_SESSION['account_id']))json_out(['ok'=>false,'error'=>'برای ذخیره و ادامه‌ی پیشرفت، وارد حساب کاربری شوید.'],401);
$pdo=db();
$accountId=(int)$_SESSION['account_id'];

if($_SERVER['REQUEST_METHOD']==='GET'){
    $statement=$pdo->prepare('SELECT lecture_id,position_seconds,duration_seconds,is_completed,updated_at FROM account_lecture_progress WHERE account_id=?');
    $statement->execute([$accountId]);
    $progress=[];
    foreach($statement as $row){
        $progress[(int)$row['lecture_id']]=[
            'position_seconds'=>(int)$row['position_seconds'],
            'duration_seconds'=>(int)$row['duration_seconds'],
            'is_completed'=>(bool)$row['is_completed'],
            'updated_at'=>$row['updated_at'],
        ];
    }
    json_out(['ok'=>true,'progress'=>$progress]);
}

if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['ok'=>false,'error'=>'روش نامعتبر است.'],405);
require_csrf();
$data=json_input();
$lectureId=(int)($data['lecture_id']??0);
$position=(int)($data['position_seconds']??-1);
$duration=(int)($data['duration_seconds']??-1);
if($lectureId<1||$position<0||$duration<0||$duration>604800||$position>$duration+1)json_out(['ok'=>false,'error'=>'مقادیر پیشرفت معتبر نیستند.'],422);
$exists=$pdo->prepare('SELECT 1 FROM lectures WHERE id=?');
$exists->execute([$lectureId]);
if(!$exists->fetchColumn())json_out(['ok'=>false,'error'=>'جلسه موردنظر پیدا نشد.'],404);
$completed=$duration>0&&$position/$duration>=0.95;
$statement=$pdo->prepare('INSERT INTO account_lecture_progress(account_id,lecture_id,position_seconds,duration_seconds,is_completed) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE position_seconds=VALUES(position_seconds),duration_seconds=VALUES(duration_seconds),is_completed=VALUES(is_completed)');
$statement->execute([$accountId,$lectureId,$position,$duration,(int)$completed]);
json_out(['ok'=>true,'is_completed'=>$completed]);
