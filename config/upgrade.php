<?php
declare(strict_types=1);

function upgrade_schema():void{
    static $done=false;
    if($done)return;
    $pdo=db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS lecture_photos(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,lecture_id BIGINT UNSIGNED NOT NULL,url TEXT NOT NULL,caption VARCHAR(255) NULL,sort_order INT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(lecture_id) REFERENCES lectures(id) ON DELETE CASCADE,INDEX(lecture_id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings(`key` VARCHAR(100) PRIMARY KEY,`value` TEXT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS accounts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,username VARCHAR(100) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS account_favorites(account_id BIGINT UNSIGNED NOT NULL,content_type ENUM('fish','lecture','article') NOT NULL,content_id BIGINT UNSIGNED NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(account_id,content_type,content_id),FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,INDEX(content_type,content_id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS account_lecture_progress(account_id BIGINT UNSIGNED NOT NULL,lecture_id BIGINT UNSIGNED NOT NULL,position_seconds INT UNSIGNED NOT NULL DEFAULT 0,duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,is_completed TINYINT(1) NOT NULL DEFAULT 0,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(account_id,lecture_id),FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,FOREIGN KEY(lecture_id) REFERENCES lectures(id) ON DELETE CASCADE,INDEX(updated_at)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS internal_links(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_fish_id BIGINT UNSIGNED NOT NULL,target_type ENUM('fish','article') NOT NULL,target_id BIGINT UNSIGNED NOT NULL,label VARCHAR(255) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(source_fish_id) REFERENCES fishes(id) ON DELETE CASCADE,INDEX(source_fish_id),INDEX(target_type,target_id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_login_attempts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ip_hash CHAR(64) NOT NULL,username_hash CHAR(64) NOT NULL,attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_admin_login_ip_time(ip_hash,attempted_at),INDEX idx_admin_login_user_time(ip_hash,username_hash,attempted_at)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor_admin_id INT UNSIGNED NULL,action VARCHAR(80) NOT NULL,target_type VARCHAR(80) NOT NULL,target_id BIGINT UNSIGNED NULL,details_json JSON NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(actor_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL,INDEX(actor_admin_id,created_at),INDEX(target_type,target_id,created_at)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS account_login_attempts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ip_hash CHAR(64) NOT NULL,username_hash CHAR(64) NOT NULL,attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_account_login_ip_time(ip_hash,attempted_at),INDEX idx_account_login_user_time(ip_hash,username_hash,attempted_at)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tag_parents(tag_id BIGINT UNSIGNED NOT NULL,parent_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(tag_id,parent_id),FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE,FOREIGN KEY(parent_id) REFERENCES tags(id) ON DELETE CASCADE,INDEX(parent_id)) ENGINE=InnoDB");
    $pdo->exec("INSERT IGNORE INTO tag_parents(tag_id,parent_id) SELECT id,parent_id FROM tags WHERE parent_id IS NOT NULL");

    $columns=[
        'admin_users'=>['must_change_password'=>'TINYINT(1) NOT NULL DEFAULT 1','role'=>"ENUM('owner','content_manager','editor') NOT NULL DEFAULT 'owner'"],
        'lectures'=>['session_date_gregorian'=>'DATE NULL','hijri_date'=>'VARCHAR(100) NULL','collection_id'=>'BIGINT UNSIGNED NULL','session_number'=>'INT NULL','session_count'=>'INT NULL','session_text'=>'LONGTEXT NULL'],
        'articles'=>['hijri_date'=>'VARCHAR(100) NULL'],
        'announcements'=>['hijri_date'=>'VARCHAR(100) NULL','start_date_gregorian'=>'DATE NULL','end_date_gregorian'=>'DATE NULL','start_jalali'=>'VARCHAR(30) NULL','end_jalali'=>'VARCHAR(30) NULL'],
        'collections'=>['session_count'=>'INT UNSIGNED NULL','start_date_gregorian'=>'DATE NULL','end_date_gregorian'=>'DATE NULL','start_jalali'=>'VARCHAR(30) NULL','end_jalali'=>'VARCHAR(30) NULL'],
    ];
    $columnCheck=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    foreach($columns as $table=>$fields){
        foreach($fields as $name=>$definition){
            $columnCheck->execute([$table,$name]);
            if(!(int)$columnCheck->fetchColumn())$pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
        }
    }

    $indexes=[
        'lectures'=>['idx_lecture_date'=>'session_date_gregorian'],
        'collections'=>['idx_collection_dates'=>'start_date_gregorian,end_date_gregorian'],
        'announcements'=>['idx_announcement_dates'=>'start_date_gregorian,end_date_gregorian'],
    ];
    $indexCheck=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    foreach($indexes as $table=>$items){
        foreach($items as $name=>$fields){
            $indexCheck->execute([$table,$name]);
            if(!(int)$indexCheck->fetchColumn())$pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` ($fields)");
        }
    }

    $audioCategories=[['منبر محرم','moharram'],['تفسیر قرآن','quran-tafsir'],['منبر فاطمیه','fatemiyeh'],['درس','dars'],['بیان احکام','ahkam']];
    $insertCategory=$pdo->prepare("INSERT IGNORE INTO categories(name,slug,kind) VALUES(?,?,'audio')");
    foreach($audioCategories as [$name,$slug])$insertCategory->execute([$name,$slug]);
        $oldArticleCategory=$pdo->query("SELECT id FROM categories WHERE kind='article' AND name='مقالات' LIMIT 1")->fetchColumn();
        $newArticleCategory=$pdo->query("SELECT id FROM categories WHERE kind='article' AND name='مطالب' LIMIT 1")->fetchColumn();
        if($oldArticleCategory&&!$newArticleCategory)$pdo->prepare('UPDATE categories SET name=? WHERE id=?')->execute(['مطالب',(int)$oldArticleCategory]);

    $legacySeedHash='$2y$12$fWkmLyQvgu7sX6Z3W/ofVOsSLQqczkXYWuLUrrCk.u/Sh7PKaHVtG';
    $pdo->prepare('UPDATE admin_users SET is_active=0,must_change_password=1 WHERE password_hash=?')->execute([$legacySeedHash]);

    $defaults=[
        'site_title'=>'منیبین | درس‌ها و سخنرانی‌های استاد حامد رنجبر',
        'tagline'=>'درس‌ها و سخنرانی‌های استاد حامد رنجبر',
        'canonical_domain'=>'',
        'site_description'=>'این وب‌گاه با هدف فراهم‌کردن دسترسی آسان، منظم و موضوعی به معارف برگرفته از درس‌ها و سخنرانی‌های استاد حامد رنجبر راه‌اندازی شده است.',
        'footer_text'=>'این وب‌گاه با هدف فراهم‌کردن دسترسی آسان، منظم و موضوعی به معارف برگرفته از درس‌ها و سخنرانی‌های استاد حامد رنجبر راه‌اندازی شده است.',
        'eitaa_url'=>'https://eitaa.com/monibin',
        'telegram_url'=>'https://telegram.me/monibin',
        'teacher_name'=>'استاد حامد رنجبر',
        'teacher_bio'=>'',
        'teacher_image_1'=>'',
        'teacher_image_2'=>'',
        'teacher_image_3'=>'',
        'footer_qr_url'=>'assets/monibin-eitaa-qr.svg',
    ];
    $setting=$pdo->prepare('INSERT IGNORE INTO site_settings(`key`,`value`) VALUES(?,?)');
    foreach($defaults as $key=>$value)$setting->execute([$key,$value]);
    $pdo->exec("UPDATE site_settings SET `value`='https://eitaa.com/monibin' WHERE `key`='eitaa_url' AND `value`='https://eitaa.com/'");
    $done=true;
}

if(!function_exists('valid_gregorian_date')){
    function valid_gregorian_date(string $value):bool{
        if($value==='')return true;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date!==false&&$date->format('Y-m-d')===$value;
    }
}
if(!function_exists('jalali_sort_key')){
    function jalali_sort_key($value):string{
        $digits=['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
        $ascii=strtr((string)$value,$digits);
        if(!preg_match('/(\d{3,4})\D+(\d{1,2})\D+(\d{1,2})/',$ascii,$parts))return'0000-00-00';
        return sprintf('%04d-%02d-%02d',(int)$parts[1],(int)$parts[2],(int)$parts[3]);
    }
}

function admin_must_change_password():bool{
    current_admin_role();
    return !empty($_SESSION['must_change_password']);
}

upgrade_schema();