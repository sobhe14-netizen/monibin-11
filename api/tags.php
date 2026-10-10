<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/upgrade.php';

$pdo=db();
$method=$_SERVER['REQUEST_METHOD'];

if($method==='GET'){
    $rows=$pdo->query('SELECT id,name,parent_id,definition FROM tags ORDER BY name')->fetchAll();
    $parents=$pdo->query('SELECT tag_id,parent_id FROM tag_parents ORDER BY parent_id,tag_id')->fetchAll();
    $parentMap=[];
    foreach($parents as $parent)$parentMap[(int)$parent['tag_id']][]=(int)$parent['parent_id'];
    $counts=$pdo->query("SELECT ct.tag_id,
        SUM(ct.content_type='lecture') lecture_count,
        SUM(ct.content_type='fish') fish_count,
        SUM(ct.content_type='article') article_count
        FROM content_tags ct
        WHERE ct.content_type IN ('lecture','fish','article')
        GROUP BY ct.tag_id")->fetchAll();
    $countMap=[];
    foreach($counts as $count)$countMap[(int)$count['tag_id']]=[
        'lecture_count'=>(int)$count['lecture_count'],
        'fish_count'=>(int)$count['fish_count'],
        'article_count'=>(int)$count['article_count']
    ];
    foreach($rows as &$row){
        $id=(int)$row['id'];
        $row['id']=$id;
        $row['parent_id']=$row['parent_id']?(int)$row['parent_id']:null;
        $row['parent_ids']=$parentMap[$id]??[];
        $row+=($countMap[$id]??['lecture_count'=>0,'fish_count'=>0,'article_count'=>0]);
    }
    unset($row);
    json_out(['ok'=>true,'items'=>$rows]);
}

require_admin_safe();
require_csrf();
$data=json_input();

if($method==='POST'||$method==='PUT'){
    $id=(int)($data['id']??0);
    $name=preg_replace('/\s+/u',' ',trim(clean_text($data['name']??'')));
    $definition=clean_text($data['definition']??'');
    $parentIds=$data['parent_ids']??null;
    if($parentIds===null)$parentIds=empty($data['parent_id'])?[]:[(int)$data['parent_id']];
    if(!is_array($parentIds))json_out(['ok'=>false,'error'=>'فهرست والدهای برچسب معتبر نیست.'],422);
    $parentIds=array_values(array_unique(array_filter(array_map('intval',$parentIds),static fn($parentId)=>$parentId>0)));
    if($name==='')json_out(['ok'=>false,'error'=>'نام برچسب الزامی است.'],422);
    if($id&&in_array($id,$parentIds,true))json_out(['ok'=>false,'error'=>'برچسب نمی‌تواند والد خودش باشد.'],422);

    try{
        $pdo->beginTransaction();
        if($method==='POST'){
            $statement=$pdo->prepare('INSERT INTO tags(name,definition) VALUES(?,?)');
            $statement->execute([$name,$definition]);
            $id=(int)$pdo->lastInsertId();
        }else{
            $statement=$pdo->prepare('UPDATE tags SET name=?,definition=? WHERE id=?');
            $statement->execute([$name,$definition,$id]);
            if(!$statement->rowCount()&&!$pdo->query('SELECT 1 FROM tags WHERE id='.(int)$id)->fetchColumn()){
                $pdo->rollBack();
                json_out(['ok'=>false,'error'=>'برچسب موردنظر پیدا نشد.'],404);
            }
        }

        $edgeRows=$pdo->query('SELECT tag_id,parent_id FROM tag_parents')->fetchAll();
        $children=[];
        foreach($edgeRows as $edge)$children[(int)$edge['tag_id']][]=(int)$edge['parent_id'];
        $walk=function(int $node,array &$visited)use(&$walk,$children):void{
            if(isset($visited[$node]))return;
            $visited[$node]=true;
            foreach($children[$node]??[] as $parent)$walk($parent,$visited);
        };
        foreach($parentIds as $parentId){
            $visited=[];
            $walk($parentId,$visited);
            if(isset($visited[$id]))throw new DomainException('ارتباط والدها نمی‌تواند چرخه ایجاد کند.');
            if(!$pdo->query('SELECT 1 FROM tags WHERE id='.(int)$parentId)->fetchColumn())throw new DomainException('یکی از برچسب‌های والد پیدا نشد.');
        }

        $pdo->prepare('DELETE FROM tag_parents WHERE tag_id=?')->execute([$id]);
        $insertParent=$pdo->prepare('INSERT INTO tag_parents(tag_id,parent_id) VALUES(?,?)');
        foreach($parentIds as $parentId)$insertParent->execute([$id,$parentId]);
        $legacyParent=$parentIds[0]??null;
        $pdo->prepare('UPDATE tags SET parent_id=? WHERE id=?')->execute([$legacyParent,$id]);
        $pdo->commit();
        json_out(['ok'=>true,'id'=>$id]);
    }catch(DomainException $error){
        if($pdo->inTransaction())$pdo->rollBack();
        json_out(['ok'=>false,'error'=>$error->getMessage()],422);
    }catch(PDOException $error){
        if($pdo->inTransaction())$pdo->rollBack();
        json_out(['ok'=>false,'error'=>$error->getCode()==='23000'?'نام برچسب تکراری است یا والد نامعتبر است.':'ذخیره برچسب انجام نشد.'],422);
    }
}

if($method==='DELETE'){
    $pdo->prepare('DELETE FROM tags WHERE id=?')->execute([(int)($data['id']??0)]);
    json_out(['ok'=>true]);
}
json_out(['ok'=>false,'error'=>'روش نامعتبر است.'],405);
