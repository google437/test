#!/usr/bin/env python3
"""Generate duda-onboarding-wizard.html (Duda HTML widget paste version)
from onboarding-wizard.html. Run after editing the standalone page:
    python3 build-duda-onboarding.py
"""
import pathlib, re

SRC = pathlib.Path(__file__).with_name('onboarding-wizard.html')
OUT = pathlib.Path(__file__).with_name('duda-onboarding-wizard.html')
W = '.evo'

def prefix_selector(sel):
    sel = sel.strip()
    if sel in (':root', 'body'): return W
    if sel == 'html': return None
    if sel == '*': return f'{W} *'
    return f'{W} {sel}'

def prefix_css(css):
    out, i = [], 0
    while i < len(css):
        j = css.find('{', i)
        if j < 0: out.append(css[i:]); break
        head = css[i:j].strip()
        if head.startswith('@media'):
            depth, k = 1, j + 1
            while depth:
                depth += {'{': 1, '}': -1}.get(css[k], 0); k += 1
            out.append(f'{head}{{{prefix_css(css[j+1:k-1])}}}\n'); i = k; continue
        k = css.find('}', j)
        sels = [s for s in (prefix_selector(x) for x in head.split(',')) if s]
        if sels: out.append(f"  {','.join(sels)}{{{css[j+1:k].strip()}}}\n")
        i = k + 1
    return ''.join(out)

src = SRC.read_text()
css = re.search(r'<style>(.*?)</style>', src, re.S).group(1)
body = re.search(r'<body>(.*?)<script>', src, re.S).group(1)
js = re.search(r'<script>(.*?)</script>\s*</body>', src, re.S).group(1)

css = prefix_css(re.sub(r'/\*.*?\*/', '', css, flags=re.S))
css = css.replace('min-height:100vh;', '')  # widget, not a full page
sticky_old = f'{W} .side{{position:sticky;top:16px}}'
assert sticky_old in css, 'sticky sidebar rule not found'
css = css.replace(sticky_old, f'{W} .side{{position:sticky;top:var(--evo-sticky-top,100px)}}')

body = body.replace('<a class="brand" id="brand" href="#" aria-label="Envision Marketing"></a>',
                    '<span class="brand" id="brand" aria-label="Envision Marketing"></span>')

def sub(old, new, count=1):
    global js
    assert js.count(old) >= 1, old
    js = js.replace(old, new) if count == 0 else js.replace(old, new, count)

sub("const $ = (s, el) => (el || document).querySelector(s);",
    "const ROOT = document.getElementById('evoOnboard');\nconst $ = (s, el) => (el || ROOT).querySelector(s);")
sub("const $$ = (s, el) => Array.from((el || document).querySelectorAll(s));",
    "const $$ = (s, el) => Array.from((el || ROOT).querySelectorAll(s));\n"
    "function scrollToWidget(){ const y = ROOT.getBoundingClientRect().top + window.scrollY - 110; if (y < window.scrollY) window.scrollTo({top: Math.max(0, y), behavior:'smooth'}); }")
sub("window.scrollTo({top:0, behavior:'smooth'});", "scrollToWidget();", 0)
sub("window.scrollTo({top:0});", "scrollToWidget();", 0)
sub("  brand.href = location.pathname;\n", "")
sub("""  renderAdmin();
}
window.addEventListener('hashchange', boot);""",
"""  if (/[#&]admin\\b/.test(h)) { renderAdmin(); return; }
  $('#modePill').textContent = 'Client onboarding';
  $('#title').textContent = 'Client Onboarding';
  $('#subtitle').textContent = 'Getting your accounts connected so we can get to work.';
  freshApp().innerHTML = '<div class="panel"><div class="sec"><h3>Looking for your onboarding?</h3><p class="lead">Please open the personal link we emailed you. It is set up for the services we are running for your business. Can\\'t find it? Reply to our welcome email and we will resend it.</p></div></div>';
}
window.addEventListener('hashchange', boot);""")

out = f"""<!-- Envision Client Onboarding Wizard :: DIRECT PASTE version for Duda HTML widget -->
<!-- Paste this entire block into the HTML widget's Content editor. -->
<!-- Admin setup: open the live page with #admin at the end of the URL. -->
<!-- Generated from onboarding-wizard.html by build-duda-onboarding.py. Edit the source, then rebuild. -->
<style>
  @import url('https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,600;12..96,700;12..96,800&family=Hanken+Grotesk:wght@400;500;600;700&display=swap');
  /* Reset Duda theme styles inside the widget */
  {W},{W} *{{box-sizing:border-box}}
  {W} button{{margin:0;font:inherit;color:inherit;background:none;border:0;text-align:inherit;line-height:normal;text-transform:none;letter-spacing:normal;box-shadow:none}}
  {W} input,{W} select,{W} textarea{{margin:0;font:inherit;line-height:normal;max-width:100%}}
  {W} h1,{W} h2,{W} h3,{W} p{{margin:0;padding:0;text-transform:none;letter-spacing:normal}}
  {W} h1,{W} h2,{W} h3{{color:var(--ink);font-size:inherit;line-height:1.15}}
  {W} p{{font-size:inherit;line-height:inherit;color:inherit}}
  {W} img{{max-width:none}}
  {W} label{{margin:0}}
  {W} input[type=radio],{W} input[type=checkbox]{{width:16px;height:16px;padding:0;border:0;margin:0;flex:none;box-shadow:none}}
{css}  {W}{{min-height:0;padding:clamp(16px,3vw,40px) 16px;border-radius:20px;line-height:1.4}}
</style>
<div class="evo" id="evoOnboard">{body}</div>
<script>
(function(){{
{js}
}})();
</script>
"""
OUT.write_text(out)
print(f'wrote {OUT.name}: {len(out):,} chars')
