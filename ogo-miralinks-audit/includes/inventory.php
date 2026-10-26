<?php
/** Read-only HTTP(S) anchor inventory. It never decides that a link is advertising. */
final class OGO_MLA_Inventory {
    private const TAG='<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private static function family($host,$root) {
        return $host===$root || substr($host,-strlen('.'.$root))==='.' . $root;
    }
    public static function classify($raw) {
        $url=trim(html_entity_decode($raw,ENT_QUOTES | ENT_HTML5,'UTF-8'));
        if (substr($url,0,2)==='//') $url='https:'.$url;
        if (!preg_match('~^https?://~i',$url)) return null;
        $p=parse_url($url);
        if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass']) || preg_match('/[\x00-\x20<>"\x7f]/',$url)) {
            return array('kind'=>'review','domain'=>'','target_url'=>'','raw_url'=>$raw,'issue'=>'Пошкоджена HTTP(S) адреса або облікові дані в URL');
        }
        $host=strtolower(rtrim($p['host'],'.'));
        if (strlen($host)>253) return array('kind'=>'review','domain'=>'','target_url'=>'','raw_url'=>$raw,'issue'=>'Надто довгий домен — потрібна перевірка');
        if (self::family($host,'ogo.ua') || self::family($host,'ogo.rv.ua')) return null;
        // Related editorial sites stay visible, but are not treated as advertisers.
        $network=self::family($host,'my.rv.ua') || self::family($host,'volyninfo.com');
        return array('kind'=>$network?'network':'external','domain'=>$host,'target_url'=>$url,'raw_url'=>$raw,'issue'=>'');
    }
    private static function label($html) {
        $html=preg_replace('~<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|pre|code)\b[^>]*>[\s\S]*?(?:</\1\s*>|$)~i','',$html);
        $text=html_entity_decode(strip_tags($html),ENT_QUOTES | ENT_HTML5,'UTF-8');
        $normalized=preg_replace('/[\s\p{Z}]+/u',' ',$text);
        $text=trim($normalized===null?$text:$normalized);
        return $text===''?'[посилання без тексту]':$text;
    }
    public static function extract($content) {
        $raw='<(script|style|textarea|pre|code)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?(?:</\1\s*>|$)';
        preg_match_all('~<!--[\s\S]*?(?:-->|$)|'.$raw.'|'.self::TAG.'~i',$content,$tokens,PREG_OFFSET_CAPTURE);
        $opens=array(); $links=array(); $stack=array();
        foreach ($tokens[0] as $token) {
            $tag=$token[0]; $offset=$token[1];
            if (preg_match('~^<a(?=[\s/>])~i',$tag)) {
                $info=OGO_MLA_HTML::href_info($tag);
                $row=$info?self::classify($info['raw']):null;
                if ($stack) foreach ($stack as $i) $opens[$i]['bad']=true;
                $i=count($opens);
                $opens[]=array('row'=>$row,'start'=>$offset+strlen($tag),'end'=>null,'bad'=>!empty($stack)||preg_match('~/\s*>$~',$tag));
                $stack[]=$i;
            } elseif (preg_match('~^</a\s*>$~i',$tag) && $stack) {
                $i=array_pop($stack); $opens[$i]['end']=$offset;
            }
        }
        foreach ($opens as $open) {
            if (!$open['row']) continue;
            $row=$open['row'];
            if ($open['bad'] || $open['end']===null) {
                $row['kind']='review'; $row['issue']='Вкладене, незакрите або пошкоджене посилання';
            }
            $row['anchor_text']=$open['end']===null?'[незакрите посилання]':self::label(substr($content,$open['start'],$open['end']-$open['start']));
            $key=hash('sha256',json_encode(array($row['raw_url'],$row['anchor_text'],$row['issue']),JSON_INVALID_UTF8_SUBSTITUTE));
            if (isset($links[$key])) ++$links[$key]['link_count'];
            else { $row['variant_key']=$key; $row['url_key']=hash('sha256',$row['target_url']!==''?$row['target_url']:$row['raw_url']); $row['link_count']=1; $links[$key]=$row; }
        }
        return array_values($links);
    }
}
