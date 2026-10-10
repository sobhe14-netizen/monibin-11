<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/upgrade.php';
require_admin_safe();
require_csrf();

if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['ok'=>false,'error'=>'روش نامعتبر است.'],405);
$collectionId=(int)($_POST['collection_id']??0);
$collectionQuery=db()->prepare('SELECT title,category_id,session_count FROM collections WHERE id=?');
$collectionQuery->execute([$collectionId]);
$collection=$collectionQuery->fetch();
if(!$collection)json_out(['ok'=>false,'error'=>'سلسله جلسات انتخاب‌شده پیدا نشد.'],422);

$metadata=json_decode((string)($_POST['metadata']??''),true);
$files=$_FILES['audio_files']??null;
if(!is_array($metadata)||!array_is_list($metadata)||!is_array($files)||!isset($files['name'])||!is_array($files['name']))json_out(['ok'=>false,'error'=>'اطلاعات فایل‌های انتخاب‌شده معتبر نیست.'],422);
$count=count($files['name']);
if($count<1||$count>20||count($metadata)!==$count)json_out(['ok'=>false,'error'=>'در هر نوبت بین ۱ تا ۲۰ فایل را با اطلاعات کامل انتخاب کنید.'],422);

$results=[];
$seenNumbers=[];
$seenHashes=[];
$created=0;
for($i=0;$i<$count;$i++){
    $file=['name'=>$files['name'][$i]??'','type'=>$files['type'][$i]??'','tmp_name'=>$files['tmp_name'][$i]??'','error'=>$files['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$files['size'][$i]??0];
    $item=$metadata[$i]??null;
    $uploadedPath=null;
    try{
        if(!is_array($item))throw new RuntimeException('اطلاعات این فایل معتبر نیست.');
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))throw new RuntimeException('آپلود فایل انجام نشد یا فایل موقت معتبر نیست.');
        $partNumber=(int)($item['session_number']??0);
        $jalali=trim((string)($item['session_date']??''));
        $gregorian=trim((string)($item['date_gregorian']??''));
        $hijri=trim((string)($item['hijri_date']??''));
        $converted=jalali_to_gregorian($jalali);
        if($partNumber<1)throw new RuntimeException('شماره قسمت باید عددی مثبت باشد.');
        if((int)($collection['session_count']??0)>0&&$partNumber>(int)$collection['session_count'])throw new RuntimeException('شماره قسمت از تعداد کل ثبت‌شده برای این سلسله بیشتر است.');
        if($converted===null||$gregorian!==$converted)throw new RuntimeException('تاریخ شمسی معتبر نیست یا با تاریخ میلادی آن تطابق ندارد.');
        if($hijri!==''&&strlen($hijri)>100)throw new RuntimeException('تاریخ قمری بیش از حد طولانی است.');
        if(isset($seenNumbers[$partNumber]))throw new RuntimeException('این شماره قسمت در همین نوبت تکرار شده است.');
        $duplicateNumber=db()->prepare('SELECT COUNT(*) FROM lectures WHERE collection_id=? AND session_number=?');
        $duplicateNumber->execute([$collectionId,$partNumber]);
        if((int)$duplicateNumber->fetchColumn()>0)throw new RuntimeException('این شماره قسمت قبلاً در این سلسله ثبت شده است.');

        db()->beginTransaction();
        $uploadedPath=safe_upload($file,'audio',['mp3','m4a','wav','ogg','aac']);
        $hash=hash_file('sha256',__DIR__.'/../'.$uploadedPath);
        if(!is_string($hash))throw new RuntimeException('خواندن محتوای فایل ممکن نشد.');
        if(isset($seenHashes[$hash]))throw new RuntimeException('این فایل با یکی از فایل‌های همین نوبت تکراری است.');
        $insert=db()->prepare('INSERT INTO lectures(title,audio_url,category_id,session_date,session_date_gregorian,hijri_date,description,collection_id,session_number,session_count) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $insert->execute([
            (string)$collection['title'],
            $uploadedPath,
            $collection['category_id']?:null,
            $jalali,
            $gregorian,
            $hijri?:null,
            'جلسه '.$partNumber,
            $collectionId,
            $partNumber,
            $collection['session_count']?:null,
        ]);
        $lectureId=(int)db()->lastInsertId();
        db()->prepare('INSERT IGNORE INTO collection_lectures(collection_id,lecture_id) VALUES(?,?)')->execute([$collectionId,$lectureId]);
        db()->commit();
        $seenNumbers[$partNumber]=true;
        $seenHashes[$hash]=true;
        $created++;
        $results[]=['index'=>$i,'file'=>$file['name'],'ok'=>true,'lecture_id'=>$lectureId,'session_number'=>$partNumber];
    }catch(Throwable $error){
        if(db()->inTransaction())db()->rollBack();
        if($uploadedPath!==null){
            $absolute=__DIR__.'/../'.ltrim($uploadedPath,'/');
            if(is_file($absolute))unlink($absolute);
        }
        if(!$error instanceof RuntimeException)error_log('Bulk lecture upload failed: '.$error->getMessage());
        $results[]=['index'=>$i,'file'=>$file['name'],'ok'=>false,'error'=>$error instanceof RuntimeException?$error->getMessage():'خطای پایگاه داده هنگام ثبت این فایل.'];
    }
}

if($created>0){
    $range=db()->prepare('SELECT MIN(session_date_gregorian),MAX(session_date_gregorian) FROM lectures WHERE collection_id=? AND session_date_gregorian IS NOT NULL');
    $range->execute([$collectionId]);
    [$start,$end]=$range->fetch(PDO::FETCH_NUM);
    if($start&&$end){
        $update=db()->prepare('UPDATE collections SET start_date_gregorian=?,end_date_gregorian=?,start_jalali=?,end_jalali=? WHERE id=?');
        $update->execute([$start,$end,gregorian_to_jalali($start),gregorian_to_jalali($end),$collectionId]);
    }
}
json_out(['ok'=>$created===$count,'created'=>$created,'total'=>$count,'results'=>$results],$created===$count?200:207);
