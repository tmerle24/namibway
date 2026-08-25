"""Turn the Outfit SemiBold letters into outlines and assemble Kaia's marks.

Run once; the SVGs it writes are the production artwork. Kept here so the
geometry is reproducible rather than a file nobody can regenerate.
"""
import json, pathlib
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.recordingPen import RecordingPen
from fontTools.pens.transformPen import TransformPen
from fontTools.misc.transform import Transform

BROWN, TAN, SAND = '#3B2418', '#E2AB6C', '#D6C9B5'
f = TTFont('outfit600.woff2'); gs = f.getGlyphSet(); hmtx = f['hmtx']

def path_of(glyph, dx=0):
    pen = SVGPathPen(gs)
    gs[glyph].draw(TransformPen(pen, Transform(1, 0, 0, 1, dx, 0)))
    return pen.getCommands()

def stem_of_i(dx=0):
    """The i without its dot — the diamond takes that job."""
    rec = RecordingPen(); gs['i'].draw(rec)
    contours, cur = [], []
    for op, args in rec.value:
        if op == 'moveTo' and cur:
            contours.append(cur); cur = []
        cur.append((op, args))
    if cur: contours.append(cur)
    stem = min(contours, key=lambda c: max(a[1] for op, args in c for a in args if isinstance(a, tuple)))
    pen = SVGPathPen(gs); tp = TransformPen(pen, Transform(1, 0, 0, 1, dx, 0))
    for op, args in stem: getattr(tp, op)(*args)
    return pen.getCommands()

TRACK = -20                       # letter-spacing -0.02em at 1000 upem
ADV = {g: hmtx[g][0] for g in ('k', 'a', 'i')}
x_k = 0
x_a1 = x_k + ADV['k'] + TRACK
x_i = x_a1 + ADV['a'] + TRACK
x_a2 = x_i + ADV['i'] + TRACK

# The diamond replaces the dot. Its size and the gap below it are the ones
# signed off on the design canvas — 0.19em across, sitting 0.03em above the
# x-height — rather than the round dot's own box, which sits higher because a
# disc reads closer to a stem than a point does.
X_HEIGHT, K_TOP = 483, 723
DOT_CX = 124                      # centre of the i's stem/dot in Outfit
GAP, SIZE = 30, 190
half_v = half_h = SIZE // 2
DOT_CY = X_HEIGHT + GAP + half_v   # 608
def diamond(dx):
    cx = DOT_CX + dx
    return (f'M{cx} {DOT_CY + half_v} L{cx + half_h} {DOT_CY} '
            f'L{cx} {DOT_CY - half_v} L{cx - half_h} {DOT_CY} Z')

letters = ' '.join([path_of('k', x_k), path_of('a', x_a1), stem_of_i(x_i), path_of('a', x_a2)])
dia = diamond(x_i)

MIN_X, MAX_X = 58, x_a2 + 530     # k's left bearing … the last a's right edge
MIN_Y, MAX_Y = -10, K_TOP
W, H = MAX_X - MIN_X, MAX_Y - MIN_Y
FLIP = f'translate({-MIN_X} {MAX_Y}) scale(1 -1)'

HEAD = ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" '
        'role="img" aria-label="{label}">')

out = pathlib.Path('marks'); out.mkdir(exist_ok=True)

def write(name, body):
    (out / name).write_text(body + '\n')

# 1 — wordmark, letters in currentColor, diamond in tan
write('kaia-wordmark.svg',
      HEAD.format(w=W, h=H, label='Kaia') +
      f'<g transform="{FLIP}">'
      f'<path fill="currentColor" d="{letters}"/>'
      f'<path fill="{TAN}" d="{dia}"/></g></svg>')

# 2 — wordmark in a single ink
write('kaia-wordmark-mono.svg',
      HEAD.format(w=W, h=H, label='Kaia') +
      f'<g transform="{FLIP}" fill="currentColor"><path d="{letters}"/><path d="{dia}"/></g></svg>')

# --- the k, for the small slot -------------------------------------------------
K_X0, K_X1, K_TOP_INK = 58, 526, 723
SCALE = 0.066
k_cx, k_cy = (K_X0 + K_X1) / 2, K_TOP_INK / 2
def k_group(fill):
    tx = 50 - k_cx * SCALE
    ty = 50 + k_cy * SCALE
    return (f'<g transform="translate({tx:.3f} {ty:.3f}) scale({SCALE} {-SCALE})">'
            f'<path fill="{fill}" d="{path_of("k")}"/></g>')

write('kaia-avatar-light.svg',
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" role="img" aria-label="Kaia">'
      f'<circle cx="50" cy="50" r="50" fill="{BROWN}"/>{k_group(TAN)}</svg>')

write('kaia-avatar-dark.svg',
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" role="img" aria-label="Kaia">'
      f'<circle cx="50" cy="50" r="50" fill="{SAND}"/>{k_group(BROWN)}</svg>')

write('kaia-icon-tile.svg',
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" role="img" aria-label="Kaia">'
      f'<rect width="100" height="100" rx="24" fill="{BROWN}"/>{k_group(TAN)}</svg>')

write('kaia-k.svg',
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" role="img" aria-label="Kaia">'
      f'{k_group("currentColor")}</svg>')

# hand the pieces to the templating side too
pathlib.Path('marks/pieces.json').write_text(json.dumps({
    'wordmark': {'w': W, 'h': H, 'flip': FLIP, 'letters': letters, 'diamond': dia},
    'k': {'scale': SCALE, 'tx': 50 - k_cx * SCALE, 'ty': 50 + k_cy * SCALE, 'path': path_of('k')},
}, indent=1))
print('wordmark viewBox', W, H, '| files:', sorted(p.name for p in out.iterdir()))
