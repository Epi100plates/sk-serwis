// Buduje favicon.ico (PNG-in-ICO: 16, 32, 48 px) na podstawie public/favicon.svg.
import sharp from 'sharp';
import { writeFileSync } from 'node:fs';

const sizes = [16, 32, 48];
const pngs = [];
for (const size of sizes) {
  const buf = await sharp('public/favicon.svg').resize(size, size).png().toBuffer();
  pngs.push({ size, buf });
}

const headerSize = 6;
const entrySize = 16;
const offset = headerSize + entrySize * pngs.length;

const header = Buffer.alloc(headerSize);
header.writeUInt16LE(0, 0); // reserved
header.writeUInt16LE(1, 2); // type: icon
header.writeUInt16LE(pngs.length, 4); // count

const entries = [];
let cursor = offset;
for (const { size, buf } of pngs) {
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

const ico = Buffer.concat([header, ...entries, ...pngs.map((p) => p.buf)]);
writeFileSync('public/favicon.ico', ico);
console.log('public/favicon.ico zapisany', ico.length, 'B');
