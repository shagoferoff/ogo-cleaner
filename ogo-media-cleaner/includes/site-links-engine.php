<?php
/** Classify existing editorial and HTML signals for the external links inventory. */
final class OGO_MC_Site_Links_Engine {
    private static function ad_marker($content) {
        // Ignore comments, hidden code and formatting before testing the visible footer.
        $text=preg_replace('~<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|pre|code)\b[^>]*>[\s\S]*?(?:</\1\s*>|$)~i','',$content);
        $text=preg_replace('~</?(?:p|div|li|section|blockquote|h[1-6])(?=[\s>])[^>]*>|<br\s*/?>~i',"\n",$text);
        $text=html_entity_decode(strip_tags($text),ENT_HTML5 | ENT_QUOTES,'UTF-8');
        return preg_match('~(?:^|\R)[\s\p{Z}«“"\x{27}(\[]*на[\s\p{Z}]+правах[\s\p{Z}]+реклами[\s\p{Z}.!…:;»”"\x{27})\]]*$~iuD',$text)===1;
    }

    public static function inspect($content) {
        $rows=array(); $review=0;
        if (stripos($content,'<a')===false) return array($rows,$review);
        $links=OGO_MC_External_Engine::extract($content,true);
        if (!$links) return array($rows,$review);
        $marker=self::ad_marker($content);
        foreach ($links as $link) {
            if ($link['kind']==='review') { $review+=$link['link_count']; continue; }
            $rel=explode(' ',$link['rel']);
            $sponsored=in_array('sponsored',$rel,true);
            $domain=$link['domain'];
            if (strpos($domain,'www.')===0 && strlen($domain)>4) $domain=substr($domain,4);
            $rows[]=array(
                'domain'=>$domain,'variant_key'=>$link['variant_key'],'url_key'=>$link['url_key'],
                'paid'=>(int)($marker || $sponsored),'ad_marker'=>(int)$marker,
                'closed'=>(int)(bool)array_intersect($rel,array('nofollow','sponsored','ugc')),
                'rel_tokens'=>$link['rel'],'new_tab'=>(int)$link['new_tab'],
                'target_url'=>$link['target_url'],'anchor_text'=>$link['anchor_text'],'link_count'=>$link['link_count'],
            );
        }
        return array($rows,$review);
    }
}
