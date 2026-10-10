USE ranjbar_maaref_v2;
INSERT IGNORE INTO categories(name,slug,kind) VALUES
('جلسات اخلاق','akhlaq','audio'),('جلسات فقه و اصول','fiqh-usul','audio'),('منبر محرم','moharram','audio'),('تفسیر قرآن','quran-tafsir','audio'),('منبر فاطمیه','fatemiyeh','audio'),('درس','dars','audio'),('بیان احکام','ahkam','audio'),('مطالب','articles','article'),('اعلانات مراسم','announcements','announcement'),('مجموعه‌ها','collections','collection');
INSERT IGNORE INTO site_settings(`key`,`value`) VALUES('site_description','پایگاه معارف استاد حامد رنجبر؛ آرشیو جلسات صوتی، فیش‌ها، مطالب و اعلان‌های مراسم.'),('bale_url','https://ble.ir/'),('eitaa_url','https://eitaa.com/');
