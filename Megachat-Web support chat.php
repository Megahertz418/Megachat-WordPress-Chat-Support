<?php
/**
 * Plugin Name: Megachat-Web support chat
 * Description: This Plugin Helps you with 24 hour support
 * Version: 0.4.0
 * Author: Megahertz
 */
/**
 * Megachat Support - WordPress Plugin
 * Copyright (C) 2025  Megahertz418
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */
if (!defined('ABSPATH')) exit;
const MEGAHERTZ_OPT_AI_PROVIDER       = 'megahertz_ai_provider';
const MEGAHERTZ_OPT_OPENAI_KEY        = 'megahertz_openai_key';
const MEGAHERTZ_OPT_GEMINI_KEY        = 'megahertz_gemini_key';
const MEGAHERTZ_OPT_KB_CSV_URL        = 'megahertz_kb_csv_url';
const MEGAHERTZ_OPT_SITE_BASE_URL     = 'megahertz_site_base_url';
const MEGAHERTZ_OPT_LOGO_LIGHT        = 'megahertz_ui_logo_light';
const MEGAHERTZ_OPT_LOGO_DARK         = 'megahertz_ui_logo_dark';
const MEGAHERTZ_OPT_TG_BOT_TOKEN      = 'megahertz_tg_bot_token';
const MEGAHERTZ_OPT_TG_CHAT_ID        = 'megahertz_tg_chat_id';
const MEGAHERTZ_OPT_TG_WEBHOOK_SECRET = 'megahertz_tg_webhook_secret';
const MEGAHERTZ_OPT_LOGS              = 'megahertz_logs';
const MEGAHERTZ_LOGS_CAP              = 300;
function megahertz_json($data, $code=200){ return new WP_REST_Response($data, $code); }
function megahertz_err($msg, $debug = null, $code = 500){
  $out = ['ok'=>false, 'error'=>$msg];
  if ($debug !== null) $out['debug']=$debug;
  return megahertz_json($out, $code);
}
function megahertz_is_valid_email($s){
  return (bool) preg_match('/^[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}$/i', (string)$s);
}
function megahertz_uuid(){
  try{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d),4));
  }catch(Exception $e){ return wp_generate_uuid4(); }
}
function megahertz_rate_limit($key, $sec){
  if (get_transient($key)) return false;
  set_transient($key, '1', $sec);
  return true;
}
function megahertz_now(){
  return current_time('mysql');
}
function megahertz_log_event($endpoint, $code, $message, $debug=null, $ip=null){
  try{
    $row = [
      'ts'       => megahertz_now(),
      'endpoint' => (string)$endpoint,
      'code'     => intval($code),
      'message'  => (string)$message,
      'debug'    => is_scalar($debug) ? (string)$debug : ( ($debug!==null) ? wp_json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '' ),
      'ip'       => $ip ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
    ];
    $logs = get_option(MEGAHERTZ_OPT_LOGS, []);
    if (!is_array($logs)) $logs = [];
    $logs[] = $row;
    $n = count($logs);
    if ($n > MEGAHERTZ_LOGS_CAP){
      $logs = array_slice($logs, $n - MEGAHERTZ_LOGS_CAP);
    }
    update_option(MEGAHERTZ_OPT_LOGS, $logs, false);
  }catch(Throwable $e){
  }
}
function megahertz_logs_clear(){
  update_option(MEGAHERTZ_OPT_LOGS, [], false);
}
function megahertz_logs_get(){
  $logs = get_option(MEGAHERTZ_OPT_LOGS, []);
  return is_array($logs) ? array_reverse($logs) : [];
}
register_activation_hook(__FILE__, function(){
  if (!get_option(MEGAHERTZ_OPT_TG_WEBHOOK_SECRET)) {
    update_option(MEGAHERTZ_OPT_TG_WEBHOOK_SECRET, wp_generate_password(48, false, false), true);
  }
  if (get_option(MEGAHERTZ_OPT_LOGS, null) === null){
    update_option(MEGAHERTZ_OPT_LOGS, [], false);
  }
});
function megahertz_norm($t){
  $t = (string)$t; if ($t === '') return '';
  $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹']; $la = ['0','1','2','3','4','5','6','7','8','9'];
  $t = str_replace($fa, $la, $t);
  $t = preg_replace('/\x{200C}/u', ' ', $t);
  return strtolower(trim(preg_replace('/\s+/u', ' ', $t)));
}
function megahertz_tokenize($t){
  $n = megahertz_norm($t); if ($n === '') return [];
  $parts = preg_split('/[^a-z0-9آ-ی]+/u', $n, -1, PREG_SPLIT_NO_EMPTY);
  return is_array($parts) ? $parts : [];
}
function megahertz_lev($a,$b){
  if ($a===$b) return 1.0;
  $la = mb_strlen($a,'UTF-8'); $lb = mb_strlen($b,'UTF-8');
  if ($la===0 || $lb===0) return 0.0;
  $aa=$a; $bb=$b;
  if (function_exists('iconv')){
    $aa = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$a); if ($aa===false) $aa=$a;
    $bb = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$b); if ($bb===false) $bb=$b;
  }
  $d = levenshtein($aa,$bb); $den = max($la,$lb);
  return max(0.0, 1.0 - ($d / max(1,$den)));
}
function megahertz_overlap_score($qTokens,$text){
  $ts = megahertz_tokenize($text ?: ''); if (!$ts) return 0.0;
  $qset = array_fill_keys($qTokens, true); $m=0; foreach($ts as $t){ if(isset($qset[$t])) $m++; }
  return $m / max(1,count($ts));
}
function megahertz_fuzzy_score($qTokens,$text){
  $ts = megahertz_tokenize($text ?: ''); if(!$ts || !$qTokens) return 0.0;
  $s=0; $c=count($qTokens);
  foreach($qTokens as $q){ $best=0.0; foreach($ts as $t){ $sc=megahertz_lev($q,$t); if($sc>$best)$best=$sc; } $s+=$best; }
  return $s / max(1,$c);
}
function megahertz_combined_score($qTokens,$text){ return 0.6*megahertz_overlap_score($qTokens,$text) + 0.4*megahertz_fuzzy_score($qTokens,$text); }
function megahertz_fetch_kb_rows($csv_url){
  $out = []; if(!$csv_url) return $out;
  $res = wp_remote_get($csv_url,['timeout'=>20,'redirection'=>3,'headers'=>['User-Agent'=>'MegahertzWP/1.0','Accept'=>'text/csv,text/plain,*/*']]);
  if (is_wp_error($res)) return $out;
  $code = (int)wp_remote_retrieve_response_code($res);
  if ($code!==200) return $out;
  $csv = (string) wp_remote_retrieve_body($res); if($csv==='') return $out;
  $lines = preg_split('/\r\n|\r|\n/',$csv); if(!$lines || count($lines)<2) return $out;
  $header = str_getcsv(array_shift($lines)); $idx = array_flip($header);
  $ID=$idx['ID']??null; $CAT=$idx['Category']??null; $Q=$idx['Question']??null; $A=$idx['Canonical_Answer']??null; $K=$idx['Keywords/Synonyms']??null;
  foreach($lines as $ln){
    if (trim($ln)==='') continue; $row = str_getcsv($ln); if(!is_array($row)) continue;
    $ans = trim((string)($A!==null ? ($row[$A]??'') : '')); if($ans!==''){
      $out[] = [
        'id'       => $ID!==null?($row[$ID]??''):'',
        'category' => $CAT!==null?($row[$CAT]??''):'',
        'question' => $Q!==null?($row[$Q]??''):'',
        'answer'   => $ans,
        'keywords' => $K!==null?($row[$K]??''):''
      ];
    }
  }
  return $out;
}
function megahertz_kb_search_top($rows,$query,$k=2){
  $qTokens = megahertz_tokenize($query); $scored=[];
  foreach($rows as $r){
    $bag = trim(($r['question']??'').' '.($r['keywords']??''));
    $scored[]=[
      'score'=>megahertz_combined_score($qTokens,$bag),
      'question'=>$r['question']??'',
      'answer'=>$r['answer']??''
    ];
  }
  usort($scored,function($a,$b){ return $b['score']<=>$a['score']; });
  $top = array_slice($scored,0,$k);
  $out=[]; foreach($top as $t){ if(($t['score']??0)>0) $out[]=$t; }
  return $out;
}
function megahertz_strip_html($html){
  $html=(string)$html; $html=preg_replace('#<(script|style)[^>]*>[\s\S]*?</\1>#i',' ',$html);
  $html=strip_tags($html); $html=preg_replace('/\s+/',' ',$html); return trim($html);
}
function megahertz_url_title($title,$url){
  $title=trim(preg_replace('/\s+/',' ',(string)$title)); if($title!=='') return $title;
  $p=wp_parse_url($url); if(!$p) return $url; $path=$p['path']??'/'; return rtrim($path,'/');
}
function megahertz_wp_search_unified($siteBase,$query,$limit=6){
  $out=[]; if(!$siteBase) return $out; $base=rtrim($siteBase,'/');
  $qs=http_build_query(['search'=>$query,'per_page'=>$limit,'_fields'=>'url,title,subtype,id']);
  $url="$base/wp-json/wp/v2/search?$qs";
  $res=wp_remote_get($url,['timeout'=>20,'redirection'=>3,'headers'=>['User-Agent'=>'MegahertzBot/1.0'],'sslverify'=>true]);
  if(is_wp_error($res)) return $out;
  if((int)wp_remote_retrieve_response_code($res)!==200) return $out;
  $data=json_decode((string)wp_remote_retrieve_body($res),true); if(!is_array($data)) return $out;
  foreach($data as $e){
    $u = $e['url'] ?? '';
    $t = megahertz_url_title($e['title'] ?? '', $u);
    $sub = $e['subtype'] ?? '';
    $id  = isset($e['id']) ? intval($e['id']) : 0;
    $text = '';
    if ($sub && $id) {
      $detail_url = "$base/wp-json/wp/v2/$sub/$id?_fields=content,title,link,status";
      $res2 = wp_remote_get($detail_url, [
        'timeout'=>20,'redirection'=>3,
        'headers'=>['User-Agent'=>'MegahertzBot/1.0'],'sslverify'=>true
      ]);
      if (!is_wp_error($res2) && (int)wp_remote_retrieve_response_code($res2)===200){
        $d = json_decode((string)wp_remote_retrieve_body($res2), true);
        if (is_array($d)) {
          $u = $d['link'] ?? $u;
          $t = megahertz_url_title($d['title']['rendered'] ?? $t, $u);
          $text = megahertz_strip_html($d['content']['rendered'] ?? '');
        }
      }
    }
    if ($u) $out[] = ['title'=>$t,'url'=>$u,'text'=>$text];
  }
  return $out;
}
function megahertz_wp_fetch_recent_docs($siteBase,$limit=4){
  $out=[]; if(!$siteBase) return $out; $base=rtrim($siteBase,'/');
  foreach ([['posts',$limit],['pages',$limit]] as $pair){
    [$type,$lim] = $pair;
    $url = "$base/wp-json/wp/v2/$type?per_page=$lim&_fields=link,title,content";
    $res = wp_remote_get($url,[
      'timeout'=>20,'redirection'=>3,'headers'=>['User-Agent'=>'MegahertzBot/1.0'],'sslverify'=>true
    ]);
    if (is_wp_error($res)) continue;
    if ((int)wp_remote_retrieve_response_code($res)!==200) continue;
    $data = json_decode((string)wp_remote_retrieve_body($res), true);
    if (!is_array($data)) continue;
    foreach($data as $p){
      $t = $p['title']['rendered']??'';
      $u = $p['link']??'';
      $tx= $p['content']['rendered']??'';
      if ($u) $out[] = ['title'=>megahertz_url_title($t,$u), 'url'=>$u, 'text'=>megahertz_strip_html($tx)];
    }
  }
  return $out;
}
function megahertz_chunk_text($text,$size,$overlap){
  $words = preg_split('/\s+/',(string)$text,-1,PREG_SPLIT_NO_EMPTY);
  $chunks=[]; $step=max(50,$size-$overlap);
  for($i=0;$i<count($words);$i+=$step){
    $ch=implode(' ',array_slice($words,$i,$size)); if(strlen($ch)>200) $chunks[]=$ch;
  }
  return $chunks;
}
function megahertz_build_site_context_from_docs($docs,$query,$chunksPerDoc=2){
  $qTokens=megahertz_tokenize($query); $picks=[]; $top=array_slice($docs??[],0,6);
  foreach($top as $d){
    $chunks=megahertz_chunk_text($d['text']??'',900,120); $scored=[];
    foreach($chunks as $ch){ $scored[]=['ch'=>$ch,'score'=>megahertz_combined_score($qTokens,$ch)]; }
    usort($scored,function($a,$b){return $b['score']<=>$a['score'];});
    foreach(array_slice($scored,0,$chunksPerDoc) as $s){
      $picks[]=['title'=>$d['title'],'url'=>$d['url'],'excerpt'=>mb_substr($s['ch'],0,1200),'score'=>$s['score']];
    }
  }
  usort($picks,function($a,$b){return $b['score']<=>$a['score'];});
  return array_slice($picks,0,4);
}
function megahertz_normalize_site_picks($picks,$max=4){
  $seen=[]; $out=[]; foreach(($picks??[]) as $p){ $u=trim($p['url']??''); if(!$u||isset($seen[$u])) continue; $seen[$u]=true; $out[]=$p; }
  return array_slice($out,0,$max);
}
function megahertz_build_context($kbHits,$sitePicks){
  $ctx  = "### ROLE\nYou are the support agent of THIS website only.\n\n";
  $ctx .= "### LANGUAGE POLICY (CRITICAL)\n- ALWAYS reply in the SAME language/script as the user's latest message.\n- Detect language from the latest user message ONLY.\n- Be concise, friendly.\n\n";
  $ctx .= "### SCOPE\n- Answer ONLY from KB or SITE; otherwise say info is not available.\n\n";
  $ctx .= "### LINKING\n- If and ONLY if answer relies on SITE, include [U1], [U2], ...\n- Do NOT use [U#] for KB-only.\n\n";
  $ctx .= "### OUTPUT RULES\n- Clean HTML only: <p>, <ol>, <ul>, <li>, <a>, <strong>, <em>, <br>. No markdown.\n- Avoid Title\">Title patterns.\n\n";
  if ($kbHits && count($kbHits)){
    $ctx.="### KB\n";
    foreach($kbHits as $i=>$k){
      $ctx.="- Q".($i+1).": ".$k['question']."\n  A".($i+1).": ".$k['answer']."\n";
    }
  } else { $ctx.="### KB\n(No relevant KB items)\n"; }
  if ($sitePicks && count($sitePicks)){
    $ctx.="\n### SITE\n";
    foreach($sitePicks as $j=>$p){
      $ctx.="- T".($j+1).": ".$p['title']."\n  U".($j+1).": ".$p['url']."\n  X".($j+1).": ".$p['excerpt']."\n";
    }
  } else { $ctx.="\n### SITE\n(No relevant excerpts)\n"; }
  return $ctx;
}
function megahertz_strip_code_fences($s){ $s=(string)$s; $s=preg_replace('/^\s*```(?:html)?\s*/i','',$s); $s=preg_replace('/\s*```\s*$/i','',$s); return trim($s); }
function megahertz_ensure_html_structure($text){
  $s=trim((string)$text); if(preg_match('/<\s*(p|ol|ul|li|a|strong|em|br)\b/i',$s)) return $s;
  $esc=function($x){return esc_html($x);}; $blocks=preg_split('/\n{2,}/',$s); $html=[];
  foreach($blocks as $b){
    $lines=preg_split('/\n/',$b);
    $allList=(count($lines)>1 && array_reduce($lines,function($acc,$ln){return $acc && (bool)preg_match('/^\s*[\d\u06F0-\u06F9]+\.\s+/', $ln);},true));
    if($allList){ $html[]='<ol>'; foreach($lines as $ln){ $t=preg_replace('/^\s*[\d\u06F0-\u06F9]+\.\s+/', '', $ln); $html[]='<li>'.$esc($t).'</li>'; } $html[]='</ol>'; }
    else { $html[]='<p>'.$esc($b).'</p>'; }
  }
  return implode('',$html);
}
function megahertz_replace_link_tokens($answer,$sitePicks){
  return preg_replace_callback('/\[\s*U\s*(\d+)\s*\]/i', function($m)use($sitePicks){
    $i=intval($m[1])-1; if(isset($sitePicks[$i])){
      $t=$sitePicks[$i]['title']; $u=$sitePicks[$i]['url'];
      return '<a href="'.esc_url($u).'">'.esc_html($t).'</a>';
    }
    return '';
  }, (string)$answer);
}
function megahertz_text_is_fa($s){ return preg_match('/[\x{0600}-\x{06FF}]/u',(string)$s)===1; }
function megahertz_pick_cta_text($isFa){ return $isFa ? 'برای جزئیات بیشتر این صفحه را ببین:' : 'For more details, check this page:'; }
function megahertz_should_allow_links($kbHits,$sitePicks,$userMsg,$answerText){
  if (!$sitePicks || !count($sitePicks)) return false;
  if (preg_match('/\[\s*U\s*\d+\s*\]/i',(string)$answerText)) return true;
  $hasKB = ($kbHits && count($kbHits)>0);
  $bestQ=0; $bestA=0;
  foreach($sitePicks as $p){
    $bag=($p['title']??'').' '.($p['excerpt']??'');
    $bestQ=max($bestQ, megahertz_combined_score(megahertz_tokenize($userMsg), $bag));
    $bestA=max($bestA, megahertz_combined_score(megahertz_tokenize(wp_strip_all_tags($answerText)), $bag));
  }
  return $hasKB ? ($bestQ>=0.55 && $bestA>=0.35) : ($bestQ>=0.45);
}
function megahertz_remove_links_and_tokens($answer){
  $s=(string)$answer;
  $s=preg_replace('/\[\s*U\s*\d+\s*\]/i','',$s);
  $s=preg_replace('/<a\b[^>]*>([\s\S]*?)<\/a>/i','$1',$s);
  return $s;
}
function megahertz_call_llm($provider,$userMsg,$context){
  return ($provider==='openai') ? megahertz_call_openai($userMsg,$context) : megahertz_call_gemini($userMsg,$context);
}
function megahertz_call_openai($userMsg,$context){
  $apiKey=trim((string)get_option(MEGAHERTZ_OPT_OPENAI_KEY,'')); if(!$apiKey) return ['ok'=>false,'error'=>'missing_openai_key'];
  $payload=['model'=>'gpt-4o-mini','temperature'=>0.25,'max_tokens'=>700,'messages'=>[
    ['role'=>'system','content'=>
      'You are the support agent of THIS website only.
       LANGUAGE: Always mirror the user\'s language/script from their latest message. Never switch languages unless explicitly asked.
       STYLE: Friendly, concise, professional.
       SOURCES: Use only KB or SITE context—no fabrication.
       LINK TOKENS: Use [U1]/[U2] ONLY if the answer relies on SITE.
       OUTPUT: Clean HTML only (<p>, <ol>, <ul>, <li>, <a>, <strong>, <em>, <br>). No markdown/backticks.
       AVOID: Don’t repeat titles or create Title\">Title patterns.'
    ],
    ['role'=>'system','content'=>$context],
    ['role'=>'user','content'=>$userMsg],
  ]];
  $res=wp_remote_post('https://api.openai.com/v1/chat/completions',[
    'timeout'=>45,
    'headers'=>['Content-Type'=>'application/json','Authorization'=>'Bearer '.$apiKey],
    'body'=>wp_json_encode($payload)
  ]);
  if(is_wp_error($res)) return ['ok'=>false,'error'=>'openai_http_error','debug'=>$res->get_error_message()];
  $code=(int)wp_remote_retrieve_response_code($res); $body=json_decode((string)wp_remote_retrieve_body($res),true);
  if($code!==200||!is_array($body)) return ['ok'=>false,'error'=>'openai_bad_status','debug'=>['code'=>$code,'body'=>$body]];
  $ch=$body['choices'][0]??null; $text=$ch['message']['content']??''; return ['ok'=>true,'text'=>$text?:'<p>No answer.</p>'];
}
function megahertz_call_gemini($userMsg,$context){
  $apiKey=trim((string)get_option(MEGAHERTZ_OPT_GEMINI_KEY,'')); if(!$apiKey) return ['ok'=>false,'error'=>'missing_gemini_key'];
  $url='https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key='.$apiKey;
  $payload=['contents'=>[[ 'parts'=>[
    ['text'=>'You are the support agent of THIS website only.
LANGUAGE: Always mirror the user\'s language/script from their latest message. Never switch languages unless explicitly asked.
STYLE: Friendly, concise, professional.
SOURCES: Use only KB or SITE context—no fabrication.
LINK TOKENS: Use [U1]/[U2] ONLY if the answer relies on SITE.
OUTPUT: Clean HTML only (<p>, <ol>, <ul>, <li>, <a>, <strong>, <em>, <br>). No markdown/backticks.
AVOID: Don’t repeat titles or create Title\">Title patterns.'],
    ['text'=>$context], ['text'=>$userMsg],
  ]]], 'generationConfig'=>['temperature'=>0.25,'maxOutputTokens'=>700]];
  $res=wp_remote_post($url,['timeout'=>45,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode($payload)]);
  if(is_wp_error($res)) return ['ok'=>false,'error'=>'gemini_http_error','debug'=>$res->get_error_message()];
  $code=(int)wp_remote_retrieve_response_code($res); $body=json_decode((string)wp_remote_retrieve_body($res),true);
  if($code!==200) return ['ok'=>false,'error'=>'gemini_bad_status','debug'=>['code'=>$code,'body'=>$body]];
  $cand=$body['candidates'][0]??null; $part=$cand['content']['parts'][0]??null; $text=$part['text']??''; return ['ok'=>true,'text'=>$text?:'<p>No answer.</p>'];
}
add_action('rest_api_init', function () {
  register_rest_route('support/v1', '/chat', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $message = trim((string) $req->get_param('message'));
      if ($message === '') {
        megahertz_log_event('/support/v1/chat', 400, 'empty_message');
        return megahertz_err('empty_message', null, 400);
      }
      $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
      $key = 'megahertz_rl_' . md5('chat_'.$ip);
      if (!megahertz_rate_limit($key, 5)) {
        megahertz_log_event('/support/v1/chat', 429, 'too_many_requests', $key, $ip);
        return megahertz_err('too_many_requests', null, 429);
      }
      $provider = get_option(MEGAHERTZ_OPT_AI_PROVIDER, 'gemini');
      $kbCsvUrl = trim((string)get_option(MEGAHERTZ_OPT_KB_CSV_URL, ''));
      $siteBase = trim((string)get_option(MEGAHERTZ_OPT_SITE_BASE_URL, get_site_url()));
      $kbRows = megahertz_fetch_kb_rows($kbCsvUrl);
      $kbHits = megahertz_kb_search_top($kbRows, $message, 2);
      $docs = megahertz_wp_search_unified($siteBase, $message, 6);
      if (!$docs || !count($docs)) $docs = megahertz_wp_fetch_recent_docs($siteBase, 4);
      $sitePicksRaw = megahertz_build_site_context_from_docs($docs, $message, 2);
      $sitePicks    = megahertz_normalize_site_picks($sitePicksRaw, 4);
      $ctx    = megahertz_build_context($kbHits, $sitePicks);
      $llm    = megahertz_call_llm($provider, $message, $ctx);
      if (!$llm['ok']) {
        megahertz_log_event('/support/v1/chat', 500, $llm['error'], $llm['debug'] ?? null, $ip);
        return megahertz_err($llm['error'], $llm['debug'] ?? null, 500);
      }
      $raw    = $llm['text'] ?? '';
      $answer = megahertz_strip_code_fences($raw);
      $answer = megahertz_ensure_html_structure($answer);
      $allowLinks = megahertz_should_allow_links($kbHits,$sitePicks,$message,$answer);
      if ($allowLinks){
        $answer = megahertz_replace_link_tokens($answer, $sitePicks);
        if (!preg_match('/<a\b[^>]*href=/i',$answer) && count($sitePicks)){
          $best = $sitePicks[0];
          $cta = megahertz_pick_cta_text(megahertz_text_is_fa($message));
          $answer .= '<p>'.esc_html($cta).' <a href="'.esc_url($best['url']).'">'.esc_html($best['title']).'</a></p>';
        }
      } else {
        $answer = megahertz_remove_links_and_tokens($answer);
      }
      megahertz_log_event('/support/v1/chat', 200, 'ok');
      return megahertz_json(['ok'=>true, 'answer'=>$answer], 200);
    }
  ]);
});
add_action('rest_api_init', function () {
  register_rest_route('support/v1', '/agent/send', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function(WP_REST_Request $req){
      $sid    = trim((string)$req->get_param('sid')) ?: megahertz_uuid();
      $email  = trim((string)$req->get_param('email'));
      $name   = trim((string)$req->get_param('name') ?? '');
      $phone  = trim((string)$req->get_param('phone') ?? '');
      $msg    = trim((string)$req->get_param('message'));
      $cmid   = trim((string)$req->get_param('client_msg_id')) ?: megahertz_uuid();
      if (!megahertz_is_valid_email($email)) {
        megahertz_log_event('/support/v1/agent/send', 400, 'invalid_email');
        return megahertz_err('invalid_email', null, 400);
      }
      if ($msg === '') {
        megahertz_log_event('/support/v1/agent/send', 400, 'empty_message');
        return megahertz_err('empty_message', null, 400);
      }
      $key = 'megahertz_rl_' . md5('agent_'.$sid);
      if (!megahertz_rate_limit($key, 3)) {
        megahertz_log_event('/support/v1/agent/send', 429, 'too_many_requests', $key);
        return megahertz_err('too_many_requests', null, 429);
      }
      $botToken = trim((string)get_option(MEGAHERTZ_OPT_TG_BOT_TOKEN, ''));
      $chatId   = trim((string)get_option(MEGAHERTZ_OPT_TG_CHAT_ID, ''));
      if (!$botToken || !$chatId) {
        megahertz_log_event('/support/v1/agent/send', 500, 'telegram_not_configured');
        return megahertz_err('telegram_not_configured', null, 500);
      }
      $lines = ["📨 New message","Email: {$email}"];
      if ($name !== '')  $lines[] = "Name: {$name}";
      if ($phone !== '') $lines[] = "Phone: {$phone}";
      $lines[]="---";
      $lines[]="\"{$msg}\"";
      $text = implode("\n", $lines);
      $tg_url = 'https://api.telegram.org/bot'.$botToken.'/sendMessage';
      $res = wp_remote_post($tg_url, [
        'timeout' => 20,
        'headers' => ['Content-Type'=>'application/json'],
        'body'    => wp_json_encode([
          'chat_id' => $chatId,
          'text'    => $text,
          'disable_web_page_preview' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ]);
      if (is_wp_error($res)) {
        megahertz_log_event('/support/v1/agent/send', 500, 'telegram_http_error', $res->get_error_message());
        return megahertz_err('telegram_http_error', $res->get_error_message(), 500);
      }
      $code = (int) wp_remote_retrieve_response_code($res);
      $body = json_decode((string)wp_remote_retrieve_body($res), true);
      if ($code !== 200 || empty($body['result']['message_id'])) {
        megahertz_log_event('/support/v1/agent/send', 500, 'tg_send_failed', $body);
        return megahertz_err('tg_send_failed', $body, 500);
      }
      $tg_mid = (string)$body['result']['message_id'];
      set_transient('megahertz_agent_map_'.$tg_mid, ['sid'=>$sid,'client_msg_id'=>$cmid], 14 * DAY_IN_SECONDS);
      set_transient('megahertz_cmid_' . md5($sid.'|'.$cmid), '1', 14 * DAY_IN_SECONDS);

      megahertz_log_event('/support/v1/agent/send', 200, 'ok');
      return megahertz_json(['ok'=>true, 'sid'=>$sid, 'client_msg_id'=>$cmid], 200);
    }
  ]);
});
add_action('rest_api_init', function () {
  register_rest_route('support/v1', '/inbox', [
    'methods'  => ['GET','POST'],
    'permission_callback' => '__return_true',
    'callback' => function(WP_REST_Request $req){
      $sid = trim((string)$req->get_param('sid'));
      if ($sid === '') {
        megahertz_log_event('/support/v1/inbox', 400, 'missing_sid');
        return megahertz_err('missing_sid', null, 400);
      }
      $key = 'megahertz_rl_' . md5('inbox_'.$sid);
      if (!megahertz_rate_limit($key, 2)) {
        megahertz_log_event('/support/v1/inbox', 429, 'too_many_requests', $key);
        return megahertz_err('too_many_requests', null, 429);
      }
      $inbox_key = 'megahertz_inbox_'.md5($sid);
      $arr = get_transient($inbox_key);
      if (!is_array($arr)) $arr = [];
      delete_transient($inbox_key);
      set_transient($inbox_key, [], 60);
      $resp = megahertz_json(['ok'=>true,'inbox'=>$arr], 200);
      $resp->header('Cache-Control','no-store, no-cache, must-revalidate, max-age=0, private');
      $resp->header('Pragma','no-cache');
      $resp->header('Expires','Wed, 11 Jan 1984 05:00:00 GMT');
      $resp->header('X-Accel-Expires','0');
      $resp->header('Surrogate-Control','no-store');
      megahertz_log_event('/support/v1/inbox', 200, 'ok');
      return $resp;
    }
  ]);
});
add_action('rest_api_init', function () {
  register_rest_route('support/v1', '/tg-webhook', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function(WP_REST_Request $req){
      $secret_sent = (string)$req->get_param('secret');
      $secret = trim((string)get_option(MEGAHERTZ_OPT_TG_WEBHOOK_SECRET, ''));
      if ($secret_sent !== $secret) {
        megahertz_log_event('/support/v1/tg-webhook', 401, 'unauthorized');
        return megahertz_err('unauthorized', null, 401);
      }
      $upd = json_decode($req->get_body(), true);
      if (!is_array($upd)) {
        megahertz_log_event('/support/v1/tg-webhook', 200, 'noop_update');
        return megahertz_json(['ok'=>true], 200);
      }
      $upd_id = isset($upd['update_id']) ? (string)$upd['update_id'] : null;
      if ($upd_id) {
        $seen_key = 'megahertz_tgupd_'.$upd_id;
        if (get_transient($seen_key)) {
          megahertz_log_event('/support/v1/tg-webhook', 200, 'dup_update');
          return megahertz_json(['ok'=>true], 200);
        }
        set_transient($seen_key, '1', 2 * DAY_IN_SECONDS);
      }
      $msg = $upd['message'] ?? $upd['edited_message'] ?? null;
      if (!$msg) {
        megahertz_log_event('/support/v1/tg-webhook', 200, 'no_message');
        return megahertz_json(['ok'=>true], 200);
      }
      $configuredChatId = trim((string)get_option(MEGAHERTZ_OPT_TG_CHAT_ID, ''));
      if ((string)($msg['chat']['id'] ?? '') !== (string)$configuredChatId) {
        megahertz_log_event('/support/v1/tg-webhook', 200, 'skip_chat');
        return megahertz_json(['ok'=>true], 200);
      }
      $text = trim((string)($msg['text'] ?? ($msg['caption'] ?? '')));
      if ($text === '') {
        megahertz_log_event('/support/v1/tg-webhook', 200, 'empty_text');
        return megahertz_json(['ok'=>true], 200);
      }
      $reply_to = $msg['reply_to_message'] ?? null;
      $sid  = null; $cmid = null;
      if ($reply_to && !empty($reply_to['message_id'])) {
        $tg_mid = (string)$reply_to['message_id'];
        $map = get_transient('megahertz_agent_map_'.$tg_mid);
        if (is_array($map) && !empty($map['sid'])) {
          $sid  = (string)$map['sid'];
          $cmid = (string)($map['client_msg_id'] ?? '');
        }
      }
      if (!$sid) {
        $hay = $text;
        if ($reply_to && isset($reply_to['text'])) $hay .= "\n".$reply_to['text'];
        if (preg_match('/\bSID:\s*([a-zA-Z0-9_\-\.|]+)/', $hay, $m))  $sid  = trim($m[1]);
        if (preg_match('/\bCMID:\s*([a-zA-Z0-9_\-\.|]+)/', $hay, $m2)) $cmid = trim($m2[1] ?? '');
      }
      if (!$sid) {
        megahertz_log_event('/support/v1/tg-webhook', 200, 'no_sid_found');
        return megahertz_json(['ok'=>true], 200);
      }
      $inbox_key = 'megahertz_inbox_'.md5($sid);
      $arr = get_transient($inbox_key);
      if (!is_array($arr)) $arr = [];
      $now = time();
      if (!empty($arr)) {
        $last = end($arr);
        if (is_array($last)
            && ($last['from'] ?? '') === 'agent'
            && ($last['text'] ?? '') === $text
            && abs(($last['ts'] ?? 0) - $now) < 3) {
          megahertz_log_event('/support/v1/tg-webhook', 200, 'dedup_same_text');
          return megahertz_json(['ok'=>true], 200);
        }
      }
      $arr[] = ['from'=>'agent','text'=>$text,'ts'=>$now];
      set_transient($inbox_key, $arr, 14 * DAY_IN_SECONDS);
      megahertz_log_event('/support/v1/tg-webhook', 200, 'ok');
      return megahertz_json(['ok'=>true], 200);
    }
  ]);
});
add_action('admin_menu', function(){
  $icon_url = plugin_dir_url(__FILE__).'assets/Megahertz41.png';
  add_menu_page('Megachat Support','Megachat Support','manage_options','megahertz-support-settings','megahertz_settings_page_render',$icon_url,56);
});
add_action('admin_head', function(){
  echo '<style>#adminmenu .toplevel_page_megahertz-support-settings .wp-menu-image img{width:18px;height:18px;object-fit:contain;}</style>';
});
function megahertz_settings_page_render(){
  if (!current_user_can('manage_options')) return;
  $saved=false; $error=null;
  if (isset($_POST['megahertz_clear_logs']) && check_admin_referer('megahertz_settings_save','megahertz_nonce')){
    megahertz_logs_clear();
    $saved = true;
  }
  if (isset($_POST['megahertz_save']) && check_admin_referer('megahertz_settings_save','megahertz_nonce')){
    try{
      $provider = in_array($_POST[MEGAHERTZ_OPT_AI_PROVIDER] ?? '', ['openai','gemini'], true) ? $_POST[MEGAHERTZ_OPT_AI_PROVIDER] : 'gemini';
      update_option(MEGAHERTZ_OPT_AI_PROVIDER,$provider,true);
      update_option(MEGAHERTZ_OPT_OPENAI_KEY, sanitize_text_field($_POST[MEGAHERTZ_OPT_OPENAI_KEY] ?? ''), true);
      update_option(MEGAHERTZ_OPT_GEMINI_KEY, sanitize_text_field($_POST[MEGAHERTZ_OPT_GEMINI_KEY] ?? ''), true);
      update_option(MEGAHERTZ_OPT_KB_CSV_URL, esc_url_raw($_POST[MEGAHERTZ_OPT_KB_CSV_URL] ?? ''), true);
      update_option(MEGAHERTZ_OPT_SITE_BASE_URL, esc_url_raw($_POST[MEGAHERTZ_OPT_SITE_BASE_URL] ?? get_site_url()), true);
      update_option(MEGAHERTZ_OPT_LOGO_LIGHT, esc_url_raw($_POST[MEGAHERTZ_OPT_LOGO_LIGHT] ?? ''), true);
      update_option(MEGAHERTZ_OPT_LOGO_DARK,  esc_url_raw($_POST[MEGAHERTZ_OPT_LOGO_DARK]  ?? ''), true);
      update_option(MEGAHERTZ_OPT_TG_BOT_TOKEN, sanitize_text_field($_POST[MEGAHERTZ_OPT_TG_BOT_TOKEN] ?? ''), true);
      update_option(MEGAHERTZ_OPT_TG_CHAT_ID,   sanitize_text_field($_POST[MEGAHERTZ_OPT_TG_CHAT_ID] ?? ''), true);
      $wh_secret = trim((string)get_option(MEGAHERTZ_OPT_TG_WEBHOOK_SECRET,'')); 
      if(!$wh_secret){ $wh_secret=wp_generate_password(48,false,false); update_option(MEGAHERTZ_OPT_TG_WEBHOOK_SECRET,$wh_secret,true); }
      $saved=true;
    }catch(Throwable $e){ $error=$e->getMessage(); }
  }
  $provider  = get_option(MEGAHERTZ_OPT_AI_PROVIDER,'gemini');
  $openaiKey = get_option(MEGAHERTZ_OPT_OPENAI_KEY,'');
  $geminiKey = get_option(MEGAHERTZ_OPT_GEMINI_KEY,'');
  $kbCsvUrl  = get_option(MEGAHERTZ_OPT_KB_CSV_URL,'');
  $siteBase  = get_option(MEGAHERTZ_OPT_SITE_BASE_URL, get_site_url());
  $logoLight = get_option(MEGAHERTZ_OPT_LOGO_LIGHT,'');
  $logoDark  = get_option(MEGAHERTZ_OPT_LOGO_DARK,'');
  $botToken  = get_option(MEGAHERTZ_OPT_TG_BOT_TOKEN,'');
  $chatId    = get_option(MEGAHERTZ_OPT_TG_CHAT_ID,'');
  $wh_secret = get_option(MEGAHERTZ_OPT_TG_WEBHOOK_SECRET,'');
  $hookUrl  = trailingslashit($siteBase) . 'wp-json/support/v1/tg-webhook?secret=' . rawurlencode($wh_secret);
  $setHook  = $botToken ? ('https://api.telegram.org/bot' . $botToken . '/setWebhook') : '← Save your Bot Token first';
  $hookJSON = wp_json_encode(['url'=>$hookUrl], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  $logs = megahertz_logs_get();
  ?>
  <div class="wrap">
    <h1 style="display:flex; align-items:center; gap:10px;">
      <img src="<?php echo esc_url(plugin_dir_url(__FILE__).'assets/Megahertz41.png'); ?>" alt="" style="width:28px; height:28px; border-radius:6px; object-fit:contain;">
      Megachat Support — Settings
    </h1>
    <?php if($saved): ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
    <?php if($error): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
    <form method="post">
      <?php wp_nonce_field('megahertz_settings_save','megahertz_nonce'); ?>
      <table class="form-table" role="presentation"><tbody>
        <tr><th><label for="ai_provider">AI Provider</label></th><td>
          <select name="<?php echo esc_attr(MEGAHERTZ_OPT_AI_PROVIDER); ?>" id="ai_provider">
            <option value="gemini" <?php selected($provider,'gemini'); ?>>Gemini (Google)</option>
            <option value="openai" <?php selected($provider,'openai'); ?>>OpenAI (ChatGPT)</option>
          </select>
          <p class="description">Choose the model vendor. The assistant must always reply in the user’s input language.</p>
        </td></tr>
        <tr class="row-openai-key"><th><label for="openai_key">OpenAI API Key</label></th><td>
          <input name="<?php echo esc_attr(MEGAHERTZ_OPT_OPENAI_KEY); ?>" id="openai_key" type="text" value="<?php echo esc_attr($openaiKey); ?>" class="regular-text" placeholder="sk-...">
          <p class="description">Required if Provider is OpenAI.</p>
        </td></tr>
        <tr class="row-gemini-key"><th><label for="gemini_key">Gemini API Key</label></th><td>
          <input name="<?php echo esc_attr(MEGAHERTZ_OPT_GEMINI_KEY); ?>" id="gemini_key" type="text" value="<?php echo esc_attr($geminiKey); ?>" class="regular-text" placeholder="AIza...">
          <p class="description">Required if Provider is Gemini.</p>
        </td></tr>
        <tr><th><label for="kb_csv">Knowledge Base CSV URL</label></th><td>
          <input name="<?php echo esc_attr(MEGAHERTZ_OPT_KB_CSV_URL); ?>" id="kb_csv" type="url" value="<?php echo esc_attr($kbCsvUrl); ?>" class="regular-text" placeholder="https://docs.google.com/.../pub?output=csv">
          <p class="description">Google Sheets → Publish to the web → CSV. Columns: ID, Category, Question, Canonical_Answer, Keywords/Synonyms.</p>
        </td></tr>
        <tr><th><label for="site_base">Site Base URL (for docs)</label></th><td>
          <input name="<?php echo esc_attr(MEGAHERTZ_OPT_SITE_BASE_URL); ?>" id="site_base" type="url" value="<?php echo esc_attr($siteBase); ?>" class="regular-text">
          <p class="description">Used for on-site search and smart linking when KB has no answer.</p>
        </td></tr>
        <tr><th><label for="logo_light">UI Logo (Light)</label></th><td>
          <input name="<?php echo esc_attr(MEGAHERTZ_OPT_LOGO_LIGHT); ?>" id="logo_light" type="url" value="<?php echo esc_attr($logoLight); ?>" class="regular-text" placeholder="<?php echo esc_attr(plugins_url('assets/Megahertz41.png', __FILE__)); ?>">
          <p class="description">Small horizontal logo (24×24 shown in header). If empty, packaged icon will be used.</p>
        </td></tr>
        <tr><th><label for="logo_dark">UI Logo (Dark)</label></th><td>
          <input name="<?php echo esc_attr(MEGAHERTZ_OPT_LOGO_DARK); ?>" id="logo_dark" type="url" value="<?php echo esc_attr($logoDark); ?>" class="regular-text" placeholder="">
          <p class="description">Optional alternate logo for dark theme.</p>
        </td></tr>
        <tr><th><label for="tg_token">Telegram Bot Token</label></th><td>
          <input name="<?php echo esc_attr(MEGAHERTZ_OPT_TG_BOT_TOKEN); ?>" id="tg_token" type="text" value="<?php echo esc_attr($botToken); ?>" class="regular-text" placeholder="123456:ABC...">
          <p class="description">From @BotFather.</p>
        </td></tr>
        <tr><th><label for="tg_chat">Telegram Chat ID</label></th><td>
          <input name="<?php echo esc_attr(MEGAHERTZ_OPT_TG_CHAT_ID); ?>" id="tg_chat" type="text" value="<?php echo esc_attr($chatId); ?>" class="regular-text" placeholder="-1001234567890 (group) or 123456789 (DM)">
          <p class="description">Admin chat where messages arrive.</p>
        </td></tr>
      </tbody></table>
      <?php submit_button('Save Changes','primary','megahertz_save'); ?>
    </form>
    <?php
      $csvEx    = "ID,Category,Question,Canonical_Answer,Keywords/Synonyms\n1,General,What are your working hours?,We are open Sat-Thu 9:00-18:00,open hours;working hours;time;schedule";
      $hookUrl  = trailingslashit($siteBase) . 'wp-json/support/v1/tg-webhook?secret=' . rawurlencode($wh_secret);
      $setHook  = $botToken ? ('https://api.telegram.org/bot' . $botToken . '/setWebhook') : '← Save your Bot Token first';
      $hookJSON = wp_json_encode(['url'=>$hookUrl], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    ?>
    <div class="card" style="margin-top:18px; padding:16px; border:1px solid #e5e7eb; border-radius:10px; background:#fff;">
      <h2 style="margin:0 0 10px; font-size:18px;">How to configure KB CSV (Google Sheets)</h2>
      <ol style="margin:8px 0 0 20px;">
        <li>Create a Sheet with headers: <code>ID</code>, <code>Category</code>, <code>Question</code>, <code>Canonical_Answer</code>, <code>Keywords/Synonyms</code>.</li>
        <li>File → <em>Publish to the web</em> → format <strong>CSV</strong>.</li>
        <li>Paste the CSV link above and Save.</li>
      </ol>
      <p style="margin-top:10px;"><strong>Example header + one row:</strong></p>
      <textarea readonly style="width:100%; height:90px; font-family:monospace; font-size:12px; padding:8px; border:1px solid #e5e7eb; border-radius:8px;"><?php echo esc_textarea($csvEx); ?></textarea>
      <button type="button" class="button" onclick="(async()=>{await navigator.clipboard.writeText(`<?php echo esc_js($csvEx); ?>`); alert('Example copied');})();">Copy Example</button>
    </div>
    <div class="card" style="margin-top:18px; padding:16px; border:1px solid #e5e7eb; border-radius:10px; background:#fff;">
      <h2 style="margin:0 0 10px; font-size:18px;">Telegram Webhook</h2>
      <p>Send this <strong>JSON</strong> to the <strong>URL</strong> below via <code>POST</code> to set your webhook.</p>
      <p style="margin-top:8px;">JSON body:</p>
      <textarea readonly style="width:100%; height:70px; font-family:monospace; font-size:12px; padding:8px; border:1px solid #e5e7eb; border-radius:8px;"><?php echo esc_textarea($hookJSON); ?></textarea>
      <button type="button" class="button" onclick="(async()=>{await navigator.clipboard.writeText(`<?php echo esc_js($hookJSON); ?>`); alert('JSON copied');})();">Copy JSON</button>
      <p style="margin-top:10px;">POST to:</p>
      <div style="display:flex; gap:6px; align-items:center;">
        <input type="text" readonly value="<?php echo esc_attr($setHook); ?>" style="flex:1; padding:8px; border:1px solid #e5e7eb; border-radius:8px;">
        <button type="button" class="button" <?php echo ($botToken?'':'disabled'); ?> onclick="(async()=>{await navigator.clipboard.writeText('<?php echo esc_js($setHook); ?>'); alert('Endpoint copied');})();">Copy URL</button>
      </div>
      <p style="margin-top:12px; color:#6b7280;">Admins must <strong>Reply</strong> to bot messages in Telegram so answers route to the correct user session (SID).</p>
    </div>
    <style>
      .mhz-log-card{ margin-top:18px; border:1px solid #e5e7eb; border-radius:10px; background:#fff; overflow:hidden; }
      .mhz-log-head{ padding:12px 14px; font-size:16px; font-weight:600; background:#f8fafc; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; gap:10px; }
      .mhz-log-scroll{ overflow:auto; max-width:100%; }
      .mhz-log-table{ width:100%; min-width:1400px; border-collapse:separate; border-spacing:0; }
      .mhz-log-table thead th{ position:sticky; top:0; background:#ffffff; z-index:2; border-bottom:1px solid #e5e7eb; box-shadow:0 1px 0 #e5e7eb; }
      .mhz-log-table th,.mhz-log-table td{ padding:8px 10px; font-size:13px; vertical-align:top; border-bottom:1px solid #f1f5f9; white-space:nowrap; }
      .mhz-col-time{ width:160px; } .mhz-col-endpoint{ width:240px; } .mhz-col-code{ width:90px; text-align:center; } .mhz-col-msg{ width:420px; } .mhz-col-debug{ width:560px; } .mhz-col-ip{ width:130px; }
      .mhz-pre{ white-space:pre-wrap; word-break:break-word; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace; font-size:12px; color:#374151; }
      .mhz-badge{ display:inline-block; padding:2px 8px; border-radius:999px; font-weight:700; font-size:12px; background:#eef2ff; color:#3730a3; }
      .mhz-badge.ok{ background:#ecfdf5; color:#065f46; } .mhz-badge.warn{ background:#fff7ed; color:#9a3412; } .mhz-badge.err{ background:#fef2f2; color:#991b1b; }
      body.admin-color-midnight .mhz-log-table thead th{ background:#111827; color:#e5e7eb; }
    </style>
    <div class="mhz-log-card">
      <div class="mhz-log-head">
        <span>Logs (latest <?php echo number_format_i18n(MEGAHERTZ_LOGS_CAP); ?>)</span>
        <form method="post" onsubmit="return confirm('Clear all logs?');" style="margin:0;">
          <?php wp_nonce_field('megahertz_settings_save','megahertz_nonce'); ?>
          <button class="button button-secondary" name="megahertz_clear_logs" value="1">Clear logs</button>
        </form>
      </div>
      <div class="mhz-log-scroll">
        <table class="mhz-log-table">
          <thead>
            <tr>
              <th class="mhz-col-time">Time</th>
              <th class="mhz-col-endpoint">Endpoint</th>
              <th class="mhz-col-code">Code</th>
              <th class="mhz-col-msg">Message</th>
              <th class="mhz-col-debug">Debug</th>
              <th class="mhz-col-ip">IP</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($logs)): ?>
            <tr><td colspan="6" style="padding:14px; color:#6b7280;">No logs yet.</td></tr>
          <?php else: foreach ($logs as $row):
            $code = (int)($row['code'] ?? 0);
            $badgeClass = ($code>=500) ? 'err' : (($code>=400) ? 'warn' : 'ok');
          ?>
            <tr>
              <td><?php echo esc_html($row['ts'] ?? ''); ?></td>
              <td><?php echo esc_html($row['endpoint'] ?? ''); ?></td>
              <td><span class="mhz-badge <?php echo esc_attr($badgeClass); ?>"><?php echo esc_html($code); ?></span></td>
              <td><div class="mhz-pre"><?php echo esc_html($row['message'] ?? ''); ?></div></td>
              <td><div class="mhz-pre"><?php echo esc_html($row['debug'] ?? ''); ?></div></td>
              <td><?php echo esc_html($row['ip'] ?? ''); ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <script>
    (function(){
      function toggleKeys(){
        var p=document.getElementById('ai_provider').value,
            rO=document.querySelector('.row-openai-key'),
            rG=document.querySelector('.row-gemini-key');
        if(p==='openai'){ rO.style.display='table-row'; rG.style.display='none'; }
        else { rO.style.display='none'; rG.style.display='table-row'; }
      }
      document.getElementById('ai_provider').addEventListener('change',toggleKeys);
      toggleKeys();
    })();
  </script>
  <?php
}
add_action('wp_enqueue_scripts', function () {
  $base = plugin_dir_url(__FILE__) . 'assets/';
  wp_enqueue_style(
    'megachat-support-css',
    $base . 'Megachat-Web support chat.css',
    [],
    '0.9.2'
  );
  $inline_css = '
  #mz-support-root .mz-bot a, #mz-support-root .mz-agent-op a { text-decoration: underline; }
  #mz-support-root:not(.mz-dark) .mz-bot a, #mz-support-root:not(.mz-dark) .mz-agent-op a { color: #1e88e5; }
  #mz-support-root.mz-dark .mz-bot a, #mz-support-root.mz-dark .mz-agent-op a { color: #90caf9; }
  #mz-support-root .mz-bot a:hover, #mz-support-root .mz-agent-op a:hover { text-decoration: underline; filter: brightness(0.9); }';
  wp_add_inline_style('megachat-support-css', $inline_css);
  wp_enqueue_script(
    'megachat-support-js',
    $base . 'Megachat-Web support chat.js',
    [],
    '0.9.2',
    true
  );
  $logoLight = trim((string)get_option(MEGAHERTZ_OPT_LOGO_LIGHT,''));
  if ($logoLight === '') {
    $logoLight = plugins_url('assets/Megahertz41.png', __FILE__);
  }
  $logoLight = set_url_scheme($logoLight, is_ssl() ? 'https' : 'http');
  wp_localize_script('megachat-support-js', 'megahertzChatData', [
    'ep_chat'    => esc_url(rest_url('support/v1/chat')),
    'ep_send'    => esc_url(rest_url('support/v1/agent/send')),
    'ep_inbx'    => esc_url(rest_url('support/v1/inbox')),
    'logo_light' => esc_url($logoLight),
  ]);
});
add_action('wp_footer', function () {
  if (is_admin()) return;
  $path = plugin_dir_path(__FILE__) . 'assets/Megachat-Web support chat.html';
  if (file_exists($path)) {
    readfile($path);
    $logoLight = trim((string)get_option(MEGAHERTZ_OPT_LOGO_LIGHT,''));
    if ($logoLight === '') {
      $logoLight = plugins_url('assets/Megahertz41.png', __FILE__);
    }
    $logoLight = set_url_scheme($logoLight, is_ssl() ? 'https' : 'http');
    ?>
    <script>
    (function(){
      try{
        var url = <?php echo json_encode(esc_url($logoLight)); ?>;
        var host = document.getElementById('mz-support-root');
        if(!host || !url) return;
        var container = host.querySelector('.mz-left');
        if(!container) return;
        var img = container.querySelector('img.mz-logo');
        if(!img){
          img = new Image();
          img.className = 'mz-logo';
          img.alt = 'logo';
          img.loading = 'lazy';
          img.decoding = 'async';
          img.referrerPolicy = 'no-referrer';
          container.insertBefore(img, container.firstChild);
        }
        img.onerror = function(){ this.style.display='none'; };
        if (!img.src) img.src = url;
      }catch(e){}
    })();
    </script>
    <?php
  }
});