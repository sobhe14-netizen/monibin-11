<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/upgrade.php';

$data=json_input();
$action=(string)($data['action']??'');

if($action==='status'){
    json_out(['ok'=>true,'isUser'=>!empty($_SESSION['account_id']),'username'=>$_SESSION['account_username']??null]);
}

if($action==='register'||$action==='login'){
    $username=preg_replace('/\s+/u',' ',trim(clean_text($data['username']??'')));
    $password=(string)($data['password']??'');
    if($username===''||strlen($username)>100||$password==='')json_out(['ok'=>false,'error'=>'نام کاربری و رمز عبور را وارد کنید.'],422);
    if($action==='register'){
        $passwordLength=function_exists('mb_strlen')?mb_strlen($password,'UTF-8'):strlen($password);
        if($passwordLength<10)json_out(['ok'=>false,'error'=>'رمز عبور باید دست‌کم ۱۰ نویسه باشد.'],422);
        try{
            $statement=db()->prepare('INSERT INTO accounts(username,password_hash) VALUES(?,?)');
            $statement->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
            $id=(int)db()->lastInsertId();
        }catch(PDOException $error){
            if($error->getCode()==='23000')json_out(['ok'=>false,'error'=>'این نام کاربری قبلاً ثبت شده است.'],409);
            throw $error;
        }
    }else{
        if(account_login_is_limited($username))json_out(['ok'=>false,'error'=>'ورود موقتاً محدود شده است؛ ۱۵ دقیقه دیگر دوباره تلاش کنید.'],429);
        $statement=db()->prepare('SELECT id,password_hash FROM accounts WHERE username=? AND is_active=1 LIMIT 1');
        $statement->execute([$username]);
        $account=$statement->fetch();
        if(!$account||!password_verify($password,$account['password_hash'])){record_account_login_failure($username);json_out(['ok'=>false,'error'=>'نام کاربری یا رمز عبور اشتباه است.'],401);}
        clear_account_login_failures($username);
        $id=(int)$account['id'];
    }
    session_regenerate_id(true);
    $_SESSION['account_id']=$id;
    $_SESSION['account_username']=$username;
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    json_out(['ok'=>true,'isUser'=>true,'username'=>$username,'csrfToken'=>$_SESSION['csrf']]);
}

if($action==='logout'){
    require_csrf();
    unset($_SESSION['account_id'],$_SESSION['account_username']);
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    json_out(['ok'=>true,'isUser'=>false]);
}

json_out(['ok'=>false,'error'=>'عملیات نامعتبر است.'],400);
