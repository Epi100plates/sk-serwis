// Generuje favicony i ikony PWA z samego znaku SK (laptop + litery SK), wycietego
// z logo. Zrodlem jest wersja transparentna logo; granica miedzy znakiem a napisem
// jest wyznaczana analiza pikseli (profil kolumn alfa), a nie "na oko".
//
// Wyjscie (nadpisuje istniejace):
//   public/favicon.ico            - 16, 32, 48 px (PNG w kontenerze ICO)
//   public/favicon-16.png
//   public/favicon-32.png
//   public/apple-touch-icon.png   - 180x180
//   public/icon-192.png
//   public/icon-512.png
//   public/favicon.svg            - zagniezdzony PNG 64 px (data URI)
//   public/brand/sk-mark.png      - master 1024x1024 (panel, materialy)
//   public/brand/studio-komp-mark-square.png - 512x512 (zgodnosc z docs)
//
// Uzycie: node scripts/favicon.mjs
import sharp from 'sharp';
import { writeFileSync } from 'node:fs';

const SRC = 'public/brand/studio-komp-logo-transparent.png';
const BG = '#2d343a'; // token --graphite
const ALPHA_MIN = 25; // prog krycia przy wykrywaniu tresci
const GAP_MIN = 12; // minimalna przerwa miedzy znakiem a napisem (px, skala 3x)

const { data, info } = await sharp(SRC).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
const W = info.width;
const H = info.height;
const CH = info.channels;
const alphaAt = (x, y) => data[(y * W + x) * CH + 3];

// 1. Profil kolumn: gdzie w pliku jest tresc.
const cols = new Uint8Array(W);
for (let y = 0; y < H; y++) {
  for (let x = 0; x < W; x++) {
    if (alphaAt(x, y) > ALPHA_MIN) cols[x] = 1;
  }
}
let x0 = 0;
while (x0 < W && !cols[x0]) x0++;
let x1 = W - 1;
while (x1 > 0 && !cols[x1]) x1--;

// 2. Granica: pierwsza przerwa w tresci o szerokosci >= GAP_MIN (za nia zaczyna
//    sie napis STUDIO-KOMP / Serwis laptopow).
let gapStart = -1;
let split = -1;
let splitEnd = -1;
for (let x = x0; x <= x1; x++) {
  if (!cols[x]) {
    if (gapStart < 0) gapStart = x;
  } else if (gapStart >= 0) {
    if (x - gapStart >= GAP_MIN) {
      split = gapStart;
      splitEnd = x - 1;
      break;
    }
    gapStart = -1;
  }
}
if (split < 0) throw new Error('Nie znaleziono granicy miedzy znakiem a napisem');

// 3. BBox znaku (tylko piksele po lewej stronie granicy).
let mx0 = W;
let mx1 = -1;
let my0 = H;
let my1 = -1;
for (let y = 0; y < H; y++) {
  for (let x = x0; x < split; x++) {
    if (alphaAt(x, y) > ALPHA_MIN) {
      if (x < mx0) mx0 = x;
      if (x > mx1) mx1 = x;
      if (y < my0) my0 = y;
      if (y > my1) my1 = y;
    }
  }
}
const mw = mx1 - mx0 + 1;
const mh = my1 - my0 + 1;
const ratio = mw / mh;
console.log(`Zrodlo: ${SRC} ${W}x${H}`);
console.log(`Znak: bbox x ${mx0}..${mx1}, y ${my0}..${my1}, ${mw}x${mh} px, proporcje ${ratio.toFixed(3)}`);
console.log(`Granica znak/napis: przerwa x ${split}..${splitEnd}, napis od x ${splitEnd + 1}`);

const mark = await sharp(SRC).extract({ left: mx0, top: my0, width: mw, height: mh }).png().toBuffer();

// Zwiekszenie krycia cienkich kresek (tylko male rozmiary) - alfa, bez zmiany ksztaltu.
function boostAlpha(rgba, strength) {
  const out = Buffer.from(rgba);
  for (let i = 3; i < out.length; i += 4) {
    const a = Math.pow(out[i] / 255, 0.55) * (1 + strength);
    out[i] = Math.round(Math.min(1, a) * 255);
  }
  return out;
}

// Dylatacja alfy (filtr maksimum) - pogrubia kreski o r px w skali supersamplingu.
// Uzywane tylko dla 16 px, gdzie oryginalna kreska ma ok. 0.3 px.
function dilateAlpha(rgba, w, h, r) {
  if (r <= 0) return rgba;
  const out = Buffer.from(rgba);
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      let m = 0;
      for (let dy = -r; dy <= r; dy++) {
        const yy = Math.min(h - 1, Math.max(0, y + dy));
        for (let dx = -r; dx <= r; dx++) {
          const xx = Math.min(w - 1, Math.max(0, x + dx));
          const v = rgba[(yy * w + xx) * 4 + 3];
          if (v > m) m = v;
        }
      }
      out[(y * w + x) * 4 + 3] = m;
    }
  }
  return out;
}

// Kompozycja: znak na kwadracie w kolorze grafitu, wysrodkowany, z marginesem
// bezpieczenstwa (px z kazdej strony, ten sam z lewej i prawej).
async function renderIcon(size, marginPx, { boost = 0, supersample = 1, sharpenSigma = 0, dilate = 0 } = {}) {
  const cw = size - marginPx * 2;
  const chh = Math.max(1, Math.round(cw / ratio));
  const bw = cw * supersample;
  const bh = chh * supersample;
  let buf = await sharp(mark).resize(bw, bh, { fit: 'fill', kernel: 'lanczos3' }).png().toBuffer();
  if (dilate > 0) {
    const raw = await sharp(buf).ensureAlpha().raw().toBuffer();
    buf = await sharp(dilateAlpha(raw, bw, bh, dilate), { raw: { width: bw, height: bh, channels: 4 } })
      .png()
      .toBuffer();
  }
  if (sharpenSigma > 0) buf = await sharp(buf).sharpen({ sigma: sharpenSigma }).png().toBuffer();
  let small =
    supersample > 1
      ? await sharp(buf).resize(cw, chh, { fit: 'fill', kernel: 'mitchell' }).png().toBuffer()
      : buf;
  if (boost > 0) {
    const raw = await sharp(small).ensureAlpha().raw().toBuffer();
    small = await sharp(boostAlpha(raw, boost), { raw: { width: cw, height: chh, channels: 4 } })
      .png()
      .toBuffer();
  }
  return sharp({ create: { width: size, height: size, channels: 4, background: BG } })
    .composite([{ input: small, left: Math.round((size - cw) / 2), top: Math.round((size - chh) / 2) }])
    .png()
    .toBuffer();
}

// 4. Ikony. Male rozmiary: mniejszy margines, podbita alfa i (dla 16 px) delikatna
//    dylatacja, zeby kreski przetrwaly downsizing. Duze: margines ok. 6%.
const plans = [
  { size: 16, margin: 1, boost: 0.45, dilate: 2, supersample: 4, sharpenSigma: 1.2, file: 'public/favicon-16.png' },
  { size: 32, margin: 1, boost: 0.35, supersample: 4, sharpenSigma: 1.2, file: 'public/favicon-32.png' },
  { size: 48, margin: 2, boost: 0.15, supersample: 4, sharpenSigma: 1.0 },
  { size: 64, margin: 2, boost: 0.1, supersample: 4, sharpenSigma: 1.0 },
  { size: 180, margin: 11, supersample: 2, file: 'public/apple-touch-icon.png' },
  { size: 192, margin: 12, file: 'public/icon-192.png' },
  { size: 512, margin: 31, file: 'public/icon-512.png' },
  { size: 1024, margin: 61, sharpenSigma: 0.8, file: 'public/brand/sk-mark.png' },
];

const pngs = new Map();
for (const plan of plans) {
  const buf = await renderIcon(plan.size, plan.margin, plan);
  pngs.set(plan.size, buf);
  if (plan.file) {
    writeFileSync(plan.file, buf);
    console.log(`${plan.file} ${plan.size}x${plan.size} ${buf.length} B`);
  }
}

// Znak kwadratowy 512 (kompatybilnosc z docs/logo-i-design.md)
writeFileSync('public/brand/studio-komp-mark-square.png', pngs.get(512));
console.log(`public/brand/studio-komp-mark-square.png 512x512 ${pngs.get(512).length} B`);

// 5. favicon.ico: PNG w kontenerze ICO (16, 32, 48).
{
  const sizes = [16, 32, 48];
  const images = sizes.map((size) => ({ size, buf: pngs.get(size) }));
  const headerSize = 6;
  const entrySize = 16;
  const offset = headerSize + entrySize * images.length;
  const header = Buffer.alloc(headerSize);
  header.writeUInt16LE(0, 0); // reserved
  header.writeUInt16LE(1, 2); // type: icon
  header.writeUInt16LE(images.length, 4); // count
  const entries = [];
  let cursor = offset;
  for (const { size, buf } of images) {
    const entry = Buffer.alloc(entrySize);
    entry.writeUInt8(size >= 256 ? 0 : size, 0);
    entry.writeUInt8(size >= 256 ? 0 : size, 1);
    entry.writeUInt8(0, 2); // palette
    entry.writeUInt8(0, 3); // reserved
    entry.writeUInt16LE(1, 4); // planes
    entry.writeUInt16LE(32, 6); // bpp
    entry.writeUInt32LE(buf.length, 8);
    entry.writeUInt32LE(cursor, 12);
    entries.push(entry);
    cursor += buf.length;
  }
  const ico = Buffer.concat([header, ...entries, ...images.map((p) => p.buf)]);
  writeFileSync('public/favicon.ico', ico);
  console.log(`public/favicon.ico 16/32/48 ${ico.length} B`);
}

// 6. favicon.svg - obrazek 64 px w data URI (ksztalt znaku wazniejszy niz wektor).
{
  const b64 = pngs.get(64).toString('base64');
  const svg =
    '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64" ' +
    'role="img" aria-label="Studio-Komp">\n' +
    `  <image width="64" height="64" href="data:image/png;base64,${b64}"/>\n` +
    '</svg>\n';
  writeFileSync('public/favicon.svg', svg, 'utf8');
  console.log(`public/favicon.svg 64x64 ${Buffer.byteLength(svg, 'utf8')} B`);
}

// 7. Podsumowanie
console.log('\nRozmiary plikow:');
for (const [size, buf] of [...pngs.entries()].sort((a, b) => a[0] - b[0])) {
  const plan = plans.find((p) => p.size === size);
  if (plan.file) console.log(`  ${size.toString().padStart(4)} px  ${buf.length} B  ${plan.file}`);
}
