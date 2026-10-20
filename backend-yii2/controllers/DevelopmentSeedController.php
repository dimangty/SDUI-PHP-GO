<?php
declare(strict_types=1);
namespace Shop\controllers;

use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env};

/** Explicit development-only persisted sample data, never part of application startup. */
final class DevelopmentSeedController extends \yii\console\Controller
{
    public function actionRun(): int
    {
        if(Env::production()){$this->stderr("Development seed is forbidden in production\n");return 1;}
        $db=new Database(Env::get('DATABASE_URL'));
        $created=$db->transaction(function()use($db){
            // Serialize with both another seed and ordinary catalog writers.
            $db->execute('LOCK TABLE categories, products IN SHARE ROW EXCLUSIVE MODE');
            if($db->one('SELECT id FROM categories LIMIT 1') || $db->one('SELECT id FROM products LIMIT 1')){return false;}
            foreach([['Освещение','Тестовая лампа','50.01','lamp'],['Дом','Настольные часы','100.02','clock']] as [$category,$name,$price,$media]){
                $c=Ids::uuid();$p=Ids::uuid();
                $db->execute('INSERT INTO categories(id,name,icon_url) VALUES(?,?,?)',[$c,$category,'/icons/'.$media.'.svg']);
                $db->execute('INSERT INTO products(id,category_id,name,description,price,stock) VALUES(?,?,?,?,?,1000)',[$p,$c,$name,'Development catalog sample persisted in PostgreSQL',$price]);
                $db->execute('INSERT INTO product_images(product_id,url,sort_order) VALUES(?,?,0)',[$p,'/images/products/'.$media.'.png']);
            }
            return true;
        });
        $this->stdout($created?"Created 2 development categories/products; run maintenance/reindex\n":"Catalog already populated; no changes\n");return 0;
    }
}
