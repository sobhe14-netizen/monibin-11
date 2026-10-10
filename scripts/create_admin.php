<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../config/config.php';

$username=trim((string)($argv[1]??''));
$password=(string)(getenv('MONIBIN_ADMIN_PASSWORD')?:'');
putenv('MONIBIN_ADMIN_PASSWORD');
unset($_ENV['MONIBIN_ADMIN_PASSWORD'],$_SERVER['MONIBIN_ADMIN_PASSWORD']);

if($username===''||strlen($username)>100||preg_match('/[\x00-\x1F\x7F]/',$username)){
    fwrite(STDERR,"نام کاربری را به‌صورت آرگومان اول وارد کنید (حداکثر ۱۰۰ نویسه).\n");
    exit(2);
}
if(strlen($password)<12){
    fwrite(STDERR,"متغیر محیطی MONIBIN_ADMIN_PASSWORD باید دست‌کم ۱۲ نویسه باشد.\n");
    exit(2);
}

$lockName='monibin_first_admin_bootstrap';
$lock=db()->prepare('SELECT GET_LOCK(?,10)');
$lock->execute([$lockName]);
if((int)$lock->fetchColumn()!==1){
    fwrite(STDERR,"قفل راه‌اندازی مدیر دریافت نشد؛ دوباره تلاش کنید.\n");
    exit(1);
}

try{
    $pdo=db();
    $pdo->beginTransaction();
    $activeAdmin=(int)$pdo->query('SELECT COUNT(*) FROM admin_users WHERE is_active=1')->fetchColumn();
    if($activeAdmin>0){
        throw new RuntimeException('یک حساب مدیر فعال وجود دارد؛ راه‌اندازی اولیه مجاز نیست.');
    }

    $insert=$pdo->prepare("INSERT INTO admin_users(username,password_hash,`role`,is_active,must_change_password) VALUES(?,?,'owner',1,0)");
    $insert->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
    $pdo->commit();
    fwrite(STDOUT,"حساب مدیر با موفقیت ایجاد شد.\n");
}catch(Throwable $error){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,"ایجاد حساب مدیر انجام نشد: ".$error->getMessage()."\n");
    exit(1);
}finally{
    $release=db()->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}
