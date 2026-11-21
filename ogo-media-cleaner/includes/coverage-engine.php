<?php
/** Match imported Search Console URLs against links in published material. */
final class OGO_MC_Coverage_Engine {
    public const MAX_BYTES = 2097152;
    private const MAX_ROWS = 5000;

    private static function resolve($url,$base='https://ogo.ua/') {
        // Browsers strip embedded tabs and line breaks and encode literal spaces.
        $url=str_replace(array("\t","\r","\n"),'',trim($url));
        $url=preg_replace_callback('/[^\x21-\x7E]|["<>\x60]/',function($m){return rawurlencode($m[0]);},$url);
        $url=preg_replace_callback('/%([a-f0-9]{2})/i',function($m){
            $byte=chr(hexdec($m[1]));
            return preg_match('/^[A-Za-z0-9._~-]$/D',$byte)?$byte:strtoupper($m[0]);
        },$url);
        $parts=OGO_MC_Audit_Engine::resolve(htmlspecialchars($url,ENT_QUOTES | ENT_HTML5,'UTF-8'),$base);
        if (!$parts || $parts['host']!=='ogo.ua' || $parts['port']!==null) return null;
        $path=$parts['path'].(array_key_exists('query',$parts)?'?'.$parts['query']:'');
        return array('key'=>hash('sha256',$path),'url'=>$parts['url']);
    }

    public static function read_csv($stream) {
        $header=fgetcsv($stream,0,',','"','');
        if (!$header || strcasecmp(ltrim(trim((string)$header[0]),"\xEF\xBB\xBF"),'URL')!==0) return null;
        $targets=array();
        while (($row=fgetcsv($stream,0,',','"',''))!==false) {
            $url=trim((string)$row[0]);
            if ($url==='') continue;
            if (count($targets)>=self::MAX_ROWS) throw new RuntimeException('У файлі понад 5 000 рядків. Розділіть список на кілька CSV.');
            // Treat the export as data. An unsupported URL still gets a report row.
            if (preg_match('//u',$url)!==1) throw new RuntimeException('Збережіть CSV у кодуванні UTF-8.');
            $url_parts=preg_match('~^https?://~i',$url)?self::resolve($url):null;
            $date=trim((string)($row[1]??''));
            $targets[]=array('raw'=>$url,'date'=>preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)?$date:'','key'=>$url_parts['key']??'','url'=>$url_parts['url']??'');
        }
        return $targets;
    }

    public static function extract($content,$base,$keys) {
        // On a local copy, resolve relative links against the original site path.
        $base_parts=parse_url($base);
        $site_host=strtolower($base_parts['host']??'');
        $base=preg_replace('~^https?://[^/?#]+~i','https://ogo.ua',$base);
        $rows=array();
        foreach (OGO_MC_Engine::html_tokens($content) as list($tag)) {
            if (!preg_match('~^<a(?=[\s/>])~i',$tag)) continue;
            $href=OGO_MC_Engine::href_info($tag);
            if (!$href) continue;
            $url=html_entity_decode($href['raw'],ENT_QUOTES | ENT_HTML5,'UTF-8');
            $parts=$href['parts'];
            if ($site_host!=='' && !in_array($site_host,array('ogo.ua','www.ogo.ua'),true)
                && strtolower($parts['host']??'')===$site_host && ($parts['port']??null)===($base_parts['port']??null)
                && !isset($parts['user']) && !isset($parts['pass'])) {
                $url=preg_replace('~^(?:https?:)?//[^/?#]+~i','https://ogo.ua',trim($url));
            }
            $target=self::resolve($url,$base);
            if (!$target || !isset($keys[$target['key']])) continue;
            $variant=hash('sha256',$href['raw']);
            if (isset($rows[$variant])) {
                ++$rows[$variant]['link_count'];
                continue;
            }
            $rows[$variant]=array(
                'group_key'=>$target['key'],'variant_key'=>$variant,'group_url'=>$target['url'],
                'category'=>'coverage_404','target_url'=>'','suggestion'=>'',
                'raw_url'=>$href['raw'],'resolved_url'=>$target['url'],'link_count'=>1,
            );
        }
        return array_values($rows);
    }
}
