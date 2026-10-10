<?php
require_once __DIR__.'/../config/config.php';require_once __DIR__.'/../config/upgrade.php';
require_admin_safe();
require_csrf();
$pdo=db();
$method=$_SERVER['REQUEST_METHOD'];
$data=$_POST?:json_input();

if($method==='POST'||$method==='PUT'){
	$id=(int)($data['id']??0);
	$title=clean_text($data['title']??'');
	$body=clean_text($data['body']??'');
	$start=clean_text($data['start_gregorian']??'')?:null;
	$end=clean_text($data['end_gregorian']??'')?:null;
	if($title===''||$body==='')json_out(['ok'=>false,'error'=>'عنوان و متن اعلان الزامی است.'],422);
	if(!$start||!$end)json_out(['ok'=>false,'error'=>'تاریخ شروع و پایان اعلان را انتخاب کنید.'],422);
	if(($start&&!valid_gregorian_date($start))||($end&&!valid_gregorian_date($end)))json_out(['ok'=>false,'error'=>'یکی از تاریخ‌های اعلان معتبر نیست.'],422);
	if($start&&$end&&$start>$end)json_out(['ok'=>false,'error'=>'تاریخ پایان باید بعد از تاریخ شروع باشد.'],422);

	try{
		$poster=clean_text($data['poster_url']??'');
		if(isset($_FILES['poster']))$poster=safe_upload($_FILES['poster'],'posters',['jpg','jpeg','png','webp']);
		if($method==='PUT'&&$poster===''){
			$query=$pdo->prepare('SELECT poster_url FROM announcements WHERE id=?');
			$query->execute([$id]);
			$poster=(string)($query->fetchColumn()?:'');
		}
		$values=[$title,$body,clean_text($data['event_date']??''),clean_text($data['hijri_date']??'')?:null,clean_text($data['event_time']??''),clean_text($data['location']??''),$poster,(int)($data['category_id']??0)?:null,$start,$end,clean_text($data['start_jalali']??'')?:null,clean_text($data['end_jalali']??'')?:null];
		if($method==='POST'){
			$statement=$pdo->prepare('INSERT INTO announcements(title,body,event_date,hijri_date,event_time,location,poster_url,category_id,start_date_gregorian,end_date_gregorian,start_jalali,end_jalali) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
			$statement->execute($values);
			$id=(int)$pdo->lastInsertId();
		}else{
			$statement=$pdo->prepare('UPDATE announcements SET title=?,body=?,event_date=?,hijri_date=?,event_time=?,location=?,poster_url=?,category_id=?,start_date_gregorian=?,end_date_gregorian=?,start_jalali=?,end_jalali=? WHERE id=?');
			$statement->execute([...$values,$id]);
		}
		sync_content_tags($pdo,'announcement',$id,$data['tags']??[]);
		json_out(['ok'=>true,'id'=>$id]);
	}catch(Throwable $error){
		json_out(['ok'=>false,'error'=>$error->getMessage()],422);
	}
}

if($method==='DELETE'){
	$pdo->prepare('DELETE FROM announcements WHERE id=?')->execute([(int)($data['id']??0)]);
	json_out(['ok'=>true]);
}
json_out(['ok'=>false,'error'=>'روش نامعتبر است.'],405);
