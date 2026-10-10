<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/upgrade.php';
require_admin_safe('owner');
if($_SERVER['REQUEST_METHOD']!=='GET')json_out(['ok'=>false,'error'=>'روش نامعتبر است.'],405);
$rows=db()->query('SELECT log.action,log.target_type,log.target_id,log.details_json,log.created_at,admin.username actor FROM admin_audit_log log LEFT JOIN admin_users admin ON admin.id=log.actor_admin_id ORDER BY log.id DESC LIMIT 100')->fetchAll();
foreach($rows as &$row)$row['details']=json_decode((string)$row['details_json'],true)?:[];
unset($row);
json_out(['ok'=>true,'events'=>$rows]);
