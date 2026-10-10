<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/upgrade.php';
require_admin_safe();
require_csrf();

$pdo=db();
$method=$_SERVER['REQUEST_METHOD'];
$data=json_input();

if($method==='POST'){
    $lectureId=(int)($data['lecture_id']??0);
    $body=clean_text($data['body']??'');
    $lectureExists=$pdo->prepare('SELECT 1 FROM lectures WHERE id=?');
    $lectureExists->execute([$lectureId]);
    if(!$lectureId||!$lectureExists->fetchColumn()||$body==='')json_out(['ok'=>false,'error'=>'جلسه و متن یادداشت معتبر الزامی است.'],422);
    $order=$pdo->prepare('SELECT COALESCE(MAX(sort_order),-1)+1 FROM fishes WHERE lecture_id=?');
    $order->execute([$lectureId]);
    $insert=$pdo->prepare('INSERT INTO fishes(lecture_id,title,body,timestamp_seconds,sort_order) VALUES(?,?,?,?,?)');
    $insert->execute([$lectureId,clean_text($data['title']??''),$body,parse_seconds($data['timestamp']??0),(int)$order->fetchColumn()]);
    $id=(int)$pdo->lastInsertId();
    sync_content_tags($pdo,'fish',$id,$data['tags']??[]);
    sync_internal_links($pdo,$id,$body);
    json_out(['ok'=>true,'id'=>$id]);
}

if($method==='PUT'){
    $id=(int)($data['id']??0);
    $body=clean_text($data['body']??'');
    if($id<1||$body==='')json_out(['ok'=>false,'error'=>'شناسه و متن یادداشت الزامی است.'],422);
    $update=$pdo->prepare('UPDATE fishes SET title=?,body=?,timestamp_seconds=? WHERE id=?');
    $update->execute([clean_text($data['title']??''),$body,parse_seconds($data['timestamp']??0),$id]);
    if(!$update->rowCount()){
        $exists=$pdo->prepare('SELECT 1 FROM fishes WHERE id=?');
        $exists->execute([$id]);
        if(!$exists->fetchColumn())json_out(['ok'=>false,'error'=>'یادداشت موردنظر پیدا نشد.'],404);
    }
    sync_content_tags($pdo,'fish',$id,$data['tags']??[]);
    sync_internal_links($pdo,$id,$body);
    json_out(['ok'=>true]);
}

if($method==='DELETE'){
    $id=(int)($data['id']??0);
    $pdo->prepare("DELETE FROM internal_links WHERE target_type='fish' AND target_id=?")->execute([$id]);
    $pdo->prepare('DELETE FROM fishes WHERE id=?')->execute([$id]);
    json_out(['ok'=>true]);
}

json_out(['ok'=>false,'error'=>'روش نامعتبر است.'],405);
