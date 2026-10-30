<?php
/** Conservative, byte-offset based cleanup of the newspaper contact boilerplate. */
final class OGO_MC_Gazeta {
    private const TAG = '<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private const ANCHOR = '~<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?</a\s*>~i';
    private static function plain($html) {
        $html=preg_replace('~<br\s*/?>~i',"\n",$html);
        return html_entity_decode(preg_replace('~'.self::TAG.'~','',$html),ENT_QUOTES|ENT_HTML5,'UTF-8');
    }
    private static function normalized($html) { return trim(preg_replace('/[\s\x{00a0}]+/u',' ',self::plain($html))); }
    private static function safe($html) {
        if(strpos($html,'<!--')!==false || strpos(self::plain($html),'[')!==false) return false;
        preg_match_all('~'.self::TAG.'~',$html,$tags);$stack=array();
        foreach($tags[0] as $tag) {
            if(!preg_match('~^<(/?)(a|strong|b|em|i|span|u|s|small|font|br)(?=[\s/>])~i',$tag,$m))return false;
            $name=strtolower($m[2]);
            if($name==='br' && !$m[1])continue;
            if($m[1]){if(array_pop($stack)!==$name)return false;}
            else {if(preg_match('~/\s*>$~',$tag))return false;$stack[]=$name;}
        }
        return !$stack;
    }
    private static function email($html) {
        if(preg_match('/(?<![\pL\pN_.+-])gazeta\.rv\.ua@gmail\.com(?![\pL\pN_-]|\.[\pL\pN])/iu',self::plain($html))===1) return true;
        preg_match_all(self::ANCHOR,$html,$anchors);
        foreach($anchors[0] as $a)if(OGO_MC_Engine::email_target($a,'gazeta.rv.ua@gmail.com'))return true;
        return false;
    }
    private static function kind($html) {
        if(!self::safe($html))return 'unknown';
        $s=self::normalized($html);
        if($s==='')return 'blank';
        if(preg_match('/^Пишіть мені листи за адресою\s*:$/iu',$s))return 'heading';
        if(preg_match('/^33028\s+м\.?\s*Рівне\s*,?\s*вул\.?\s*Шкільна\s*,?\s*2\s*,.*Рівненська газета.*Домовичку Кузі\s*[;.]?$/iu',$s))return 'address';
        if(preg_match('/^(?:або\s+)?надсилайте електронні листи на gazeta\.rv\.ua@gmail\.com(?:\s*\(у назві листа вкажіть\s*[“"«]?Домовичку Кузі[”"»]?\))?\s*[;.]?$/iu',$s))return 'email';
        if(preg_match('/^надсилайте смс із текстом привітання і підписом на телефон\s*\(066\)\s*621-15-47(?:\s*\(\*згідно з тарифами вашого оператора\))?\s*[;.]?$/iu',$s))return 'sms';
        if(preg_match('/^залишайте коментарі на сайті\s+(?:https?:\/\/)?ogo\.ua\/gazeta\/?\s+у рубриці\s*[“"«]?Родина[”"»]?\s*[;.]?$/iu',$s))return 'site';
        if(preg_match('~^(?:або\s+)?пишіть повідомлення Вконтакте\s*\(?\s*(?:https?://)?vk\.com/kuzyadomovichok/?\s*\)?\s*[;.]?$~iu',$s))return 'vk';
        return 'unknown';
    }
    private static function contact_like($html) {
        return preg_match('/^(?:33028|або(?=\s|$)|надсилайте(?=\s|$)|залишайте(?=\s|$)|пишіть(?=\s|$))|gazeta\.rv\.ua|ogo\.ua\/gazeta|Домовичку Кузі|vk\.com\/kuzyadomovichok/iu',self::normalized($html))===1;
    }
    private static function markers($content) {
        $count=0;
        // Tokenize complete HTML tags so fake anchors inside quoted attributes
        // cannot become matches. Opaque code/comment bodies are excluded too.
        $rest=preg_replace_callback('~<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|pre|code)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?(?:</\\1\s*>|$)|'.self::TAG.'~i',function($m)use(&$count){
            if(OGO_MC_Engine::target($m[0],'gazeta') || OGO_MC_Engine::email_target($m[0],'gazeta.rv.ua@gmail.com'))++$count;
            return ' ';
        },$content);
        $plain=self::plain($rest);
        $count+=preg_match_all('/(?<![\pL\pN_.+-])gazeta\.rv\.ua@gmail\.com(?![\pL\pN_-]|\.[\pL\pN])/iu',$plain);
        $count+=preg_match_all('/Пишіть\s+мені\s+листи\s+за\s+адресою\s*:/iu',$plain);
        $count+=preg_match_all('~(?<![\pL\pN_.-])ogo\.ua/gazeta(?=[/\s;,."“”«»)]|$)~iu',$plain);
        return $count;
    }
    private static function units($content) {
        $units=array();$gutenberg=strpos($content,'<!-- wp:')!==false;
        $pattern=$gutenberg?'~<!-- wp:paragraph(?:\s+\{[^\r\n]*\})?\s*-->\s*<p\b[^>]*>([\s\S]*?)</p\s*>\s*<!-- /wp:paragraph -->~i':'~<p\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>([\s\S]*?)</p\s*>~i';
        preg_match_all($pattern,$content,$paragraphs,PREG_OFFSET_CAPTURE|PREG_SET_ORDER);
        preg_match_all('~<(script|style|textarea|pre|code)\b[^>]*>[\s\S]*?</\1\s*>|<!--(?!\s*/?wp:)[\s\S]*?-->~i',$content,$hidden,PREG_OFFSET_CAPTURE);
        $cursor=0;
        foreach($paragraphs as $p) {
            $overlap=false;
            foreach($hidden[0] as $h) if($p[0][1]<$h[1]+strlen($h[0]) && $p[0][1]+strlen($p[0][0])>$h[1]) $overlap=true;
            if($overlap)continue;
            self::gap_units($units,$content,$cursor,$p[0][1],$gutenberg);
            $units[]=array('start'=>$p[0][1],'end'=>$p[0][1]+strlen($p[0][0]),'inner'=>$p[1][0],'offset'=>$p[1][1]);
            $cursor=$p[0][1]+strlen($p[0][0]);
        }
        self::gap_units($units,$content,$cursor,strlen($content),$gutenberg);
        return $units;
    }
    private static function gap_units(&$units,$content,$start,$end,$gutenberg) {
        if($end<=$start)return;
        $gap=substr($content,$start,$end-$start);
        if($gutenberg || preg_match('~<(script|style|textarea|pre|code)\b|<!--~i',$gap)) {
            if(trim($gap)!=='')$units[]=array('start'=>$start,'end'=>$end,'inner'=>$gap,'offset'=>$start,'barrier'=>true);
            return;
        }
        foreach(preg_split('/(?:\r\n|\n|\r)[ \t]*(?:\r\n|\n|\r)+/',$gap,-1,PREG_SPLIT_OFFSET_CAPTURE) as $p) {
            if(trim($p[0])==='')continue;
            $units[]=array('start'=>$start+$p[1],'end'=>$start+$p[1]+strlen($p[0]),'inner'=>$p[0],'offset'=>$start+$p[1]);
        }
    }
    private static function records($units) {
        $records=array();
        foreach($units as $id=>$u) {
            if(!empty($u['barrier']) || !self::safe($u['inner'])) {$records[]=array('unit'=>$id,'start'=>$u['start'],'end'=>$u['end'],'html'=>$u['inner'],'kind'=>'unknown');continue;}
            // Only split at line breaks outside formatting/links, preserving wrappers.
            preg_match_all('~'.self::TAG.'|\r\n|\r|\n~',$u['inner'],$tokens,PREG_OFFSET_CAPTURE);
            $stack=array();$cursor=0;
            foreach($tokens[0] as $token) {
                $is_break=$token[0][0]!=='<' || preg_match('~^<br\s*/?>$~i',$token[0]);
                if($is_break && !$stack) {
                    $html=substr($u['inner'],$cursor,$token[1]-$cursor);
                    $records[]=array('unit'=>$id,'start'=>$u['offset']+$cursor,'end'=>$u['offset']+$token[1]+strlen($token[0]),'html'=>$html,'kind'=>self::kind($html));
                    $cursor=$token[1]+strlen($token[0]);continue;
                }
                if(preg_match('~^<(/?)(a|strong|b|em|i|span|u|s|small|font)(?=[\s/>])~i',$token[0],$m)) {
                    if($m[1]) {if($stack && end($stack)===strtolower($m[2]))array_pop($stack);}
                    else $stack[]=strtolower($m[2]);
                }
            }
            $html=substr($u['inner'],$cursor);
            $records[]=array('unit'=>$id,'start'=>$u['offset']+$cursor,'end'=>$u['offset']+strlen($u['inner']),'html'=>$html,'kind'=>self::kind($html));
        }
        return $records;
    }
    private static function mask($content) {
        return preg_replace_callback('~<!--[\s\S]*?-->|<(script|style|textarea|pre|code)\b[^>]*>[\s\S]*?</\1\s*>~i',function($m){return str_repeat(' ',strlen($m[0]));},$content);
    }
    private static function remove_ranges($content,$ranges) {
        usort($ranges,function($a,$b){return $b[0]<=>$a[0];});
        foreach($ranges as $r)$content=substr_replace($content,'',$r[0],$r[1]-$r[0]);
        return $content;
    }
    public static function analyze($content,$masked) {
        $units=self::units($content);$records=self::records($units);
        $selected=array();$protected=array();$blocks=0;
        foreach($records as $i=>$r) {
            if($r['kind']!=='heading')continue;
            $candidate=array($i);$channel=false;$uncertain=false;
            for($j=$i+1;$j<count($records);++$j) {
                $next=$records[$j];
                if($next['kind']==='heading')break;
                if(!in_array($next['kind'],array('blank','address','email','sms','site','vk'),true)) {
                    $uncertain=self::contact_like($next['html']) || !self::safe($next['html']);
                    if($uncertain)$candidate[]=$j;
                    break;
                }
                $candidate[]=$j;
                if(in_array($next['kind'],array('email','site'),true))$channel=true;
            }
            if($channel && !$uncertain) {foreach($candidate as $j)$selected[$j]=true;++$blocks;}
            else {
                foreach($candidate as $j)$protected[$records[$j]['unit']]=true;
                // A malformed/contact-like line may precede the email. Protect
                // the known continuation too; never leave an orphan heading.
                $last=end($candidate);
                for($k=$last+1;$k<count($records);++$k) {
                    if(!in_array($records[$k]['kind'],array('blank','address','email','sms','site','vk'),true))break;
                    $protected[$records[$k]['unit']]=true;
                }
            }
        }
        // Expand a fully selected paragraph to include its <p>/block wrapper.
        $ranges=array();
        foreach($units as $uid=>$u) {
            $members=array();foreach($records as $i=>$r)if($r['unit']===$uid)$members[]=$i;
            $all=true;$any=false;
            foreach($members as $i){if(isset($selected[$i]))$any=true;elseif($records[$i]['kind']!=='blank')$all=false;}
            if($any && $all) {$ranges[]=array($u['start'],$u['end']);continue;}
            foreach($members as $i)if(isset($selected[$i]))$ranges[]=array($records[$i]['start'],$records[$i]['end']);
        }
        $after=self::remove_ranges($content,$ranges);
        // Rule 1 follows rule 3. An unresolved contact heading protects nearby
        // units, preventing deletion of its email while leaving an orphan title.
        $email_ranges=array();$email_paragraphs=0;
        foreach(self::units($after) as $u) {
            if(!empty($u['barrier']) || !self::safe($u['inner']) || !self::email($u['inner']))continue;
            $blocked=false;
            foreach($protected as $uid=>$ignored) {
                // Snapshots retain exact protected unit text across the first pass.
                if(self::normalized($units[$uid]['inner'])!=='' && (strpos($units[$uid]['inner'],$u['inner'])!==false || strpos($u['inner'],$units[$uid]['inner'])!==false))$blocked=true;
            }
            if($blocked || preg_match('/Пишіть\s+мені\s+листи\s+за\s+адресою\s*:/iu',self::plain($u['inner'])))continue;
            $email_ranges[]=array($u['start'],$u['end']);++$email_paragraphs;
        }
        $after=self::remove_ranges($after,$email_ranges);
        require_once __DIR__.'/gazeta-links.php';
        $protected_texts=array();
        foreach($protected as $uid=>$ignored)$protected_texts[]=$units[$uid]['inner'];
        $links=OGO_MC_Gazeta_Links::analyze($after,$protected_texts);
        $after=$links['after'];
        $total=self::markers($masked);$remaining=self::markers(self::mask($after));
        return array('after'=>$after,'total'=>$total,'removed'=>max(0,$total-$remaining),'review'=>$remaining,'fragments'=>array(),
            'summary'=>'Контактних блоків: '.$blocks.'; абзаців з email: '.$email_paragraphs.'. '.$links['summary']);
    }
}
