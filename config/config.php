<?php
declare(strict_types=1);
define('DB_HOST',getenv('MONIBIN_DB_HOST')?:'127.0.0.1');
define('DB_NAME',getenv('MONIBIN_DB_NAME')?:'ranjbar_maaref_v2');
define('DB_USER',getenv('MONIBIN_DB_USER')?:'root');
define('DB_PASS',getenv('MONIBIN_DB_PASS')?:'');
const SITE_TITLE='پایگاه معارف استاد حامد رنجبر';
const BALE_URL='https://ble.ir/'; const EITAA_URL='https://eitaa.com/';
if(session_status()!==PHP_SESSION_ACTIVE){session_name('ranjbar_admin');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);session_start();}
function db():PDO{static $pdo;if($pdo instanceof PDO)return $pdo;$pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);return $pdo;}
function json_input():array{$raw=file_get_contents('php://input');$d=json_decode($raw?:'{}',true);return is_array($d)?$d:[];}
function json_out(array $d,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function csrf_token():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function require_admin():void{require_admin_safe();}
function require_csrf():void{if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))json_out(['ok'=>false,'error'=>'نشست امنیتی منقضی شده است؛ صفحه را تازه کنید.'],419);}
function login_ok(string $u,string $p):?int{$s=db()->prepare('SELECT id,password_hash FROM admin_users WHERE username=? AND is_active=1 LIMIT 1');$s->execute([$u]);$account=$s->fetch();return $account&&password_verify($p,$account['password_hash'])?(int)$account['id']:null;}
function admin_login_hashes(string $username):array{return [hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'')),hash('sha256',function_exists('mb_strtolower')?mb_strtolower(trim($username),'UTF-8'):strtolower(trim($username)))];}
function admin_login_is_limited(string $username):bool{[$ipHash,$usernameHash]=admin_login_hashes($username);$ip=db()->prepare('SELECT COUNT(*) FROM admin_login_attempts WHERE ip_hash=? AND attempted_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)');$ip->execute([$ipHash]);if((int)$ip->fetchColumn()>=20)return true;$user=db()->prepare('SELECT COUNT(*) FROM admin_login_attempts WHERE ip_hash=? AND username_hash=? AND attempted_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)');$user->execute([$ipHash,$usernameHash]);return (int)$user->fetchColumn()>=5;}
function record_admin_login_failure(string $username):void{[$ipHash,$usernameHash]=admin_login_hashes($username);$statement=db()->prepare('INSERT INTO admin_login_attempts(ip_hash,username_hash) VALUES(?,?)');$statement->execute([$ipHash,$usernameHash]);db()->exec('DELETE FROM admin_login_attempts WHERE attempted_at<DATE_SUB(NOW(),INTERVAL 1 DAY)');}
function clear_admin_login_failures(string $username):void{[$ipHash,$usernameHash]=admin_login_hashes($username);$statement=db()->prepare('DELETE FROM admin_login_attempts WHERE ip_hash=? AND username_hash=?');$statement->execute([$ipHash,$usernameHash]);}
function account_login_is_limited(string $username):bool{[$ipHash,$usernameHash]=admin_login_hashes($username);$ip=db()->prepare('SELECT COUNT(*) FROM account_login_attempts WHERE ip_hash=? AND attempted_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)');$ip->execute([$ipHash]);if((int)$ip->fetchColumn()>=20)return true;$user=db()->prepare('SELECT COUNT(*) FROM account_login_attempts WHERE ip_hash=? AND username_hash=? AND attempted_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)');$user->execute([$ipHash,$usernameHash]);return (int)$user->fetchColumn()>=5;}
function record_account_login_failure(string $username):void{[$ipHash,$usernameHash]=admin_login_hashes($username);$statement=db()->prepare('INSERT INTO account_login_attempts(ip_hash,username_hash) VALUES(?,?)');$statement->execute([$ipHash,$usernameHash]);db()->exec('DELETE FROM account_login_attempts WHERE attempted_at<DATE_SUB(NOW(),INTERVAL 1 DAY)');}
function clear_account_login_failures(string $username):void{[$ipHash,$usernameHash]=admin_login_hashes($username);$statement=db()->prepare('DELETE FROM account_login_attempts WHERE ip_hash=? AND username_hash=?');$statement->execute([$ipHash,$usernameHash]);}
function current_admin_role():?string{if(empty($_SESSION['admin_id']))return null;$statement=db()->prepare('SELECT `role`,is_active,must_change_password FROM admin_users WHERE id=?');$statement->execute([(int)$_SESSION['admin_id']]);$account=$statement->fetch();if(!$account||(int)$account['is_active']!==1||!in_array($account['role'],['owner','content_manager','editor'],true)){unset($_SESSION['admin_id'],$_SESSION['admin_username'],$_SESSION['admin_role'],$_SESSION['must_change_password']);return null;}$_SESSION['admin_role']=$account['role'];$_SESSION['must_change_password']=(bool)$account['must_change_password'];return $account['role'];}
function require_admin_safe(string $minimumRole='content_manager'):void{$role=current_admin_role();if($role===null)json_out(['ok'=>false,'error'=>'نشست مدیر منقضی شده است. دوباره وارد شوید.'],401);if(!empty($_SESSION['must_change_password']))json_out(['ok'=>false,'error'=>'ابتدا رمز مدیر را تغییر دهید.'],403);$ranks=['editor'=>1,'content_manager'=>2,'owner'=>3];if(!isset($ranks[$minimumRole])||$ranks[$role]<$ranks[$minimumRole])json_out(['ok'=>false,'error'=>'نقش شما اجازه‌ی انجام این عملیات را ندارد.'],403);}
function clean_text($v):string{return trim((string)$v);}
function normalize_tags($raw):array{if(is_string($raw))$raw=preg_split('/[،,]+/u',$raw);if(!is_array($raw))return[];$o=[];$seen=[];foreach($raw as $x){$x=preg_replace('/\s+/u',' ',trim((string)$x));if($x==='')continue;$key=function_exists('mb_strtolower')?mb_strtolower($x,'UTF-8'):strtolower($x);if(isset($seen[$key]))continue;$seen[$key]=true;$o[]=$x;}return $o;}
function jalali_sort_key($value):string{$ascii=strtr((string)$value,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);if(!preg_match('/(\d{3,4})\D+(\d{1,2})\D+(\d{1,2})/',$ascii,$parts))return'0000-00-00';return sprintf('%04d-%02d-%02d',(int)$parts[1],(int)$parts[2],(int)$parts[3]);}
function valid_gregorian_date(string $value):bool{if($value==='')return true;$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $date!==false&&$date->format('Y-m-d')===$value;}
function jalali_to_gregorian(string $value):?string
{
    $ascii=strtr(trim($value),['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    if(!preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/',$ascii,$parts))return null;
    $jy=(int)$parts[1];$jm=(int)$parts[2];$jd=(int)$parts[3];
    if($jy<1||$jm<1||$jm>12||$jd<1||$jd>31||($jm>6&&$jd>30))return null;
    $year=$jy+1595;
    $days=-355668+365*$year+intdiv($year,33)*8+intdiv(($year%33)+3,4)+$jd+($jm<7?($jm-1)*31:($jm-7)*30+186);
    $gy=400*intdiv($days,146097);$days%=146097;
    if($days>36524){$gy+=100*intdiv(--$days,36524);$days%=36524;if($days>=365)$days++;}
    $gy+=4*intdiv($days,1461);$days%=1461;
    if($days>365){$gy+=intdiv($days-1,365);$days=($days-1)%365;}
    $gd=$days+1;$gm=1;
    $monthDays=[31,((($gy%4===0)&&($gy%100!==0))||$gy%400===0)?29:28,31,30,31,30,31,31,30,31,30,31];
    while($gm<=12&&$gd>$monthDays[$gm-1]){$gd-=$monthDays[$gm-1];$gm++;}
    if($gm>12||!checkdate($gm,$gd,$gy))return null;
    $gregorian=sprintf('%04d-%02d-%02d',$gy,$gm,$gd);
    $roundTrip=gregorian_to_jalali($gregorian);
    return $roundTrip===sprintf('%04d/%02d/%02d',$jy,$jm,$jd)?$gregorian:null;
}
function gregorian_to_jalali(string $value):?string
{
    if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$parts))return null;
    $gy=(int)$parts[1];$gm=(int)$parts[2];$gd=(int)$parts[3];
    if(!checkdate($gm,$gd,$gy))return null;
    $gdm=[0,31,59,90,120,151,181,212,243,273,304,334];$gy2=$gm>2?$gy+1:$gy;
    $days=355666+365*$gy+intdiv($gy2+3,4)-intdiv($gy2+99,100)+intdiv($gy2+399,400)+$gd+$gdm[$gm-1];
    $jy=-1595+33*intdiv($days,12053);$days%=12053;$jy+=4*intdiv($days,1461);$days%=1461;
    if($days>365){$jy+=intdiv($days-1,365);$days=($days-1)%365;}
    $jm=$days<186?1+intdiv($days,31):7+intdiv($days-186,30);
    $jd=1+($days<186?$days%31:($days-186)%30);
    return sprintf('%04d/%02d/%02d',$jy,$jm,$jd);
}
function sync_content_tags(PDO $pdo,string $type,int $id,$tags):void{$tags=normalize_tags($tags);$pdo->prepare('DELETE FROM content_tags WHERE content_type=? AND content_id=?')->execute([$type,$id]);$find=$pdo->prepare('SELECT id FROM tags WHERE name=?');$insTag=$pdo->prepare('INSERT INTO tags(name) VALUES(?)');$ins=$pdo->prepare('INSERT IGNORE INTO content_tags(content_type,content_id,tag_id) VALUES(?,?,?)');foreach($tags as $name){$find->execute([$name]);$tid=$find->fetchColumn();if(!$tid){$insTag->execute([$name]);$tid=$pdo->lastInsertId();}$ins->execute([$type,$id,(int)$tid]);}}
function get_tags(PDO $pdo,string $type,int $id):array{$s=$pdo->prepare('SELECT t.name FROM content_tags c JOIN tags t ON t.id=c.tag_id WHERE c.content_type=? AND c.content_id=? ORDER BY t.name');$s->execute([$type,$id]);return $s->fetchAll(PDO::FETCH_COLUMN);}
function audit_admin_event(string $action,string $targetType,?int $targetId,array $details=[]):void
{
    $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json===false)throw new RuntimeException('ثبت سابقه مدیریتی ممکن نشد.');
    $statement=db()->prepare('INSERT INTO admin_audit_log(actor_admin_id,action,target_type,target_id,details_json) VALUES(?,?,?,?,?)');
    $statement->execute([isset($_SESSION['admin_id'])?(int)$_SESSION['admin_id']:null,$action,$targetType,$targetId,$json]);
}
function sync_internal_links(PDO $pdo,int $sourceFishId,string $body):void
{
    $pdo->prepare('DELETE FROM internal_links WHERE source_fish_id=?')->execute([$sourceFishId]);
    $lower=static fn(string $value):string=>function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    $cut=static fn(string $value,int $length):string=>function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);
    $links=[];
    preg_match_all('/\[\[article:(\d+)\|([^\]]+)\]\]/u',$body,$articles,PREG_SET_ORDER);
    foreach($articles as $match)$links['article:'.(int)$match[1]]=['article',(int)$match[1],trim($match[2])];
    preg_match_all('/\[\[fish:(\d+):(\d+)\|([^\]]+)\]\]/u',$body,$fishes,PREG_SET_ORDER);
    foreach($fishes as $match)$links['fish:'.(int)$match[2]]=['fish',(int)$match[2],trim($match[3])];
    $targets=[];
    foreach($pdo->query('SELECT id,title FROM articles')->fetchAll() as $target)$targets[$lower(trim((string)$target['title']))]=['article',(int)$target['id']];
    foreach($pdo->query('SELECT id,title FROM fishes WHERE title IS NOT NULL AND title<>""')->fetchAll() as $target){$title=$lower(trim((string)$target['title']));if(!isset($targets[$title]))$targets[$title]=['fish',(int)$target['id']];}
    preg_match_all('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u',$body,$named,PREG_SET_ORDER);
    foreach($named as $match){
        $title=$lower(trim($match[1]));
        if(isset($targets[$title]))$links[$targets[$title][0].':'.$targets[$title][1]]=[$targets[$title][0],$targets[$title][1],trim($match[2]??$match[1])];
    }
    $exists=['article'=>$pdo->prepare('SELECT 1 FROM articles WHERE id=?'),'fish'=>$pdo->prepare('SELECT 1 FROM fishes WHERE id=?')];
    $insert=$pdo->prepare('INSERT INTO internal_links(source_fish_id,target_type,target_id,label) VALUES(?,?,?,?)');
    foreach($links as [$type,$targetId,$label]){
        $exists[$type]->execute([$targetId]);
        if(!$exists[$type]->fetchColumn())continue;
        $insert->execute([$sourceFishId,$type,$targetId,$cut($label!==''?$label:(string)$targetId,255)]);
    }
}
function parse_seconds($v):int{if(is_numeric($v))return max(0,(int)$v);$v=str_replace(['-','–','—'],';',str_replace('؛',':',(string)$v));$v=str_replace(';',':',$v);$p=array_map('intval',preg_split('/:/',$v));if(count($p)===2)return max(0,$p[0]*60+$p[1]);if(count($p)===3)return max(0,$p[0]*3600+$p[1]*60+$p[2]);return 0;}
function safe_upload(array $file,string $folder,array $exts,int $max=80*1024*1024):string
{
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('آپلود فایل انجام نشد.');
    $temporary=(string)($file['tmp_name']??'');
    if($temporary===''||!is_uploaded_file($temporary))throw new RuntimeException('فایل بارگذاری‌شده معتبر نیست.');
    $name=(string)($file['name']??'');
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    if(!in_array($ext,$exts,true))throw new RuntimeException('نوع فایل مجاز نیست.');
    $size=filesize($temporary);
    if($size===false||$size<1||$size>$max)throw new RuntimeException('حجم فایل نامعتبر یا بیش از حد مجاز است.');
    $mimeTypes=[
        'mp3'=>['audio/mpeg','audio/mp3'],'m4a'=>['audio/mp4','video/mp4','audio/x-m4a'],
        'wav'=>['audio/wav','audio/x-wav','audio/wave','audio/vnd.wave'],
        'ogg'=>['audio/ogg','application/ogg'],'aac'=>['audio/aac','audio/x-aac','audio/mp4'],
        'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],
        'webp'=>['image/webp'],'gif'=>['image/gif'],
    ];
    if(!isset($mimeTypes[$ext]))throw new RuntimeException('این قالب فایل امن برای بارگذاری نیست.');
    if(!class_exists('finfo'))throw new RuntimeException('برای بررسی امن فایل، افزونه‌ی PHP Fileinfo باید فعال باشد.');
    $finfo=new finfo(FILEINFO_MIME_TYPE);
    $mime=$finfo->file($temporary);
    if(!is_string($mime)||!in_array($mime,$mimeTypes[$ext],true))throw new RuntimeException('محتوای فایل با پسوند آن هم‌خوانی ندارد.');
    $dir=__DIR__.'/../uploads/'.$folder;
    if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('ساخت پوشه‌ی بارگذاری ناموفق بود.');
    $base=bin2hex(random_bytes(14)).'.'.$ext;
    if(!move_uploaded_file($temporary,$dir.'/'.$base))throw new RuntimeException('ذخیره فایل ناموفق بود.');
    return 'uploads/'.$folder.'/'.$base;
}
function migrate():void{static $done=false;if($done)return;$pdo=db();$sqls=[
"CREATE TABLE IF NOT EXISTS lecture_photos(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,lecture_id BIGINT UNSIGNED NOT NULL,url TEXT NOT NULL,caption VARCHAR(255) NULL,sort_order INT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(lecture_id) REFERENCES lectures(id) ON DELETE CASCADE,INDEX(lecture_id)) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS site_settings(`key` VARCHAR(100) PRIMARY KEY,`value` TEXT NULL) ENGINE=InnoDB",
];foreach($sqls as $sql)$pdo->exec($sql);
$cols=['admin_users'=>['must_change_password'=>'TINYINT(1) NOT NULL DEFAULT 1','role'=>"ENUM('owner','content_manager','editor') NOT NULL DEFAULT 'owner'"],'lectures'=>['session_date_gregorian'=>'DATE NULL','hijri_date'=>'VARCHAR(100) NULL','collection_id'=>'BIGINT UNSIGNED NULL','session_number'=>'INT NULL','session_count'=>'INT NULL','session_text'=>'LONGTEXT NULL'], 'articles'=>['hijri_date'=>'VARCHAR(100) NULL'], 'announcements'=>['hijri_date'=>'VARCHAR(100) NULL','start_date_gregorian'=>'DATE NULL','end_date_gregorian'=>'DATE NULL','start_jalali'=>'VARCHAR(30) NULL','end_jalali'=>'VARCHAR(30) NULL'], 'collections'=>['session_count'=>'INT UNSIGNED NULL','start_date_gregorian'=>'DATE NULL','end_date_gregorian'=>'DATE NULL','start_jalali'=>'VARCHAR(30) NULL','end_jalali'=>'VARCHAR(30) NULL']];
$columnCheck=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
foreach($cols as $table=>$cs){foreach($cs as $c=>$type){$columnCheck->execute([$table,$c]);if(!(int)$columnCheck->fetchColumn())$pdo->exec("ALTER TABLE `$table` ADD COLUMN `$c` $type");}}
$audioCategories=[['منبر محرم','moharram'],['تفسیر قرآن','quran-tafsir'],['منبر فاطمیه','fatemiyeh'],['درس','dars'],['بیان احکام','ahkam']];
$categoryInsert=$pdo->prepare("INSERT IGNORE INTO categories(name,slug,kind) VALUES(?,?,'audio')");
foreach($audioCategories as [$name,$slug])$categoryInsert->execute([$name,$slug]);
	$oldArticleCategory=$pdo->query("SELECT id FROM categories WHERE kind='article' AND name='مقالات' LIMIT 1")->fetchColumn();
	$newArticleCategory=$pdo->query("SELECT id FROM categories WHERE kind='article' AND name='مطالب' LIMIT 1")->fetchColumn();
	if($oldArticleCategory&&!$newArticleCategory)$pdo->prepare('UPDATE categories SET name=? WHERE id=?')->execute(['مطالب',(int)$oldArticleCategory]);
$indexes=['lectures'=>['idx_lecture_date'=>'session_date_gregorian'],'collections'=>['idx_collection_dates'=>'start_date_gregorian,end_date_gregorian'],'announcements'=>['idx_announcement_dates'=>'start_date_gregorian,end_date_gregorian']];
$indexCheck=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
foreach($indexes as $table=>$items){foreach($items as $name=>$columns){$indexCheck->execute([$table,$name]);if(!(int)$indexCheck->fetchColumn())$pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` ($columns)");}}
$legacySeedHash='$2y$12$fWkmLyQvgu7sX6Z3W/ofVOsSLQqczkXYWuLUrrCk.u/Sh7PKaHVtG';$pdo->prepare('UPDATE admin_users SET is_active=0,must_change_password=1 WHERE password_hash=?')->execute([$legacySeedHash]);
$defaults=['site_description'=>'پایگاه معارف استاد حامد رنجبر؛ آرشیو جلسات صوتی، یادداشت‌ها، مطالب و اعلان‌های مراسم.','bale_url'=>BALE_URL,'eitaa_url'=>EITAA_URL];$st=$pdo->prepare('INSERT IGNORE INTO site_settings(`key`,`value`) VALUES(?,?)');foreach($defaults as $k=>$v)$st->execute([$k,$v]);$done=true;}
migrate();
