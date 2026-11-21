<?php

namespace App\Services\Tenancy;

/**
 * صفحة الـ iframe (HTML كامل من غير Blade): بتتبرمج بهوية الـ tenant، وبتبدّل توكن الدخول بتوكن جلسة بـ POST (مش GET، عشان
 * ماسحات الروابط/الـ prefetch ما تحرقش التوكن). الـ CSP بنطاقات الـ tenant بيتحط من الـ controller.
 * الرسائل للصفحة الأم بتتبعت لـ origin واحد متحقق منه بس (مش '*') وبتقبل رسائل من نفس الـ origin والـ window بس.
 */
final class EmbedFramePage
{
    private const STR = [
        'en' => [
            'connecting' => 'Connecting…', 'connected' => 'Connected as', 'waiting' => 'Waiting for the meeting to start.', 'left' => 'You left the meeting.',
            'expired_session' => 'Your session ended.', 'powered' => 'Powered by',
            'invalid_token' => 'This join link is not valid.', 'token_used' => 'This join link was already used. Ask for a new one.', 'token_expired' => 'This join link expired. Ask for a new one.',
            'origin_not_allowed' => 'This site is not allowed to embed the meeting.', 'origin_mismatch' => 'This link was issued for a different site.',
            'tenant_suspended' => 'This workspace is not available.', 'network' => 'Could not reach the server. Check your connection.', 'error' => 'Something went wrong.',
        ],
        'ar' => [
            'connecting' => 'جارٍ الاتصال…', 'connected' => 'متصل باسم', 'waiting' => 'في انتظار بدء الاجتماع.', 'left' => 'غادرت الاجتماع.',
            'expired_session' => 'انتهت الجلسة.', 'powered' => 'بدعم من',
            'invalid_token' => 'رابط الدخول غير صالح.', 'token_used' => 'رابط الدخول اتستخدم قبل كده. اطلب رابط جديد.', 'token_expired' => 'انتهت صلاحية رابط الدخول. اطلب رابط جديد.',
            'origin_not_allowed' => 'الموقع ده غير مسموح له بتضمين الاجتماع.', 'origin_mismatch' => 'الرابط ده اتصدر لموقع تاني.',
            'tenant_suspended' => 'المساحة دي غير متاحة.', 'network' => 'تعذّر الوصول للخادم. اتأكد من الاتصال.', 'error' => 'حصل خطأ.',
        ],
    ];

    private static function color(?string $c, string $fallback): string
    {
        return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $fallback;
    }

    private static function json(mixed $v): string
    {
        return (string) json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array $brand  TenantBranding::publicPayload أو شكل مماثل (name, logo_url, colors, theme, locale, powered_by)
     * @param array{state:string,token:?string,parent:?string,ns:string,exchange:string,session:string} $view
     */
    public static function render(array $brand, array $view, string $nonce): string
    {
        $lang = ($brand['locale'] ?? 'en') === 'ar' ? 'ar' : 'en';
        $t = self::STR[$lang];
        $theme = in_array($brand['theme'] ?? 'auto', ['light', 'dark', 'auto'], true) ? ($brand['theme'] ?? 'auto') : 'auto';
        $p = self::color($brand['colors']['primary'] ?? null, '#2563eb');
        $a = self::color($brand['colors']['accent'] ?? null, '#7c3aed');
        $name = htmlspecialchars((string) ($brand['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logo = (string) ($brand['logo_url'] ?? '');
        $logoHtml = preg_match('~^https://~i', $logo) ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="" referrerpolicy="no-referrer" height="28">' : '';
        $powered = !empty($brand['powered_by']) ? '<footer>' . htmlspecialchars($t['powered'] . ' ' . $brand['powered_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</footer>' : '';
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';

        $cfg = self::json([
            'state' => $view['state'], 'token' => $view['state'] === 'ok' ? $view['token'] : null, 'parent' => $view['parent'], 'ns' => $view['ns'],
            'exchange' => $view['exchange'], 'session' => $view['session'], 't' => $t,
        ]);

        $css = ":root{--p:{$p};--a:{$a};--bg:#fff;--fg:#0f172a;--mut:#64748b;--card:#f1f5f9;--bad:#b91c1c}"
            . ":root[data-theme=dark]{--bg:#0b1220;--fg:#e5e7eb;--mut:#94a3b8;--card:#162033;--bad:#f87171}"
            . "@media(prefers-color-scheme:dark){:root[data-theme=auto]{--bg:#0b1220;--fg:#e5e7eb;--mut:#94a3b8;--card:#162033;--bad:#f87171}}"
            . "*{box-sizing:border-box}html,body{height:100%;margin:0}body{font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--fg)}"
            . "main{min-height:100%;display:flex;flex-direction:column}header{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:3px solid var(--p)}header strong{font-size:15px}"
            . ".stage{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:24px;text-align:center;background:var(--card)}"
            . "#status{margin:0;font-weight:600}#who,#hint{margin:0;color:var(--mut)}[data-state=error] #status{color:var(--bad)}[data-state=live] #status{color:var(--p)}"
            . "footer{padding:8px 16px;font-size:12px;color:var(--mut);text-align:center}";

        $js = <<<'JS'
(function(){
  var cfg=JSON.parse(document.getElementById('cfg').textContent), T=cfg.t, app=document.getElementById('app');
  var parentOrigin=cfg.parent, framed=window.parent!==window, session=null, timer=null;
  function $(id){return document.getElementById(id);}
  function post(type,data){ if(framed&&parentOrigin){ try{ window.parent.postMessage({source:cfg.ns+'-embed',v:1,type:type,data:data||{}},parentOrigin); }catch(e){} } }
  function show(state,text,hint){ app.setAttribute('data-state',state); $('status').textContent=text; $('hint').textContent=hint||''; }
  function fail(code){
    show('error',T[code]||T.error,'');
    post('error',{code:code});
    if(code==='token_expired'||code==='token_used'){ post('token-expired',{code:code}); }
  }
  function endSession(){
    if(timer){ clearTimeout(timer); timer=null; }
    var s=session; session=null;
    if(s){ try{ fetch(cfg.session,{method:'DELETE',headers:{'Authorization':'Bearer '+s.session.token},credentials:'omit',cache:'no-store',referrerPolicy:'no-referrer'}); }catch(e){} }
    show('left',T.left,''); post('left',{});
  }
  window.addEventListener('message',function(e){
    if(!parentOrigin||e.origin!==parentOrigin||e.source!==window.parent){ return; }
    var m=e.data; if(!m||m.source!==cfg.ns+'-host'){ return; }
    if(m.type==='leave'){ endSession(); } else if(m.type==='ping'){ post('pong',{}); }
  });
  try{ history.replaceState(null,'',location.pathname); }catch(e){}
  if(cfg.state!=='ok'){ fail(cfg.state); return; }
  post('loaded',{});
  fetch(cfg.exchange,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({token:cfg.token,parent:cfg.parent}),credentials:'omit',cache:'no-store',referrerPolicy:'no-referrer'})
    .then(function(r){ return r.json().then(function(j){ return {ok:r.ok,j:j}; }); })
    .then(function(x){
      if(!x.ok){ fail((x.j&&x.j.errors&&x.j.errors.code)||'error'); return; }
      session=x.j.data;
      show('live',T.connected+' '+session.participant.name,T.waiting);
      post('ready',{participant:{external_id:session.participant.external_id,name:session.participant.name,role:session.participant.role},room:session.room,expires_at:session.session.expires_at});
      var ms=new Date(session.session.expires_at).getTime()-Date.now();
      if(ms>0&&ms<2147483000){ timer=setTimeout(function(){ session=null; show('error',T.expired_session,''); post('session-expired',{}); },ms); }
    })
    .catch(function(){ fail('network'); });
})();
JS;

        return '<!doctype html><html lang="' . $lang . '" dir="' . $dir . '" data-theme="' . $theme . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow">'
            . '<title>' . $name . '</title><style nonce="' . $nonce . '">' . $css . '</style></head><body>'
            . '<main id="app" data-state="connecting"><header>' . $logoHtml . '<strong>' . $name . '</strong></header>'
            . '<section class="stage"><p id="status" role="status" aria-live="polite">' . htmlspecialchars($t['connecting'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><p id="who"></p><p id="hint"></p></section>'
            . $powered . '</main>'
            . '<script type="application/json" id="cfg">' . $cfg . '</script>'
            . '<script nonce="' . $nonce . '">' . $js . '</script></body></html>';
    }
}
