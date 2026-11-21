<?php
/** Locate social embeds and keep their markup opaque during anchor edits. */
final class OGO_MC_Social_Links {
    private const CLASSES=array(
        'telegram'=>array('telegram-post','telegram-embed','telegram-widget','wp-block-embed-telegram','is-provider-telegram','tgme_widget_message'),
        'facebook'=>array('fb-post','fb-video','fb-page','fb-comments','fb-share-button','fb-like','fb-xfbml-parse-ignore','wp-block-embed-facebook','is-provider-facebook'),
    );
    private const TELEGRAM_DATA=array('data-telegram-post','data-telegram-discussion','data-telegram-embed','data-telegram-login','data-telegram-share');
    private const FACEBOOK_TAGS=array('fb:post','fb:video','fb:page','fb:comments','fb:share-button','fb:like');
    private const VOID_TAGS=array('area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr');

    private static function provider_url($markup,$provider) {
        $text=html_entity_decode(stripslashes($markup),ENT_QUOTES | ENT_HTML5,'UTF-8');
        $hosts=$provider==='facebook'?'(?:(?:[a-z0-9-]+\.)*facebook\.com|(?:www\.)?fb\.watch)':'(?:www\.)?t\.me';
        return preg_match('~(?:https?:)?//'.$hosts.'(?=[:/?#\s"\'<>]|$)~i',$text)===1;
    }

    private static function marked_tag($tag,$name,$provider) {
        if ($provider==='facebook' && in_array($name,self::FACEBOOK_TAGS,true)) return true;
        foreach (OGO_MC_Engine::tag_attributes($tag,$name) as $attribute) {
            $key=strtolower($attribute[1][0]);
            if ($provider==='telegram' && in_array($key,self::TELEGRAM_DATA,true)) return true;
            $value=html_entity_decode($attribute[2][0]??$attribute[3][0]??$attribute[4][0]??'',ENT_QUOTES | ENT_HTML5,'UTF-8');
            if ($key==='class' && array_intersect(preg_split('/\s+/',strtolower(trim($value))),self::CLASSES[$provider])) return true;
            if ($provider==='telegram' && $key==='src' && $name==='script'
                && preg_match('~^(?:https?:)?//(?:www\.)?(?:telegram\.org|t\.me)/js/telegram-widget\.js(?:[?#]|$)~i',$value)) return true;
        }
        return false;
    }

    private static function object_depth($tag) {
        $depth=0;
        foreach (OGO_MC_Engine::html_tokens($tag) as list($inner)) {
            if (preg_match('~^</?object(?=[\s/>])~i',$inner)) $depth+=substr($inner,0,2)==='</'?-1:1;
        }
        return $depth;
    }

    public static function inspect($content,$provider) {
        $tokens=array(); $embeds=0; $container=null; $depth=0; $object_pending=false;
        foreach (OGO_MC_Engine::html_tokens($content,true) as list($tag,$offset)) {
            preg_match('~^<(/?)([a-z][a-z0-9:-]*)(?=[\s/>])~i',$tag,$match);
            $name=strtolower($match[2]??'');
            $closing=($match[1]??'')==='/';
            $self_closing=in_array($name,self::FACEBOOK_TAGS,true) && preg_match('~/\s*>$~',$tag);
            if ($container!==null) {
                if ($object_pending && self::provider_url($tag,$provider)) { ++$embeds; $object_pending=false; }
                if ($name===$container) {
                    if ($closing && --$depth===0) { $container=null; $object_pending=false; }
                    elseif (!$closing && !$self_closing) $depth+=$container==='object'?self::object_depth($tag):1;
                }
                continue;
            }
            $opaque=in_array($name,array('iframe','object','video','audio','template','script','style','textarea','pre','code'),true)
                || preg_match('~^<!--\s*wp:(?:(?:core/)?embed\b|core-embed/)|^\[embed\b~i',$tag);
            $media=in_array($name,array('iframe','object','video','audio','embed'),true);
            $marked=!$closing && $name!=='' && !in_array($name,array('style','textarea','pre','code'),true) && self::marked_tag($tag,$name,$provider);
            $provider_block=preg_match('~^<!--\s*wp:(?:core-embed/'.$provider.'(?=[\s-])|(?:core/)?embed\b(?:(?!-->)[\s\S])*"providerNameSlug"\s*:\s*"'.$provider.'")~i',$tag)===1;
            $wordpress_fallback=!$closing && $name==='blockquote' && stripos($tag,'wp-embedded-content')!==false
                && preg_match('~\G[\s\S]*?</blockquote\s*>~i',$content,$fallback,0,$offset+strlen($tag))
                && self::provider_url($fallback[0],$provider);
            $detected=$provider_block || $marked || $wordpress_fallback || (!$closing && ($media || ($opaque && $name==='')) && self::provider_url($tag,$provider));
            if ($detected) {
                ++$embeds;
                $tokens[]=array($tag,$offset,true);
                if (!$opaque && !$self_closing && !in_array($name,self::VOID_TAGS,true)) {
                    $container=$name;
                    $depth=1;
                }
            } else $tokens[]=array($tag,$offset);
            if ($name==='object' && !$closing) {
                $unclosed=self::object_depth($tag);
                if ($unclosed>0) { $container='object'; $depth=$unclosed; $object_pending=!$detected; }
            }
        }
        return array($tokens,$embeds);
    }
}
