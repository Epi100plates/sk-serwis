// Generuje techniczne ilustracje realizacji (SVG) w spójnym stylu:
// grafitowe tło, cienkie linie, żółte akcenty. Podmienialne na zdjęcia.
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const OUT = join(process.cwd(), 'public', 'images', 'realizacje');
mkdirSync(OUT, { recursive: true });

const W = 800;
const H = 600;
const BG = '#151816';
const LINE = '#3b423a';
const LINE_SOFT = '#272c27';
const YELLOW = '#ffd400';

function rng(seed) {
  let s = seed >>> 0;
  return () => {
    s = (s * 1664525 + 1013904223) >>> 0;
    return s / 4294967296;
  };
}

const header = (seed) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" fill="none" role="img">
<defs>
  <pattern id="grid-${seed}" width="40" height="40" patternUnits="userSpaceOnUse">
    <path d="M40 0H0V40" fill="none" stroke="${LINE_SOFT}" stroke-width="1"/>
  </pattern>
  <radialGradient id="vig-${seed}" cx="50%" cy="45%" r="70%">
    <stop offset="60%" stop-color="#000" stop-opacity="0"/>
    <stop offset="100%" stop-color="#000" stop-opacity="0.55"/>
  </radialGradient>
</defs>
<rect width="${W}" height="${H}" fill="${BG}"/>
<rect width="${W}" height="${H}" fill="url(#grid-${seed})"/>`;

const footer = (seed) => `<rect width="${W}" height="${H}" fill="url(#vig-${seed})"/>
</svg>`;

const g = (body) => `<g fill="none" stroke-linecap="round" stroke-linejoin="round">${body}</g>`;

function pcbTraces(seed, count = 26, area = { x: 60, y: 60, w: 680, h: 480 }) {
  const r = rng(seed);
  let out = '';
  for (let i = 0; i < count; i++) {
    let x = area.x + Math.round((r() * area.w) / 20) * 20;
    let y = area.y + Math.round((r() * area.h) / 20) * 20;
    let d = `M${x} ${y}`;
    const steps = 2 + Math.floor(r() * 4);
    for (let s = 0; s < steps; s++) {
      const len = 40 + Math.round(r() * 140);
      if (r() > 0.5) {
        x += r() > 0.5 ? len : -len;
      } else {
        y += r() > 0.5 ? len : -len;
      }
      d += `H${x}V${y}`.replace(/H(-?\d+)V(-?\d+)/, (_, a, b) => (r() > 0.5 ? `H${a}V${b}` : `V${b}H${a}`));
    }
    const yellow = r() > 0.86;
    out += `<path d="${d}" stroke="${yellow ? YELLOW : LINE}" stroke-width="${yellow ? 2 : 1.4}" opacity="${yellow ? 0.95 : 0.8}"/>`;
    out += `<circle cx="${x}" cy="${y}" r="${yellow ? 5 : 3.2}" fill="${yellow ? YELLOW : BG}" stroke="${yellow ? YELLOW : LINE}" stroke-width="1.4"/>`;
  }
  return out;
}

function chip(x, y, w, h, label) {
  return `<g>
    <rect x="${x}" y="${y}" width="${w}" height="${h}" fill="#1d211d" stroke="${LINE}" stroke-width="1.6"/>
    ${Array.from({ length: Math.floor(w / 14) }, (_, i) => `<path d="M${x + 8 + i * 14} ${y - 8}v8M${x + 8 + i * 14} ${y + h}v8" stroke="${LINE}" stroke-width="1.6"/>`).join('')}
    <rect x="${x + 14}" y="${y + 14}" width="${w - 28}" height="${h - 28}" fill="none" stroke="${LINE_SOFT}" stroke-width="1"/>
    ${label ? `<rect x="${x + w - 26}" y="${y + 10}" width="10" height="10" fill="${YELLOW}"/>` : ''}
  </g>`;
}

const arts = {
  'case-board': (seed) => `
    ${g(pcbTraces(seed, 30))}
    ${chip(250, 210, 300, 180, true)}
    ${g(`
      <rect x="120" y="120" width="90" height="48" fill="#1d211d" stroke="${LINE}" stroke-width="1.4"/>
      <rect x="600" y="420" width="110" height="40" fill="#1d211d" stroke="${LINE}" stroke-width="1.4"/>
      <path d="M120 470h120v-60h180" stroke="${YELLOW}" stroke-width="1.8"/>
      <circle cx="120" cy="470" r="6" fill="${YELLOW}"/>
      <circle cx="330" cy="410" r="4" fill="${LINE}" stroke="${LINE}"/>
    `)}`,

  'case-liquid': (seed) => {
    const r = rng(seed);
    let branches = '';
    for (let i = 0; i < 14; i++) {
      const x = 120 + r() * 560;
      const y = 120 + r() * 380;
      const a = r() * Math.PI * 2;
      const len = 30 + r() * 90;
      branches += `<path d="M${x} ${y}q${Math.cos(a) * len} ${Math.sin(a) * len} ${Math.cos(a) * len * 1.8} ${Math.sin(a) * len * 1.8}" stroke="${i % 4 === 0 ? YELLOW : LINE}" stroke-width="${i % 4 === 0 ? 1.8 : 1.2}" opacity="0.85"/>`;
    }
    return `${g(pcbTraces(seed, 14, { x: 80, y: 80, w: 640, h: 440 }))}
      <circle cx="400" cy="300" r="150" fill="none" stroke="${LINE}" stroke-width="1.2" opacity="0.6"/>
      <circle cx="400" cy="300" r="210" fill="none" stroke="${LINE_SOFT}" stroke-width="1"/>
      ${g(branches)}
      <path d="M300 260c40-40 160-40 200 0" stroke="${YELLOW}" stroke-width="2"/>
      <circle cx="300" cy="260" r="5" fill="${YELLOW}"/>`;
  },

  'case-screen': () => {
    let pixels = '';
    for (let y = 0; y < 9; y++) {
      for (let x = 0; x < 12; x++) {
        if ((x + y) % 7 === 0) pixels += `<rect x="${140 + x * 42}" y="${110 + y * 42}" width="26" height="26" fill="${YELLOW}" opacity="0.75"/>`;
        else if ((x * y) % 11 === 0) pixels += `<rect x="${140 + x * 42}" y="${110 + y * 42}" width="26" height="26" fill="#2a302a"/>`;
      }
    }
    return `<rect x="120" y="90" width="560" height="420" fill="#10130f" stroke="${LINE}" stroke-width="2"/>
      ${pixels}
      <path d="M120 400l160-120 120 60 140-180 140 90v240H120z" fill="none" stroke="${LINE}" stroke-width="1.2" opacity="0.5"/>
      <path d="M470 90l-60 150 70 60-40 210" fill="none" stroke="${YELLOW}" stroke-width="2.2"/>
      <path d="M520 90l-40 120" fill="none" stroke="#fff" stroke-width="1" opacity="0.55"/>
      <path d="M380 470l90-110" fill="none" stroke="${YELLOW}" stroke-width="1.4" opacity="0.8"/>`;
  },

  'case-cooling': () => {
    let fins = '';
    for (let i = 0; i < 26; i++) {
      fins += `<path d="M${210 + i * 16} 160v280" stroke="${LINE}" stroke-width="2"/>`;
    }
    let air = '';
    for (let i = 0; i < 5; i++) {
      air += `<path d="M${120 + i * 40} 300q60-${60 + i * 18} 120 0" stroke="${i === 2 ? YELLOW : LINE}" stroke-width="${i === 2 ? 2 : 1.2}" opacity="${i === 2 ? 0.95 : 0.7}"/>`;
    }
    return `${g(fins)}
      <path d="M180 140h470v320H180z" fill="none" stroke="${LINE}" stroke-width="2"/>
      ${g(air)}
      <circle cx="640" cy="460" r="44" fill="none" stroke="${LINE}" stroke-width="1.4"/>
      <path d="M640 416v88M596 460h88" stroke="${LINE_SOFT}" stroke-width="1"/>
      <rect x="250" y="470" width="240" height="8" fill="${YELLOW}" opacity="0.8"/>`;
  },

  'case-socket': () => {
    let pins = '';
    for (let i = 0; i < 13; i++) {
      pins += `<circle cx="${240 + i * 26}" cy="290" r="6" fill="none" stroke="${LINE}" stroke-width="1.6"/>`;
      pins += `<path d="M${240 + i * 26} 302v40" stroke="${LINE}" stroke-width="1.2"/>`;
    }
    return `<path d="M200 240h440v90H200z" fill="#1b1f1b" stroke="${LINE}" stroke-width="2"/>
      ${pins}
      <path d="M190 240v-60h70" stroke="${YELLOW}" stroke-width="2.2"/>
      <circle cx="190" cy="180" r="5" fill="${YELLOW}"/>
      <path d="M170 380h480" stroke="${LINE_SOFT}" stroke-width="1"/>
      <path d="M260 380v60h320v-60" stroke="${LINE}" stroke-width="1.4" opacity="0.7"/>`;
  },

  'case-power': () => {
    return `${g(`<path d="M80 300h140l40-70 60 140 50-70h130" stroke="${LINE}" stroke-width="1.6"/>
      <path d="M500 300h100l30-50 34 100 26-50h90" stroke="${YELLOW}" stroke-width="2.2"/>`)}
      <rect x="180" y="420" width="440" height="10" fill="${LINE_SOFT}"/>
      <rect x="180" y="420" width="300" height="10" fill="${YELLOW}" opacity="0.9"/>
      ${chip(320, 150, 160, 90, false)}
      <path d="M400 150v-40M400 240v40" stroke="${LINE}" stroke-width="1.4"/>
      <circle cx="620" cy="140" r="34" fill="none" stroke="${YELLOW}" stroke-width="2"/>
      <path d="M620 120v24l14 10" stroke="${YELLOW}" stroke-width="2"/>`;
  },

  'case-hinge': () => {
    let arcs = '';
    for (let i = 0; i < 7; i++) {
      arcs += `<path d="M${260 + i * 26} 470a${120 + i * 26} ${120 + i * 26} 0 0 1 ${(120 + i * 26) * 2} 0" fill="none" stroke="${i === 3 ? YELLOW : LINE}" stroke-width="${i === 3 ? 2.2 : 1.2}" opacity="0.9"/>`;
    }
    return `<path d="M140 140h220v60H140z" fill="#1b1f1b" stroke="${LINE}" stroke-width="1.8"/>
      <path d="M440 140h220v60H440z" fill="#1b1f1b" stroke="${LINE}" stroke-width="1.8"/>
      ${g(arcs)}
      <circle cx="400" cy="300" r="14" fill="${BG}" stroke="${YELLOW}" stroke-width="2"/>
      <circle cx="400" cy="300" r="4" fill="${YELLOW}"/>
      <path d="M360 140v60M440 140v60" stroke="${LINE_SOFT}" stroke-width="1"/>`;
  },
};

for (const [name, draw] of Object.entries(arts)) {
  const seed = [...name].reduce((acc, ch) => acc + ch.charCodeAt(0), 7);
  const svg = `${header(seed)}${draw(seed)}${footer(seed)}`;
  writeFileSync(join(OUT, `${name}.svg`), svg, 'utf8');
  console.log('written', name);
}
