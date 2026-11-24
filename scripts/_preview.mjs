import sharp from 'sharp';
const files = ['case-board','case-liquid','case-screen','case-cooling'];
for (const f of files) {
  await sharp('public/images/realizacje/'+f+'.svg').resize(800,600).png().toFile('assets-src/preview-'+f+'.png');
}
console.log('converted');
