<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/upgrade.php';
require_admin_safe('owner');

$pdo=db();
$method=$_SERVER['REQUEST_METHOD'];

if($method==='GET'){
    $admins=$pdo->query('SELECT id,username,`role`,is_active,created_at FROM admin_users ORDER BY id')->fetchAll();
    foreach($admins as &$admin){
        $admin['id']=(int)$admin['id'];
        $admin['is_active']=(bool)$admin['is_active'];
    }
    unset($admin);
    json_out(['ok'=>true,'admins'=>$admins]);
}

if($method!=='POST')json_out(['ok'=>false,'error'=>'روش نامعتبر است.'],405);
require_csrf();
$data=json_input();
$action=$data['action']??'';
$roles=['owner','content_manager','editor'];
$currentAdminId=(int)$_SESSION['admin_id'];

if($action==='create'){
    $username=clean_text($data['username']??'');
    $password=(string)($data['password']??'');
    $role=(string)($data['role']??'content_manager');
    if($username===''||strlen($username)>100||preg_match('/[\x00-\x1F\x7F]/',$username))json_out(['ok'=>false,'error'=>'نام کاربری معتبر نیست.'],422);
    if(strlen($password)<12)json_out(['ok'=>false,'error'=>'رمز باید دست‌کم ۱۲ نویسه باشد.'],422);
    if(!in_array($role,$roles,true))json_out(['ok'=>false,'error'=>'نقش انتخاب‌شده معتبر نیست.'],422);
    $exists=$pdo->prepare('SELECT 1 FROM admin_users WHERE username=?');
    $exists->execute([$username]);
    if($exists->fetchColumn())json_out(['ok'=>false,'error'=>'این نام کاربری قبلاً ثبت شده است.'],409);
    $pdo->beginTransaction();
    try{
        $insert=$pdo->prepare('INSERT INTO admin_users(username,password_hash,`role`,is_active,must_change_password) VALUES(?,?,?,1,1)');
        $insert->execute([$username,password_hash($password,PASSWORD_DEFAULT),$role]);
        $id=(int)$pdo->lastInsertId();
        audit_admin_event('admin_created','admin_user',$id,['role'=>$role]);
        $pdo->commit();
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('Admin creation failed: '.$error->getMessage());
        json_out(['ok'=>false,'error'=>'ایجاد حساب مدیر انجام نشد.'],500);
    }
    json_out(['ok'=>true,'id'=>$id]);
}

if($action==='update'){
    $id=(int)($data['id']??0);
    $role=(string)($data['role']??'');
    $isActive=filter_var($data['is_active']??null,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
    $password=(string)($data['password']??'');
    if($id<1||!in_array($role,$roles,true)||$isActive===null)json_out(['ok'=>false,'error'=>'اطلاعات مدیر معتبر نیست.'],422);
    if($id===$currentAdminId)json_out(['ok'=>false,'error'=>'نقش یا وضعیت حساب فعلی را نمی‌توانید تغییر دهید.'],422);
    if($password!==''&&strlen($password)<12)json_out(['ok'=>false,'error'=>'رمز باید دست‌کم ۱۲ نویسه باشد.'],422);

    $pdo->beginTransaction();
    try{
        $owners=$pdo->query("SELECT id FROM admin_users WHERE `role`='owner' AND is_active=1 FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
        $targetQuery=$pdo->prepare('SELECT `role`,is_active FROM admin_users WHERE id=? FOR UPDATE');
        $targetQuery->execute([$id]);
        $target=$targetQuery->fetch();
        if(!$target){
            $pdo->rollBack();
            json_out(['ok'=>false,'error'=>'حساب مدیر پیدا نشد.'],404);
        }
        $removesOwner=$target['role']==='owner'&&(int)$target['is_active']===1&&($role!=='owner'||!$isActive);
        if($removesOwner&&count($owners)<=1){
            $pdo->rollBack();
            json_out(['ok'=>false,'error'=>'حداقل یک مالک فعال باید باقی بماند.'],422);
        }
        if($password===''){
            $update=$pdo->prepare('UPDATE admin_users SET `role`=?,is_active=? WHERE id=?');
            $update->execute([$role,(int)$isActive,$id]);
        }else{
            $update=$pdo->prepare('UPDATE admin_users SET `role`=?,is_active=?,password_hash=?,must_change_password=1 WHERE id=?');
            $update->execute([$role,(int)$isActive,password_hash($password,PASSWORD_DEFAULT),$id]);
        }
        audit_admin_event('admin_updated','admin_user',$id,['role'=>$role,'is_active'=>$isActive,'password_reset'=>$password!=='']);
        $pdo->commit();
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('Admin account update failed: '.$error->getMessage());
        json_out(['ok'=>false,'error'=>'به‌روزرسانی حساب مدیر انجام نشد.'],500);
    }
    json_out(['ok'=>true]);
}

json_out(['ok'=>false,'error'=>'عملیات نامعتبر است.'],400);
