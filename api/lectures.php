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
	$date=clean_text($data['date']??'');
	$gregorian=clean_text($data['date_gregorian']??'');
	$hijri=clean_text($data['hijri_date']??'');
	$category=(int)($data['category_id']??0);
	$description=clean_text($data['description']??'');
	$sessionText=(string)($data['session_text']??'');
	if(strlen($sessionText)>1000000)json_out(['ok'=>false,'error'=>'متن جلسه از یک مگابایت بیشتر است.'],422);
	$collection=(int)($data['collection_id']??0);
	$number=(int)($data['session_number']??0);
	$count=(int)($data['session_count']??0);
	if($title===''||$date==='')json_out(['ok'=>false,'error'=>'عنوان و تاریخ جلسه الزامی است.'],422);
	if(!valid_gregorian_date($gregorian))json_out(['ok'=>false,'error'=>'تاریخ میلادی جلسه معتبر نیست.'],422);

	if($collection){
		$seriesQuery=$pdo->prepare('SELECT title,category_id,session_count,start_date_gregorian,end_date_gregorian FROM collections WHERE id=?');
		$seriesQuery->execute([$collection]);
		$series=$seriesQuery->fetch();
		if(!$series)json_out(['ok'=>false,'error'=>'مجموعه انتخاب‌شده پیدا نشد.'],422);
		$title=$series['title'];
		$category=(int)($series['category_id']?:$category);
		$count=(int)($series['session_count']?:$count);
		if($number<1)json_out(['ok'=>false,'error'=>'شماره جلسه را وارد کنید.'],422);
		if($count>0&&$number>$count)json_out(['ok'=>false,'error'=>'شماره جلسه از تعداد کل جلسات بیشتر است.'],422);
		if($gregorian&&$series['start_date_gregorian']&&$gregorian<$series['start_date_gregorian'])json_out(['ok'=>false,'error'=>'تاریخ جلسه پیش از شروع بازه مجموعه است.'],422);
		if($gregorian&&$series['end_date_gregorian']&&$gregorian>$series['end_date_gregorian'])json_out(['ok'=>false,'error'=>'تاریخ جلسه پس از پایان بازه مجموعه است.'],422);
		$duplicate=$pdo->prepare('SELECT COUNT(*) FROM lectures WHERE collection_id=? AND session_number=? AND id<>?');
		$duplicate->execute([$collection,$number,$id]);
		if((int)$duplicate->fetchColumn())json_out(['ok'=>false,'error'=>'این شماره جلسه قبلاً در مجموعه ثبت شده است.'],422);
	}else{
		$number=0;
		$count=0;
	}

	try{
		$audio=clean_text($data['audio_url']??'');
		if(isset($_FILES['audio']))$audio=safe_upload($_FILES['audio'],'audio',['mp3','m4a','wav','ogg','aac']);
		if($method==='POST'){
			if($audio==='')json_out(['ok'=>false,'error'=>'فایل صوتی را انتخاب کنید.'],422);
			$statement=$pdo->prepare('INSERT INTO lectures(title,audio_url,category_id,session_date,session_date_gregorian,hijri_date,description,session_text,collection_id,session_number,session_count) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
			$statement->execute([$title,$audio,$category?:null,$date,$gregorian?:null,$hijri?:null,$description,$sessionText,$collection?:null,$number?:null,$count?:null]);
			$id=(int)$pdo->lastInsertId();
		}else{
			$sql='UPDATE lectures SET title=?,category_id=?,session_date=?,session_date_gregorian=?,hijri_date=?,description=?,session_text=?,collection_id=?,session_number=?,session_count=?';
			$arguments=[$title,$category?:null,$date,$gregorian?:null,$hijri?:null,$description,$sessionText,$collection?:null,$number?:null,$count?:null];
			if($audio!==''){$sql.=',audio_url=?';$arguments[]=$audio;}
			$sql.=' WHERE id=?';
			$arguments[]=$id;
			$pdo->prepare($sql)->execute($arguments);
		}
		sync_content_tags($pdo,'lecture',$id,$data['tags']??[]);
		if($collection)$pdo->prepare('INSERT IGNORE INTO collection_lectures(collection_id,lecture_id) VALUES(?,?)')->execute([$collection,$id]);
		json_out(['ok'=>true,'id'=>$id,'audio_url'=>$audio]);
	}catch(Throwable $error){
		json_out(['ok'=>false,'error'=>$error->getMessage()],422);
	}
}
if($method==='DELETE'){
	$pdo->prepare('DELETE FROM lectures WHERE id=?')->execute([(int)($data['id']??0)]);
	json_out(['ok'=>true]);
}
json_out(['ok'=>false,'error'=>'روش نامعتبر است.'],405);
