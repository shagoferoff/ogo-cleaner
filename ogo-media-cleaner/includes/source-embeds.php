<?php
/** Find embeds associated with domains from the external-link audit. */
final class OGO_MC_Source_Embeds {
    private const EMBED_CLASSES=array(
        'wp-block-embed','wp-block-embed__wrapper','wp-embedded-content',
        'embed-responsive','embed-container','video-embed','fb-post','fb-video','telegram-post',
    );
    private const PLAYER_DATA=array(
        'data-embed','data-embed-url','data-oembed-url','data-video','data-video-url','data-player','data-player-url',
    );

    private static function player_tag($tag,$name) {
        foreach (OGO_MC_Engine::tag_attributes($tag,$name) as $attribute) {
            $key=strtolower($attribute[1][0]);
            if (in_array($key,self::PLAYER_DATA,true)) return true;
            if ($key!=='class') continue;
            $value=html_entity_decode($attribute[2][0]??$attribute[3][0]??$attribute[4][0]??'',ENT_QUOTES | ENT_HTML5,'UTF-8');
            if (array_intersect(preg_split('/\s+/',strtolower($value)),self::EMBED_CLASSES)) return true;
        }
        return false;
    }

    private static function mentions_host($markup,$pattern) {
        $text=str_replace('\/','/',html_entity_decode($markup,ENT_QUOTES | ENT_HTML5,'UTF-8'));
        return preg_match($pattern,rawurldecode($text))===1;
    }

    private static function standalone_urls($gap,$before,$after,$pattern) {
        if (stripos($gap,'http')===false) return 0;
        if ($before!=='' && !preg_match('~^<(?:p|div)(?=[\s>])~i',$before)) $gap='>'.$gap;
        if ($after!=='' && !preg_match('~^</(?:p|div)\s*>$~i',$after)) $gap.='<';
        preg_match_all('~^[\t ]*(https?://[^\s<>"\']+)[\t ]*\r?$~im',$gap,$urls);
        $count=0;
        foreach ($urls[1] as $url) if (self::mentions_host($url,$pattern)) ++$count;
        return $count;
    }

    public static function inspect($content,$domains) {
        $hosts=implode('|',array_map(function($domain){return preg_quote($domain,'~');},$domains));
        if (!preg_match('~'.$hosts.'|[&%]~i',$content)) return array(array(),0);
        // Media can be served from a subdomain even when the article link uses the audit host.
        $pattern='~(?<![\w:/])(?:https?:)?//(?:[a-z0-9-]+\.)*(?:'.$hosts.')\.?(?::[0-9]+)?(?=[/?#\s"\'<>\]]|$)~i';
        $tokens=array();
        $embeds=0;
        $container=null;
        $depth=0;
        $anchors=0;
        $previous='';
        $cursor=0;
        $stream=OGO_MC_Engine::html_tokens($content,true,true);
        $stream[]=array('',strlen($content));
        foreach ($stream as list($tag,$offset)) {
            if ($offset>$cursor && $container===null && $anchors===0) {
                $embeds+=self::standalone_urls(substr($content,$cursor,$offset-$cursor),$previous,$tag,$pattern);
            }
            if ($tag==='') break;
            $cursor=$offset+strlen($tag);
            $previous=$tag;
            preg_match('~^<(/?)([a-z][a-z0-9-]*)(?=[\s/>])~i',$tag,$match);
            $name=strtolower($match[2]??'');
            $closing=($match[1]??'')==='/';
            if ($container!==null) {
                if ($name!==$container) continue;
                if ($closing) --$depth;
                elseif ($name==='object') $depth+=self::object_depth($tag);
                else ++$depth;
                if ($depth>0) continue;
                $start=$tokens[$container_index][1];
                $markup=substr($content,$start,$offset+strlen($tag)-$start);
                self::mark($tokens[$container_index],$markup,$pattern,$embeds);
                $container=null;
                continue;
            }
            $tokens[]=array($tag,$offset);
            if ($closing) {
                if ($name==='a' && $anchors>0) --$anchors;
                continue;
            }
            $opaque=in_array($name,array('object','iframe','video','audio','template','script'),true)
                || preg_match('~^<!--\s*wp:(?:(?:core/)?embed\b|core-embed/)|^\[(?:embed|iframe|video|audio)(?=[\s\]/])~i',$tag);
            if ($opaque || $name==='embed') {
                if ($name==='object' && ($depth=self::object_depth($tag))>0) $container='object';
            } elseif (in_array($name,array('a','div','span','figure','blockquote','section','p'),true) && self::player_tag($tag,$name)) {
                $container=$name;
                $depth=1;
            } else {
                if ($name==='a' && !OGO_MC_Engine::self_closing_anchor($tag)) ++$anchors;
                continue;
            }
            $container_index=count($tokens)-1;
            if ($container===null) self::mark($tokens[$container_index],$tag,$pattern,$embeds);
        }
        if ($container!==null) {
            self::mark($tokens[$container_index],substr($content,$tokens[$container_index][1]),$pattern,$embeds);
        }
        return array($tokens,$embeds);
    }

    private static function mark(&$token,$markup,$pattern,&$embeds) {
        $markup=preg_replace('~<!--(?!\s*wp:(?:(?:core/)?embed\b|core-embed/))[\s\S]*?(?:-->|$)|<(pre|code|textarea)(?=[\s>])[^>]*>[\s\S]*?(?:</\1\s*>|$)~i','',$markup);
        if (self::mentions_host($markup,$pattern)) {
            ++$embeds;
            $token[2]=true;
        } else $token[2]='unmatched_embed';
    }

    private static function object_depth($markup) {
        $depth=0;
        foreach (OGO_MC_Engine::html_tokens($markup) as list($tag)) {
            if (preg_match('~^</?object(?=[\s/>])~i',$tag)) $depth+=substr($tag,0,2)==='</'?-1:1;
        }
        return $depth;
    }
}
