<?php
require_once __DIR__.'/../config/config.php';require_once __DIR__.'/../config/upgrade.php';
$data=json_input();
$action=$data['action']??'';

if($action==='login'){
  $username=clean_text($data['username']??'');
  $password=(string)($data['password']??'');
  if(admin_login_is_limited($username))json_out(['ok'=>false,'error'=>'ورود موقتاً محدود شده است؛ ۱۵ دقیقه دیگر دوباره تلاش کنید.'],429);
  $id=login_ok($username,$password);
  if(!$id){record_admin_login_failure($username);json_out(['ok'=>false,'error'=>'نام کاربری یا رمز عبور اشتباه است.'],401);}
  clear_admin_login_failures($username);
  session_regenerate_id(true);
  $account=db()->prepare('SELECT `role`,must_change_password FROM admin_users WHERE id=? AND is_active=1');
  $account->execute([$id]);
  $admin=$account->fetch();
  if(!$admin)json_out(['ok'=>false,'error'=>'حساب مدیر فعال نیست.'],401);
  $_SESSION['admin_id']=$id;
  $_SESSION['admin_username']=$username;
  $_SESSION['admin_role']=$admin['role'];
  $_SESSION['must_change_password']=(bool)$admin['must_change_password'];
  $_SESSION['csrf']=bin2hex(random_bytes(32));
  $csrf=$_SESSION['csrf'];
  session_write_close();
  json_out(['ok'=>true,'isAdmin'=>true,'adminRole'=>$admin['role'],'mustChangePassword'=>$_SESSION['must_change_password'],'csrfToken'=>$csrf]);
}

if($action==='change_password'){
  if(empty($_SESSION['admin_id']))json_out(['ok'=>false,'error'=>'ابتدا وارد شوید.'],401);
  require_csrf();
  $current=(string)($data['current_password']??'');
  $password=(string)($data['new_password']??'');
  if(strlen($password)<12)json_out(['ok'=>false,'error'=>'رمز جدید باید دست‌کم ۱۲ نویسه باشد.'],422);
  if($current===$password)json_out(['ok'=>false,'error'=>'رمز جدید باید با رمز فعلی متفاوت باشد.'],422);
  $id=(int)$_SESSION['admin_id'];
  $statement=db()->prepare('SELECT password_hash FROM admin_users WHERE id=? AND is_active=1');
  $statement->execute([$id]);
  $account=$statement->fetch();
  if(!$account)json_out(['ok'=>false,'error'=>'حساب مدیر فعال نیست.'],401);
  $hash=$account['password_hash'];
  if(!password_verify($current,$hash))json_out(['ok'=>false,'error'=>'رمز فعلی درست نیست.'],401);
  $newHash=password_hash($password,PASSWORD_DEFAULT);
  db()->prepare('UPDATE admin_users SET password_hash=?,must_change_password=0 WHERE id=?')->execute([$newHash,$id]);
  $_SESSION['must_change_password']=false;
  json_out(['ok'=>true,'mustChangePassword'=>false]);
}

if($action==='logout'){
  require_csrf();
  unset($_SESSION['admin_id'],$_SESSION['admin_username'],$_SESSION['admin_role'],$_SESSION['must_change_password']);
  $_SESSION['csrf']=bin2hex(random_bytes(32));
  json_out(['ok'=>true,'isAdmin'=>false,'csrfToken'=>$_SESSION['csrf']]);
}
if($action==='status'){$role=current_admin_role();json_out(['ok'=>true,'isAdmin'=>$role!==null,'adminRole'=>$role,'mustChangePassword'=>!empty($_SESSION['must_change_password']),'csrfToken'=>csrf_token()]);}
json_out(['ok'=>false,'error'=>'عملیات نامعتبر است.'],400);
