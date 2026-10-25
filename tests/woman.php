<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
$lookup = array();
function get_post($id) { global $lookup; return $lookup[$id] ?? null; }
function get_permalink($post) { return $post->permalink; }
function home_url($path = '/') { return 'https://ogo.ua'.$path; }
function is_post_publicly_viewable($post) { return $post->viewable ?? true; }
$count = 0;
function check_woman($input,$expected,$total,$changed,$review) {
    global $count;
    if (class_exists('OGO_MC_Legacy')) OGO_MC_Legacy::clear_cache();
    $r = OGO_MC_Engine::analyze($input,'woman_urls');
    foreach (array('after'=>$expected,'total'=>$total,'removed'=>$changed,'review'=>$review) as $key=>$value) {
        if ($r[$key] !== $value) throw new RuntimeException('Woman case '.($count+1).' failed: '.$key.' '.var_export($r,true));
    }
    $again = OGO_MC_Engine::analyze($r['after'],'woman_urls');
    if ($again['after'] !== $r['after'] || $again['removed'] !== 0) throw new RuntimeException('Woman repeat-run failed');
    ++$count;
}
$map = array('sex_relationships'=>'seks','parents-children'=>'batky-i-dity','recipes'=>'reczepty','adviser'=>'korysni-porady','stories'=>'istoriyi','handmade'=>'rukodillya','beauty-care'=>'krasa','wedding'=>'vesillya');
foreach ($map as $source=>$dest) {
    foreach (array('/woman/rubrics/view/','/rubrics/view/') as $prefix) {
        foreach (array('','/','/page:16','/page:16/') as $suffix) {
            $url = 'https://ogo.ua'.$prefix.$source.$suffix;
            $html = '<p><a href="'.$url.'" title="a > b"><strong>Жіночий клуб</strong></a></p>';
            check_woman($html,str_replace($url,'https://ogo.ua/tag/'.$dest.'/',$html),1,1,0);
        }
    }
}
foreach (array('http://ogo.ua','https://www.ogo.ua','//ogo.ua','//www.ogo.ua','HTTPS://OGO.UA','https://ogo.ua:443','http://ogo.ua:80','') as $origin) {
    foreach (array('"',"'",'') as $quote) {
        $html = '<A HREF='.$quote.$origin.'/woman/rubrics/view/beauty-care/page:2'.$quote.' target="_blank">Краса</A>';
        check_woman($html,'<A HREF='.$quote.'https://ogo.ua/tag/krasa/'.$quote.' target="_blank">Краса</A>',1,1,0);
    }
}
foreach (array('/woman','/woman/','/woman/page:3','/woman/page:3/') as $path) {
    $html = '<a href="https://ogo.ua'.$path.'">Жіночий клуб</a>';
    check_woman($html,'<a href="https://ogo.ua/tag/zhinochyj-klub/">Жіночий клуб</a>',1,1,0);
}
$html = '<!-- wp:paragraph --><p><a href=" /woman/rubrics/view/wedding/?x=1&amp;y=2#page-top ">Весілля</a> <a href="/woman?x=1#section">Клуб</a></p><!-- /wp:paragraph -->';
check_woman($html,'<!-- wp:paragraph --><p><a href=" https://ogo.ua/tag/vesillya/?x=1&amp;y=2 ">Весілля</a> <a href="https://ogo.ua/tag/zhinochyj-klub/?x=1#section">Клуб</a></p><!-- /wp:paragraph -->',2,2,0);
foreach (array('https://evil.example/woman','https://ogo.ua.evil.example/woman','https://user@ogo.ua/woman','https://ogo.ua:444/woman','ftp://ogo.ua/woman','https://ogo.ua/women','/rubrics/view/dim/','/rubrics/view/unknown','https://ogo.ua/tag/zhinochyj-klub/','https://www.wittchen.ua/woman/category/1/sumki.html','https://konfiskat.ua/catalog/woman/odezda','https://www.miomio.com.ua/uk/woman/sabo-ta-myuli') as $url) {
    $html = '<a href="'.$url.'">Посилання</a>';
    check_woman($html,$html,0,0,0);
}
foreach (array('/woman/rubrics/view/unknown','/woman/rubrics/view/wedding/more','/woman/mystery','/woman/2010-02-04/33708','/woman/2010-02-30/33708.html','/woman/2010-02-04/99999999999999999999999999.html') as $url) {
    $html = '<a href="'.$url.'">Клуб</a>';
    check_woman($html,$html,1,0,1);
}
foreach (array('script','style','textarea','pre','code') as $element) {
    $html = '<'.$element.'><a href="/woman/">Клуб</a></'.$element.'>';
    check_woman($html,$html,0,0,0);
    $html = '<'.$element.'><a href="/woman/">Клуб</a>';
    check_woman($html,$html,0,0,0);
}
$html = '<!-- <a href="/woman/">Клуб</a> -->';
check_woman($html,$html,0,0,0);
foreach (array('<a href="/woman/">Клуб','<a href="/woman/"><a href="https://example.com">Клуб</a></a>','<a href="/woman/" />') as $html) check_woman($html,$html,1,0,1);
$html = '<a href="/woman/">Клуб</a> <a href="/woman/rubrics/view/unknown">Інше</a>';
check_woman($html,str_replace('href="/woman/"','href="https://ogo.ua/tag/zhinochyj-klub/"',$html),2,1,1);
$lookup[33708] = (object)array('post_type'=>'post','post_status'=>'publish','post_password'=>'','permalink'=>'https://ogo.ua/news/test-article/');
$article = 'https://ogo.ua/woman/2010-02-04/33708.html';
foreach (array('','#page-top','#section') as $suffix) {
    check_woman('<a href="'.$article.$suffix.'">Стаття</a>','<a href="https://ogo.ua/news/test-article/'.($suffix==='#page-top'?'':$suffix).'">Стаття</a>',1,1,0);
}
foreach (array('post_status'=>'draft','post_password'=>'secret','post_type'=>'page','viewable'=>false,'permalink'=>'https://evil.example/news/','old_woman'=>'https://ogo.ua/woman/2010-02-04/33708.html','old_region'=>'https://ogo.ua/nashregion/2010-02-04/33708.html') as $key=>$value) {
    $saved = clone $lookup[33708];
    $field = strpos($key,'old_')===0 ? 'permalink' : $key;
    $lookup[33708]->$field = $value;
    $html = '<a href="'.$article.'">Стаття</a>';
    check_woman($html,$html,1,0,1);
    $lookup[33708] = $saved;
}
$html = '<a href="'.$article.'?x=1">Стаття</a>';
check_woman($html,$html,1,0,1);
$lookup[33708]->permalink = 'https://ogo.ua/news/changed/';
check_woman('<a href="'.$article.'">Стаття</a>','<a href="https://ogo.ua/news/changed/">Стаття</a>',1,1,0);
unset($lookup[33708]);
$html = '<a href="'.$article.'">Стаття</a>';
check_woman($html,$html,1,0,1);
echo 'Passed '.$count." Woman functional cases.\n";
if (isset($argv[1])) {
    $cases = json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    $changed_posts=0; $links=0;
    foreach ($cases as $case) {
        check_woman($case['before'],$case['expected'],$case['links'],$case['links'],0);
        if ($case['links']) ++$changed_posts;
        $links += $case['links'];
    }
    echo 'Passed '.count($cases).' exported rows: '.$links.' replacements in '.$changed_posts." articles; unrelated content unchanged.\n";
}
