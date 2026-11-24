// Eksportuje pliki logo (SVG + PNG) do public/brand/.
import sharp from 'sharp';
import { mkdirSync, writeFileSync } from 'node:fs';

mkdirSync('public/brand', { recursive: true });

const mark = (tile, stroke, accent = '#ffd400') => `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" width="36" height="36">
  <rect width="36" height="36" fill="${tile}"/>
  <path d="M25.5 10.5H13.5V17.5H23V26H11.5" fill="none" stroke="${stroke}" stroke-width="4.2" stroke-linejoin="miter" stroke-linecap="butt"/>
  <rect x="24.5" y="24.5" width="6" height="6" fill="${accent}"/>
</svg>`;

const wordmark = (color, subColor) => `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 360 72" width="360" height="72">
  <g transform="translate(0 18)">
    ${mark(color === '#ffffff' ? '#0e100f' : '#0e100f', '#ffffff').replace(/<\/?svg[^>]*>/g, '')}
  </g>
  <text x="56" y="38" font-family="Arial, Helvetica, sans-serif" font-size="27" font-weight="600" letter-spacing="-0.8" fill="${color}">studio-komp</text>
  <text x="57" y="56" font-family="Courier New, monospace" font-size="9" letter-spacing="2.4" fill="${subColor}">SERWIS LAPTOPÓW · WROCŁAW</text>
</svg>`;

// Prosty wariant wektorowy (bez tekstu zależnego od fontów systemowych)
const logoHorizontal = `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 56" width="300" height="56">
  <rect width="56" height="56" fill="#0e100f"/>
  <path d="M40 16H21V27H36V41H18" fill="none" stroke="#ffffff" stroke-width="6.5" stroke-linejoin="miter"/>
  <rect x="38" y="38" width="9" height="9" fill="#ffd400"/>
  <g fill="#0e100f" font-family="Arial, Helvetica, sans-serif">
    <text x="70" y="29" font-size="23" font-weight="600" letter-spacing="-0.7">studio-komp</text>
    <text x="71" y="43" font-size="7.5" letter-spacing="2.1" fill="#5f645e">SERWIS LAPTOPÓW · WROCŁAW</text>
  </g>
</svg>`;

const logoHorizontalLight = logoHorizontal
  .replace('fill="#0e100f"/>\n  <path', 'fill="#f5f6f3"/>\n  <path')
  .replace('stroke="#ffffff"', 'stroke="#0e100f"')
  .replace('<g fill="#0e100f"', '<g fill="#f5f6f3"')
  .replace('fill="#5f645e"', 'fill="#a7aca5"');

writeFileSync('public/brand/studio-komp-mark.svg', mark('#0e100f', '#ffffff'));
writeFileSync('public/brand/studio-komp-mark-light.svg', mark('#f5f6f3', '#0e100f'));
writeFileSync('public/brand/studio-komp-logo.svg', logoHorizontal);
writeFileSync('public/brand/studio-komp-logo-light.svg', logoHorizontalLight);

await sharp(Buffer.from(mark('#0e100f', '#ffffff'))).resize(1024, 1024).png().toFile('public/brand/studio-komp-mark-1024.png');
await sharp(Buffer.from(logoHorizontal)).resize(1200).png().toFile('public/brand/studio-komp-logo-1200.png');
await sharp(Buffer.from(logoHorizontalLight)).resize(1200).png().toFile('public/brand/studio-komp-logo-light-1200.png');

console.log('Logo zapisane w public/brand/');
