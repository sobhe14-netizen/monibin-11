<?php
require_once __DIR__.'/../config/config.php';require_once __DIR__.'/../config/upgrade.php';
require_admin_safe();
require_csrf();
$pdo=db();
$method=$_SERVER['REQUEST_METHOD'];
$data=json_input();

if($method==='POST'||$method==='PUT'){
	$id=(int)($data['id']??0);
	$title=clean_text($data['title']??'');
	if($title==='')json_out(['ok'=>false,'error'=>'عنوان مجموعه الزامی است.'],422);
	$category=(int)($data['category_id']??0)?:null;
	$count=(int)($data['session_count']??0)?:null;
	$description=clean_text($data['description']??'');
	$poster=clean_text($data['poster_url']??'');
	$startGregorian=clean_text($data['start_gregorian']??'')?:null;
	$endGregorian=clean_text($data['end_gregorian']??'')?:null;
	$startJalali=clean_text($data['start_jalali']??'')?:null;
	$endJalali=clean_text($data['end_jalali']??'')?:null;
	if(!$startGregorian||!$endGregorian)json_out(['ok'=>false,'error'=>'تاریخ شروع و پایان مجموعه را انتخاب کنید.'],422);
	if(($startGregorian&&!valid_gregorian_date($startGregorian))||($endGregorian&&!valid_gregorian_date($endGregorian)))json_out(['ok'=>false,'error'=>'یکی از تاریخ‌های مجموعه معتبر نیست.'],422);
	if(($startGregorian===null)!==($endGregorian===null))json_out(['ok'=>false,'error'=>'بازه‌ی مجموعه باید هر دو تاریخ را داشته باشد.'],422);
	if($startGregorian&&$endGregorian&&$startGregorian>$endGregorian)json_out(['ok'=>false,'error'=>'تاریخ پایان مجموعه باید بعد از شروع آن باشد.'],422);
	if($method==='PUT'){
		$maximumNumber=$pdo->prepare('SELECT MAX(session_number) FROM lectures WHERE collection_id=?');
		$maximumNumber->execute([$id]);
		$maxNumber=(int)$maximumNumber->fetchColumn();
		if($count&&$maxNumber>$count)json_out(['ok'=>false,'error'=>'تعداد کل نمی‌تواند از شماره‌ی ثبت‌شده‌ی آخر کمتر باشد.'],422);
		if($startGregorian&&$endGregorian){
			$outsideRange=$pdo->prepare('SELECT COUNT(*) FROM lectures WHERE collection_id=? AND session_date_gregorian IS NOT NULL AND (session_date_gregorian<? OR session_date_gregorian>?)');
			$outsideRange->execute([$id,$startGregorian,$endGregorian]);
			if((int)$outsideRange->fetchColumn())json_out(['ok'=>false,'error'=>'بازه‌ی جدید بعضی از جلسه‌های ثبت‌شده را در بر نمی‌گیرد.'],422);
		}
	}

	if($method==='POST'){
		$statement=$pdo->prepare('INSERT INTO collections(title,description,poster_url,category_id,session_count,start_date_gregorian,end_date_gregorian,start_jalali,end_jalali) VALUES(?,?,?,?,?,?,?,?,?)');
		$statement->execute([$title,$description,$poster,$category,$count,$startGregorian,$endGregorian,$startJalali,$endJalali]);
		$id=(int)$pdo->lastInsertId();
	}else{
		$statement=$pdo->prepare('UPDATE collections SET title=?,description=?,poster_url=COALESCE(NULLIF(?,\'\'),poster_url),category_id=?,session_count=?,start_date_gregorian=?,end_date_gregorian=?,start_jalali=?,end_jalali=? WHERE id=?');
		$statement->execute([$title,$description,$poster,$category,$count,$startGregorian,$endGregorian,$startJalali,$endJalali,$id]);
		$pdo->prepare('UPDATE lectures SET title=?,category_id=?,session_count=? WHERE collection_id=?')->execute([$title,$category,$count,$id]);
	}
	$pdo->prepare('DELETE FROM collection_lectures WHERE collection_id=?')->execute([$id]);
	foreach((array)($data['lecture_ids']??[]) as $lectureId)$pdo->prepare('INSERT IGNORE INTO collection_lectures(collection_id,lecture_id) VALUES(?,?)')->execute([$id,(int)$lectureId]);
	sync_content_tags($pdo,'collection',$id,$data['tags']??[]);
	json_out(['ok'=>true,'id'=>$id]);
}
if($method==='DELETE'){
	$pdo->prepare('DELETE FROM collections WHERE id=?')->execute([(int)($data['id']??0)]);
	json_out(['ok'=>true]);
}
json_out(['ok'=>false,'error'=>'روش نامعتبر است.'],405);
