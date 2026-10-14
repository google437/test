#!/usr/bin/env python3
"""Build the Detail Digital version of the MSA + SOW generator.

msa-sow-generator.html (Envision) is the single source. This script copies it
and swaps only the branding: logo, colors, header/footer label, and the form
styling. The contract text is left untouched, so both versions always carry
the same MSA and SOW wording.

Run from the repo root:  python3 tools/build-detail-digital.py
"""
import base64
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'msa-sow-generator.html')
OUT = os.path.join(ROOT, 'detail-digital-msa-sow-generator.html')
LOGO = os.path.join(ROOT, 'tools', 'assets', 'detail_digital_logo.jpg')  # 301 x 158, black background

logo_b64 = base64.b64encode(open(LOGO, 'rb').read()).decode()

DD_BRAND = """/*BRAND-START*/
  var BRAND = {
    storageKey: 'emc-contract-draft-dd-v1',
    logo: 'data:image/jpeg;base64,%s',
    logoType: 'JPEG', logoRatio: 301 / 158, logoWidth: 81, logoPlate: true,
    accent: [238, 105, 26], accent2: [245, 138, 69], onAccent: [0, 0, 0], heading: [13, 14, 16],
    gray: [90, 94, 99], light: [246, 246, 247], line: [220, 222, 226],
    topRule: 0, bottomRule: 5, headerLabelColor: [238, 105, 26], upperTitle: true,
    footerLabel: 'DETAIL DIGITAL', author: 'Detail Digital'
  };
  /*BRAND-END*/""" % logo_b64

DD_CSS = """
  /* Detail Digital skin, scoped to this widget's own root so it never
     restyles an Envision widget on the same page. */
  #emc-root-dd.emc {
    --emc-blue: #EE691A;
    --emc-sky: #F58A45;
    --emc-black: #0D0E10;
    --emc-gray: #5A5E63;
    --emc-light: #F6F6F7;
    --emc-border: #DCDEE2;
    font-family: Barlow, 'Helvetica Neue', Helvetica, Arial, sans-serif;
    border-bottom: 5px solid var(--emc-blue);
  }
  #emc-root-dd .emc-head { border-top: 0; }
  #emc-root-dd .emc-head img { width: 130px; background: #000; padding: 4px 6px; border-radius: 6px; }
  #emc-root-dd .emc-head h2 { color: var(--emc-black); text-transform: uppercase; letter-spacing: .02em; }
  #emc-root-dd .emc-card > h3 { color: var(--emc-black); }
  #emc-root-dd .emc-group-title, #emc-root-dd .emc-btn { color: #000; }
  #emc-root-dd .emc-btn:hover { background: #d95b10; }
  #emc-root-dd .emc-btn.emc-ghost { color: #b34a0b; }
</style>"""

src = open(SRC, encoding='utf-8').read()
out, n = re.subn(r'/\*BRAND-START\*/.*?/\*BRAND-END\*/', lambda m: DD_BRAND, src, flags=re.S)
assert n == 1, 'BRAND block not found'
out = out.replace('</style>', DD_CSS, 1)
# Own root id, so the Envision and Detail Digital widgets can share a page.
for a, b in [('id="emc-root"', 'id="emc-root-dd"'),
             ("document.getElementById('emc-root')", "document.getElementById('emc-root-dd')")]:
    assert out.count(a) == 1, a
    out = out.replace(a, b)
out = out.replace('<!-- ENVISION MARKETING: MSA + SOW CONTRACT GENERATOR               -->',
                  '<!-- DETAIL DIGITAL: MSA + SOW CONTRACT GENERATOR                  -->', 1)
out = out.replace('<!-- To change contract wording, edit MSA_SECTIONS / SOW_TEXT in    -->\n<!-- the <script> below.                                            -->',
                  '<!-- GENERATED FILE: edit msa-sow-generator.html, then run         -->\n<!-- python3 tools/build-detail-digital.py                          -->', 1)
open(OUT, 'w', encoding='utf-8').write(out)
print('wrote', OUT, len(out), 'bytes')
